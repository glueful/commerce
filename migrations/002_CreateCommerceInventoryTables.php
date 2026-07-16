<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateCommerceInventoryTables implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('commerce_stock')) {
            $schema->createTable('commerce_stock', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('tenant_uuid', 12)->default('');
                $table->string('variant_uuid', 12);
                $table->bigInteger('quantity')->default(0);
                $table->boolean('tracked')->default(true);
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
                $table->timestamp('updated_at')->nullable();

                $table->unique('uuid');
                $table->unique(['tenant_uuid', 'variant_uuid']);
            });
        }

        if (!$schema->hasTable('commerce_stock_movements')) {
            $schema->createTable('commerce_stock_movements', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('tenant_uuid', 12)->default('');
                $table->string('variant_uuid', 12);
                $table->bigInteger('delta');
                $table->string('reason', 24);
                $table->string('reference_uuid', 12)->nullable();
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');

                $table->unique('uuid');
                $table->index(['tenant_uuid', 'variant_uuid']);
            });
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('commerce_stock_movements');
        $schema->dropTableIfExists('commerce_stock');
    }

    public function getDescription(): string
    {
        return 'Creates commerce stock and stock movement tables.';
    }
}
