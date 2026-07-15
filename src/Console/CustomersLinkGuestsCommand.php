<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Console;

use Glueful\Auth\Contracts\UserProviderInterface;
use Glueful\Console\BaseCommand;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Support\EmailNormalizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Operator guest-order -> account linking (design spec §7, "Guest linking").
 *
 * For every order with `user_uuid IS NULL`, resolves the order's `email` via
 * the SOFT `UserProviderInterface::findByLogin()` (identifier-agnostic — it
 * may resolve by username, email, or anything else the provider supports).
 * `user_uuid` is stamped ONLY when the resolved identity has a non-null email
 * whose {@see EmailNormalizer::normalize()} form exactly matches the order's
 * normalized email — a username-only resolution (or any resolved identity
 * whose email doesn't match) is REJECTED and reported, never stamped. This
 * matters because `findByLogin()` is identifier-agnostic: passing an email as
 * the lookup string does not guarantee the provider resolved it BY email, and
 * the provider contract exposes no verified-email flag (design spec §7,
 * "Documented risk").
 *
 * `--dry-run` reports the exact same plan a real run would perform without
 * writing anything. `--email=` and `--tenant=` narrow the scan.
 */
#[AsCommand(
    name: 'commerce:customers:link-guests',
    description: 'Link guest commerce orders to a resolvable user account by exact-match email'
)]
final class CustomersLinkGuestsCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Limit to a single tenant uuid');
        $this->addOption('email', null, InputOption::VALUE_REQUIRED, 'Limit to guest orders with this exact email');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report the plan without stamping user_uuid');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $context = $this->getContext();

        $provider = null;
        if ($this->container->has(UserProviderInterface::class)) {
            $resolved = $this->container->get(UserProviderInterface::class);
            $provider = $resolved instanceof UserProviderInterface ? $resolved : null;
        }
        if ($provider === null) {
            $this->info('No UserProviderInterface bound; nothing to link.');

            return self::SUCCESS;
        }

        $tenantOption = $input->getOption('tenant');
        $emailOption = $input->getOption('email');
        $dryRun = (bool) $input->getOption('dry-run');

        $query = db($context)->table('commerce_orders')
            ->whereNull('user_uuid')
            ->orderBy('id', 'ASC');
        if (is_string($tenantOption) && trim($tenantOption) !== '') {
            $query->where('tenant_uuid', '=', trim($tenantOption));
        }
        if (is_string($emailOption) && trim($emailOption) !== '') {
            $query->where('email', '=', trim($emailOption));
        }
        $orders = $query->get();

        if ($orders === []) {
            $this->info('No guest orders found.');

            return self::SUCCESS;
        }

        // Zero-dependency repository (only uses db($context) internally) --
        // instantiated directly rather than via app(), matching the pattern
        // OrderRepository is used with elsewhere across this test suite; no
        // container registration is required for this command to work.
        $orderRepo = new OrderRepository();
        $rows = [];
        $linked = 0;
        $rejected = 0;
        $unresolved = 0;

        foreach ($orders as $order) {
            $orderEmail = (string) $order['email'];
            $normalizedOrderEmail = EmailNormalizer::normalize($orderEmail);
            $identity = $provider->findByLogin($orderEmail);

            if ($identity === null) {
                $unresolved++;
                $rows[] = [(string) $order['order_number'], $orderEmail, 'unresolved'];
                continue;
            }

            $identityEmail = $identity->email();
            if ($identityEmail === null || EmailNormalizer::normalize($identityEmail) !== $normalizedOrderEmail) {
                $rejected++;
                $rows[] = [
                    (string) $order['order_number'],
                    $orderEmail,
                    'rejected (resolved identity email does not match)',
                ];
                continue;
            }

            $linked++;
            $rows[] = [
                (string) $order['order_number'],
                $orderEmail,
                ($dryRun ? 'would link -> ' : 'linked -> ') . $identity->uuid(),
            ];

            if (!$dryRun) {
                $orderRepo->linkGuestToUser(
                    $context,
                    (string) $order['tenant_uuid'],
                    (string) $order['uuid'],
                    $identity->uuid()
                );
            }
        }

        $this->table(['Order', 'Email', 'Result'], $rows);

        $mode = $dryRun ? 'Dry run' : 'Link';
        $this->info(
            "{$mode} complete: {$linked} linked, {$rejected} rejected, {$unresolved} unresolved, "
            . count($orders) . ' guest order(s) scanned.'
        );

        return self::SUCCESS;
    }
}
