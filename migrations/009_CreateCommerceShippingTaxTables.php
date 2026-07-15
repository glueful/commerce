<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateCommerceShippingTaxTables implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('commerce_shipping_zones')) {
            $schema->createTable('commerce_shipping_zones', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('tenant_uuid', 12)->default('');
                $table->string('name', 255);
                $table->integer('position')->default(0);
                $table->integer('revision')->default(0);
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
                $table->timestamp('updated_at')->nullable();

                $table->unique('uuid');
                $table->unique(['tenant_uuid', 'name']);
                $table->index('tenant_uuid');
            });
        }

        if (!$schema->hasTable('commerce_shipping_zone_locations')) {
            $schema->createTable('commerce_shipping_zone_locations', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('zone_uuid', 12);
                $table->string('kind', 16);
                $table->string('value', 64);

                $table->unique(['zone_uuid', 'kind', 'value']);
                $table->index('zone_uuid');
            });
        }

        if (!$schema->hasTable('commerce_shipping_methods')) {
            $schema->createTable('commerce_shipping_methods', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('zone_uuid', 12);
                $table->string('kind', 24);
                $table->string('label', 255);
                $table->json('config');
                $table->integer('position')->default(0);
                $table->boolean('enabled')->default(true);
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
                $table->timestamp('updated_at')->nullable();

                $table->unique('uuid');
                $table->index('zone_uuid');
            });
        }

        if (!$schema->hasTable('commerce_shipping_classes')) {
            $schema->createTable('commerce_shipping_classes', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('tenant_uuid', 12)->default('');
                $table->string('slug', 64);
                $table->string('name', 255);
                $table->integer('revision')->default(0);
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
                $table->timestamp('updated_at')->nullable();

                $table->unique('uuid');
                $table->unique(['tenant_uuid', 'slug']);
                $table->index('tenant_uuid');
            });
        }

        if (!$schema->hasTable('commerce_tax_rates')) {
            $schema->createTable('commerce_tax_rates', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('tenant_uuid', 12)->default('');
                $table->string('country', 2);
                $table->string('state', 64)->nullable();
                $table->string('postcode_pattern', 32)->nullable();
                $table->integer('rate_bps');
                $table->string('label', 255);
                $table->integer('priority')->default(0);
                $table->boolean('shipping_taxable')->default(false);
                $table->string('class', 16)->default('standard');
                $table->integer('revision')->default(0);
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
                $table->timestamp('updated_at')->nullable();

                $table->unique('uuid');
                $table->index('tenant_uuid');
                $table->index(['tenant_uuid', 'country']);
            });
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('commerce_tax_rates');
        $schema->dropTableIfExists('commerce_shipping_classes');
        $schema->dropTableIfExists('commerce_shipping_methods');
        $schema->dropTableIfExists('commerce_shipping_zone_locations');
        $schema->dropTableIfExists('commerce_shipping_zones');
    }

    public function getDescription(): string
    {
        return 'Creates commerce shipping zones, zone locations, shipping methods, shipping '
            . 'classes, and tax rate tables.';
    }
}
