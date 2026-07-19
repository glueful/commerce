<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Marketplace MV1 foundation (design spec §3): the per-workspace activation
 * settings row, seller identity, and seller memberships. Commerce is a
 * PUBLISHED extension (1.1.0) -- this is a REAL new migration, never a fold
 * into an already-shipped one.
 *
 * MV5a (design spec §3.1) folds the rolling-reserve policy columns directly
 * into these still-unreleased tables rather than adding a follow-up ALTER:
 * `commerce_marketplace_settings.reserve_bps`/`reserve_days` is the
 * workspace-default policy (NOT NULL, default 0 -- `0` on either means the
 * feature is off for that workspace), and `commerce_sellers.reserve_bps`/
 * `reserve_days` is the nullable per-seller override (null inherits the
 * workspace default; an explicit `0` disables it for that seller without
 * inheriting).
 */
final class CreateMarketplaceSellerTables implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('commerce_marketplace_settings')) {
            $schema->createTable('commerce_marketplace_settings', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('tenant_uuid', 12)->default('');
                $table->string('status', 16);
                $table->string('default_seller_uuid', 12)->nullable();
                // Marketplace commission policy (MV3, design spec §2.2/§3.1): the
                // workspace-wide fallback level in the product -> seller ->
                // workspace-settings -> config precedence chain. All three nullable --
                // null means "inherit config" (config/commerce.php marketplace.commission,
                // the total, never-all-null tail).
                $table->string('commission_kind', 16)->nullable();
                $table->integer('commission_bps')->nullable();
                $table->bigInteger('commission_fixed')->nullable();
                $table->string('activated_by', 12)->nullable();
                $table->timestamp('activated_at')->nullable();
                // Rolling-reserve workspace policy (MV5a, design spec §2.1/§3.1): the
                // fallback level in the per-seller-override -> workspace-default
                // resolution chain. NOT NULL with a `0` default -- `0` bps OR `0`
                // days means no rolling reserve, so an unconfigured workspace is
                // inert by construction.
                $table->integer('reserve_bps')->default(0);
                $table->integer('reserve_days')->default(0);
                $table->integer('revision')->default(0);
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
                $table->timestamp('updated_at')->nullable();

                $table->unique('uuid');
                $table->unique('tenant_uuid');
            });
        }

        if (!$schema->hasTable('commerce_sellers')) {
            $schema->createTable('commerce_sellers', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('tenant_uuid', 12)->default('');
                $table->string('slug', 64);
                $table->string('name', 160);
                $table->json('metadata')->nullable();
                // Marketplace commission policy (MV3, design spec §2.2/§3.1): the
                // per-seller override level. All three nullable -- null means "inherit
                // workspace-settings" in the precedence chain above.
                $table->string('commission_kind', 16)->nullable();
                $table->integer('commission_bps')->nullable();
                $table->bigInteger('commission_fixed')->nullable();
                // Rolling-reserve per-seller override (MV5a, design spec §2.1/§3.1):
                // null inherits the workspace default above; an explicit `0` on
                // either column disables the reserve for this seller WITHOUT
                // inheriting (mirrors the commission override nullability idiom).
                $table->integer('reserve_bps')->nullable();
                $table->integer('reserve_days')->nullable();
                $table->string('status', 16)->default('active');
                $table->integer('revision')->default(0);
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
                $table->timestamp('updated_at')->nullable();

                $table->unique('uuid');
                $table->unique(['tenant_uuid', 'slug']);
                $table->index('tenant_uuid');
            });
        }

        if (!$schema->hasTable('commerce_seller_memberships')) {
            $schema->createTable('commerce_seller_memberships', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('tenant_uuid', 12)->default('');
                $table->string('seller_uuid', 12);
                $table->string('user_uuid', 12);
                $table->string('role', 32);
                $table->string('status', 16)->default('active');
                $table->string('created_by', 12)->nullable();
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
                $table->timestamp('updated_at')->nullable();

                $table->unique('uuid');
                $table->unique(['seller_uuid', 'user_uuid']);
                $table->index(['tenant_uuid', 'user_uuid']);
                $table->index(['seller_uuid', 'status', 'role']);
            });
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('commerce_seller_memberships');
        $schema->dropTableIfExists('commerce_sellers');
        $schema->dropTableIfExists('commerce_marketplace_settings');
    }

    public function getDescription(): string
    {
        return 'Creates marketplace settings, sellers, and seller membership tables (MV1 foundation).';
    }
}
