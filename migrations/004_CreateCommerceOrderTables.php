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
                // Marketplace shared checkout (MV2): the partition marker is set once at
                // placement to MarketplaceMode::activeFor(tenant) and never mutated -- all
                // historical behavior branches on the order's own flag, not current
                // activation (design spec §2.6). fulfillment_revision is the parent-side
                // claim counter for the parent-then-children fulfillment rollup (§2.8).
                $table->boolean('marketplace_partitioned')->default(false);
                $table->bigInteger('fulfillment_revision')->default(0);
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
                $table->bigInteger('refunded_total')->default(0);
                $table->bigInteger('refund_revision')->default(0);
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
                // Layer 5 reports: report_at is the two-branch derived timestamp
                // (placed_at when present, else created_at -- see ReportWindow /
                // the sales & customers repositories). Each branch needs its own
                // indexable range predicate; a single index on COALESCE(...)
                // would not be usable by either branch's plain column comparison.
                $table->index(['tenant_uuid', 'placed_at']);
                $table->index(['tenant_uuid', 'created_at']);
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
                // Marketplace shared checkout (MV2, design spec §3.1): immutable seller
                // snapshot (null for non-partitioned orders) plus this line's allocated
                // share of the order-level discount and its per-line merchandise tax
                // (populated only on the line_detailed tax-attribution path; else 0).
                $table->string('seller_uuid', 12)->nullable();
                $table->bigInteger('discount_amount')->default(0);
                $table->bigInteger('tax_amount')->default(0);
                $table->json('addons')->nullable();
                $table->json('downloads')->nullable();

                $table->unique('uuid');
                $table->index('order_uuid');
                $table->index(['order_uuid', 'seller_uuid']);
            });
        }

        if (!$schema->hasTable('commerce_order_events')) {
            $schema->createTable('commerce_order_events', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('order_uuid', 12);
                $table->string('type', 48);
                $table->json('payload')->nullable();
                $table->string('actor_uuid', 12)->nullable();
                $table->string('visibility', 16)->default('internal');
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
