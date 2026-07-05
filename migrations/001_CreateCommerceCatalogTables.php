<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateCommerceCatalogTables implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('commerce_products')) {
            $schema->createTable('commerce_products', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('tenant_uuid', 12)->default('');
                $table->string('slug', 191);
                $table->string('name', 255);
                $table->text('description')->nullable();
                $table->string('type', 16)->default('physical');
                $table->string('status', 16)->default('draft');
                $table->json('options')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
                $table->timestamp('updated_at')->nullable();
                $table->timestamp('deleted_at')->nullable();

                $table->unique('uuid');
                $table->unique(['tenant_uuid', 'slug']);
                $table->index('tenant_uuid');
                $table->index('status');
            });
        }

        if (!$schema->hasTable('commerce_variants')) {
            $schema->createTable('commerce_variants', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('tenant_uuid', 12)->default('');
                $table->string('product_uuid', 12);
                $table->string('sku', 191);
                $table->json('option_values');
                $table->bigInteger('price');
                $table->bigInteger('compare_at_price')->nullable();
                $table->string('currency', 3);
                $table->integer('position')->default(0);
                $table->string('status', 16)->default('active');
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
                $table->timestamp('updated_at')->nullable();

                $table->unique('uuid');
                $table->unique(['tenant_uuid', 'sku']);
                $table->index('product_uuid');
                $table->index('tenant_uuid');
            });
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('commerce_variants');
        $schema->dropTableIfExists('commerce_products');
    }

    public function getDescription(): string
    {
        return 'Creates commerce products and variants.';
    }
}
