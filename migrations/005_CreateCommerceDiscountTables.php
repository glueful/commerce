<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateCommerceDiscountTables implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('commerce_discounts')) {
            $schema->createTable('commerce_discounts', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('tenant_uuid', 12)->default('');
                $table->string('code', 64);
                $table->string('type', 16);
                $table->bigInteger('value')->default(0);
                $table->bigInteger('min_subtotal')->nullable();
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('ends_at')->nullable();
                $table->bigInteger('usage_limit')->nullable();
                $table->boolean('once_per_buyer')->default(false);
                $table->bigInteger('usage_count')->default(0);
                $table->json('product_scope')->nullable();
                $table->string('status', 16)->default('active');
                $table->integer('revision')->default(0);
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
                $table->timestamp('updated_at')->nullable();

                $table->unique('uuid');
                $table->unique(['tenant_uuid', 'code']);
            });
        }

        if (!$schema->hasTable('commerce_discount_redemptions')) {
            $schema->createTable('commerce_discount_redemptions', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('tenant_uuid', 12)->default('');
                $table->string('discount_uuid', 12);
                $table->string('order_uuid', 12);
                $table->string('buyer_identity', 255);
                $table->string('buyer_key', 255);
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');

                $table->unique('uuid');
                $table->unique(['tenant_uuid', 'discount_uuid', 'buyer_key']);
            });
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('commerce_discount_redemptions');
        $schema->dropTableIfExists('commerce_discounts');
    }

    public function getDescription(): string
    {
        return 'Creates commerce discounts and discount redemption tables.';
    }
}
