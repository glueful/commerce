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
                $table->string('activated_by', 12)->nullable();
                $table->timestamp('activated_at')->nullable();
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
