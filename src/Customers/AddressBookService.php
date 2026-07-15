<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Customers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Helpers\Utils;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Validation\ValidationException;

/**
 * Storefront address book orchestration (design spec §2/§7). Every mutation
 * (create/update/delete, including a default-shipping/billing swap) shares
 * one discipline:
 *
 * 1. {@see AddressBookRepository::ensureBook()} OUTSIDE any transaction --
 *    idempotent, insert-or-ignore against the unique (tenant, user) index.
 * 2. Open a transaction, claim the parent via
 *    {@see AddressBookRepository::claimBook()} (affected-row-checked), THEN
 *    re-read/validate/mutate. The claimed row lock is what actually
 *    serializes two concurrent first-address (or default-swap) requests for
 *    the same account onto that one parent row, not the revision counter by
 *    itself.
 *
 * Default swap is clear-then-set in the SAME transaction as the create/
 * update that requested it -- never a separate follow-up write.
 */
final class AddressBookService
{
    public function __construct(
        private AddressBookRepository $repository,
        private CurrentTenantResolver $tenants,
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function list(ApplicationContext $context, string $userUuid): array
    {
        $tenant = $this->tenants->tenantUuid($context);

        return array_map(
            fn (array $row): array => $this->projection($row),
            $this->repository->forUser($context, $tenant, $userUuid)
        );
    }

    /**
     * @param array<string,mixed> $input {label?, address, is_default_shipping?, is_default_billing?}
     * @return array<string,mixed>
     */
    public function create(ApplicationContext $context, string $userUuid, array $input): array
    {
        $tenant = $this->tenants->tenantUuid($context);
        $this->repository->ensureBook($context, $tenant, $userUuid);

        return db($context)->transaction(function () use ($context, $tenant, $userUuid, $input): array {
            if (!$this->repository->claimBook($context, $tenant, $userUuid)) {
                throw new NotFoundException('Resource not found.');
            }

            $address = $this->assertAddress($input['address'] ?? null);
            $label = $this->normalizeLabel($input['label'] ?? null);
            $defaultShipping = (bool) ($input['is_default_shipping'] ?? false);
            $defaultBilling = (bool) ($input['is_default_billing'] ?? false);

            if ($defaultShipping) {
                $this->repository->clearDefaultShipping($context, $tenant, $userUuid);
            }
            if ($defaultBilling) {
                $this->repository->clearDefaultBilling($context, $tenant, $userUuid);
            }

            $uuid = Utils::generateNanoID();
            $this->repository->insert($context, [
                'uuid' => $uuid,
                'tenant_uuid' => $tenant,
                'user_uuid' => $userUuid,
                'label' => $label,
                'address' => $address,
                'is_default_shipping' => $defaultShipping,
                'is_default_billing' => $defaultBilling,
            ]);

            $row = $this->repository->findByUuid($context, $tenant, $userUuid, $uuid);
            if ($row === null) {
                throw new \RuntimeException('Created address could not be reloaded.');
            }

            return $this->projection($row);
        });
    }

    /**
     * @param array<string,mixed> $changes only present keys are applied -- absent means "leave unchanged"
     * @return array<string,mixed>
     */
    public function update(ApplicationContext $context, string $userUuid, string $uuid, array $changes): array
    {
        $tenant = $this->tenants->tenantUuid($context);
        $this->repository->ensureBook($context, $tenant, $userUuid);

        return db($context)->transaction(function () use ($context, $tenant, $userUuid, $uuid, $changes): array {
            if (!$this->repository->claimBook($context, $tenant, $userUuid)) {
                throw new NotFoundException('Resource not found.');
            }

            if ($this->repository->findByUuid($context, $tenant, $userUuid, $uuid) === null) {
                throw new NotFoundException('Resource not found.');
            }

            $set = [];
            if (array_key_exists('label', $changes)) {
                $set['label'] = $this->normalizeLabel($changes['label']);
            }
            if (array_key_exists('address', $changes)) {
                $set['address'] = $this->assertAddress($changes['address']);
            }
            if (array_key_exists('is_default_shipping', $changes)) {
                $flag = (bool) $changes['is_default_shipping'];
                if ($flag) {
                    $this->repository->clearDefaultShipping($context, $tenant, $userUuid, $uuid);
                }
                $set['is_default_shipping'] = $flag;
            }
            if (array_key_exists('is_default_billing', $changes)) {
                $flag = (bool) $changes['is_default_billing'];
                if ($flag) {
                    $this->repository->clearDefaultBilling($context, $tenant, $userUuid, $uuid);
                }
                $set['is_default_billing'] = $flag;
            }

            if ($set !== []) {
                $this->repository->update($context, $tenant, $userUuid, $uuid, $set);
            }

            $row = $this->repository->findByUuid($context, $tenant, $userUuid, $uuid);
            if ($row === null) {
                throw new \RuntimeException('Updated address could not be reloaded.');
            }

            return $this->projection($row);
        });
    }

    public function delete(ApplicationContext $context, string $userUuid, string $uuid): void
    {
        $tenant = $this->tenants->tenantUuid($context);
        $this->repository->ensureBook($context, $tenant, $userUuid);

        db($context)->transaction(function () use ($context, $tenant, $userUuid, $uuid): void {
            if (!$this->repository->claimBook($context, $tenant, $userUuid)) {
                throw new NotFoundException('Resource not found.');
            }

            if ($this->repository->findByUuid($context, $tenant, $userUuid, $uuid) === null) {
                throw new NotFoundException('Resource not found.');
            }

            $this->repository->delete($context, $tenant, $userUuid, $uuid);
        });
    }

    /**
     * Same loose shape checkout already accepts, but never empty and never a
     * JSON array/list -- must be an object.
     *
     * @return array<string,mixed>
     */
    private function assertAddress(mixed $address): array
    {
        if (!is_array($address) || $address === [] || array_is_list($address)) {
            throw ValidationException::forField('address', 'address must be a non-empty JSON object.');
        }

        return $address;
    }

    private function normalizeLabel(mixed $label): ?string
    {
        if ($label === null) {
            return null;
        }

        $label = trim((string) $label);

        return $label === '' ? null : $label;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function projection(array $row): array
    {
        return [
            'uuid' => (string) $row['uuid'],
            'label' => $row['label'],
            'address' => $row['address'],
            'is_default_shipping' => (bool) $row['is_default_shipping'],
            'is_default_billing' => (bool) $row['is_default_billing'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }
}
