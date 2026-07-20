<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Marketplace MV4 provider-payout destination accounts (design spec §3.2): one
 * row per (tenant, seller, provider) opaque payout destination. `readiness_state`
 * is synced from `PayoutCollector::inspectDestination()` -- never
 * operator-asserted -- and gates the reserve step. No row for a
 * (seller, provider) pair means "unconfigured" (`pending` is the initial state
 * once a row exists; the absence of a row is a distinct, stricter case handled
 * service-side). Commerce stores no raw bank/KYC/PII data here, only the
 * provider-opaque `account_ref`. A genuinely new table -- there is nothing
 * existing to fold into.
 */
final class CreateSellerPayoutAccountsTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('commerce_seller_payout_accounts')) {
            $schema->createTable('commerce_seller_payout_accounts', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('tenant_uuid', 12)->default('');
                $table->string('seller_uuid', 12);
                $table->string('provider', 32);
                $table->string('account_ref', 191);
                $table->string('readiness_state', 16)->default('pending');
                $table->timestamp('last_synced_at')->nullable();
                $table->string('failure_code', 64)->nullable();
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
                $table->timestamp('updated_at')->nullable();

                // Explicit short names: the auto-generated name for the
                // (tenant_uuid, seller_uuid, provider) unique alone is 71 bytes,
                // over PostgreSQL's 63-byte NAMEDATALEN limit (see
                // CreateSellerOrderTables's identical note).
                $table->unique(
                    ['tenant_uuid', 'seller_uuid', 'provider'],
                    'commerce_payout_accounts_tenant_seller_provider_unique'
                );
                $table->unique(['tenant_uuid', 'uuid'], 'commerce_payout_accounts_tenant_uuid_unique');
                $table->index(['tenant_uuid', 'seller_uuid'], 'commerce_payout_accounts_tenant_seller_index');
            });
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('commerce_seller_payout_accounts');
    }

    public function getDescription(): string
    {
        return 'Creates the commerce_seller_payout_accounts table for MV4 provider payout destinations.';
    }
}
