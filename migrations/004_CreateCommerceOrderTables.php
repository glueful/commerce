<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateCommerceOrderTables implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('commerce_orders')) {
            $schema->createTable('commerce_orders', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('tenant_uuid', 12)->default('');
                $table->string('order_number', 64);
                $table->string('status', 24)->default('pending_payment');
                $table->string('fulfillment_status', 16)->default('unfulfilled');
                $table->string('tracking_ref', 191)->nullable();
                $table->string('email', 255);
                $table->string('user_uuid', 12)->nullable();
                $table->string('guest_token_hash', 64);
                $table->string('currency', 3);
                $table->bigInteger('subtotal');
                $table->bigInteger('discount_total')->default(0);
                $table->bigInteger('shipping_total')->default(0);
                $table->bigInteger('tax_total')->default(0);
                $table->bigInteger('grand_total');
                $table->string('discount_code', 64)->nullable();
                $table->string('shipping_method', 64)->nullable();
                $table->json('addresses')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('placed_at')->nullable();
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
                $table->timestamp('updated_at')->nullable();

                $table->unique('uuid');
                $table->unique(['tenant_uuid', 'order_number']);
                $table->index(['tenant_uuid', 'status']);
                $table->index(['tenant_uuid', 'user_uuid']);
            });
        }

        if (!$schema->hasTable('commerce_order_lines')) {
            $schema->createTable('commerce_order_lines', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('order_uuid', 12);
                $table->string('variant_uuid', 12);
                $table->string('product_name', 255);
                $table->string('sku', 191);
                $table->json('option_values');
                $table->bigInteger('unit_price');
                $table->integer('quantity');
                $table->bigInteger('line_total');

                $table->unique('uuid');
                $table->index('order_uuid');
            });
        }

        if (!$schema->hasTable('commerce_order_events')) {
            $schema->createTable('commerce_order_events', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('order_uuid', 12);
                $table->string('type', 48);
                $table->json('payload')->nullable();
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');

                $table->unique('uuid');
                $table->index('order_uuid');
            });
        }

        if (!$schema->hasTable('commerce_sequences')) {
            $schema->createTable('commerce_sequences', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('tenant_uuid', 12)->default('');
                $table->string('name', 32);
                $table->bigInteger('value')->default(0);

                $table->unique(['tenant_uuid', 'name']);
            });
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('commerce_sequences');
        $schema->dropTableIfExists('commerce_order_events');
        $schema->dropTableIfExists('commerce_order_lines');
        $schema->dropTableIfExists('commerce_orders');
    }

    public function getDescription(): string
    {
        return 'Creates commerce orders, order lines, audit events, and sequences.';
    }
}
