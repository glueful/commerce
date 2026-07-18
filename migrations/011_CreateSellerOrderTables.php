<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Marketplace MV2 shared-checkout foundation (design spec §3.3): the
 * `commerce_seller_orders` partition created per `(order, seller)` at
 * checkout, carrying the exactly-reconciled money facts (subtotal, allocated
 * discount / shipping-discount / shipping / tax, attributed_total) and
 * independent fulfillment (status, carrier, tracking) for that seller's
 * slice of the order. This is a genuinely NEW table -- unlike the
 * `commerce_order_lines`/`commerce_orders` column additions (folded into
 * migrations 004), there is no existing table to fold into.
 */
final class CreateSellerOrderTables implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('commerce_seller_orders')) {
            $schema->createTable('commerce_seller_orders', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('tenant_uuid', 12);
                $table->string('order_uuid', 12);
                $table->string('seller_uuid', 12);
                $table->string('seller_name_snapshot', 160);
                $table->integer('partition_number');
                $table->string('seller_reference', 96);
                $table->string('currency', 3);
                $table->bigInteger('subtotal');
                $table->bigInteger('allocated_discount')->default(0);
                $table->bigInteger('allocated_shipping_discount')->default(0);
                $table->bigInteger('allocated_shipping')->default(0);
                $table->bigInteger('allocated_tax')->default(0);
                $table->bigInteger('attributed_total');
                $table->string('tax_attribution_method', 20);
                $table->timestamp('confirmed_at')->nullable();
                $table->string('fulfillment_status', 16)->default('unfulfilled');
                $table->timestamp('fulfilled_at')->nullable();
                $table->string('carrier', 96)->nullable();
                $table->string('tracking_number', 191)->nullable();
                $table->string('tracking_url', 512)->nullable();
                $table->string('status', 16)->default('open');
                $table->bigInteger('revision')->default(0);
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
                $table->timestamp('updated_at')->nullable();

                $table->unique(['order_uuid', 'seller_uuid']);
                $table->unique(['order_uuid', 'partition_number']);
                $table->unique(['tenant_uuid', 'seller_reference']);
                $table->unique(['tenant_uuid', 'uuid']);
                // Explicit short name: the auto-generated 4-column name (84 bytes) silently
                // truncates on PostgreSQL's 63-byte NAMEDATALEN limit -- SQLite stores it
                // verbatim, so the divergence is invisible without a real pgsql run (see
                // MarketplaceOrderShapeTest's PostgreSQL convergence lane).
                $table->index(
                    ['tenant_uuid', 'seller_uuid', 'confirmed_at', 'fulfillment_status'],
                    'commerce_seller_orders_confirmed_listing_index'
                );
                $table->index('order_uuid');
            });
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('commerce_seller_orders');
    }

    public function getDescription(): string
    {
        return 'Creates the commerce_seller_orders table for MV2 shared-checkout seller partitions.';
    }
}
