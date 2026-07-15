<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateCommerceCustomerDeliveryTables implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('commerce_customer_address_books')) {
            $schema->createTable('commerce_customer_address_books', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('tenant_uuid', 12)->default('');
                $table->string('user_uuid', 12);
                $table->integer('revision')->default(0);
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
                $table->timestamp('updated_at')->nullable();

                $table->unique('uuid');
                $table->unique(['tenant_uuid', 'user_uuid']);
            });
        }

        if (!$schema->hasTable('commerce_customer_addresses')) {
            $schema->createTable('commerce_customer_addresses', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('tenant_uuid', 12)->default('');
                $table->string('user_uuid', 12);
                $table->string('label', 64)->nullable();
                $table->json('address');
                $table->boolean('is_default_shipping')->default(false);
                $table->boolean('is_default_billing')->default(false);
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
                $table->timestamp('updated_at')->nullable();

                $table->unique('uuid');
                $table->index(['tenant_uuid', 'user_uuid']);
            });
        }

        if (!$schema->hasTable('commerce_downloads')) {
            $schema->createTable('commerce_downloads', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('tenant_uuid', 12)->default('');
                $table->string('variant_uuid', 12);
                $table->string('blob_uuid', 12);
                $table->string('name', 255);
                $table->integer('download_limit')->nullable();
                $table->integer('expiry_days')->nullable();
                $table->integer('position')->default(0);
                $table->string('status', 16)->default('active');
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
                $table->timestamp('updated_at')->nullable();

                $table->unique('uuid');
                $table->unique(['variant_uuid', 'blob_uuid']);
                $table->index('tenant_uuid');
                $table->index('variant_uuid');
                // CommerceDownloadBlobPolicy::definitionReferences() runs a
                // `WHERE blob_uuid = ?` scan on EVERY blob request app-wide (T6
                // review finding). The (variant_uuid, blob_uuid) unique above has
                // blob_uuid as a non-leading column, so it cannot serve that
                // lookup -- a dedicated single-column index is required.
                $table->index('blob_uuid');
            });
        }

        if (!$schema->hasTable('commerce_download_grants')) {
            $schema->createTable('commerce_download_grants', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('tenant_uuid', 12)->default('');
                $table->string('order_uuid', 12);
                $table->string('download_uuid', 12);
                $table->string('blob_uuid', 12);
                $table->string('name', 255);
                $table->string('token_hash', 64);
                $table->bigInteger('remaining')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->bigInteger('mint_count')->default(0);
                $table->timestamp('last_minted_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamp('refund_access_override_at')->nullable();
                $table->string('refund_access_override_by', 12)->nullable();
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');

                $table->unique('uuid');
                // Named uniques: Task 4 must distinguish an idempotency-key collision
                // (order_uuid, download_uuid) from a token_hash collision. The names given
                // here become real constraint/key names on MySQL and PostgreSQL (visible in
                // driver error messages), but SQLite silently discards custom names for
                // UNIQUE constraints declared inline inside CREATE TABLE -- verified via
                // PRAGMA index_list, which reports SQLite's own `sqlite_autoindex_*` names
                // instead (see CustomerDeliveryShapeTest). Task 4's collision-type logic must
                // therefore stay portable: probe which key exists after the violation rather
                // than parsing a constraint name out of the driver exception.
                $table->unique(['order_uuid', 'download_uuid'], 'uniq_grant_order_download');
                $table->unique('token_hash', 'uniq_grant_token_hash');
                $table->index('tenant_uuid');
                $table->index('order_uuid');
                // Same rationale as commerce_downloads.blob_uuid above:
                // CommerceDownloadBlobPolicy::grantReferences() runs a
                // `WHERE blob_uuid = ?` scan on every blob request, and no
                // existing unique here leads with blob_uuid.
                $table->index('blob_uuid');
            });
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('commerce_download_grants');
        $schema->dropTableIfExists('commerce_downloads');
        $schema->dropTableIfExists('commerce_customer_addresses');
        $schema->dropTableIfExists('commerce_customer_address_books');
    }

    public function getDescription(): string
    {
        return 'Creates commerce customer address book, address, download definition, and '
            . 'download grant tables.';
    }
}
