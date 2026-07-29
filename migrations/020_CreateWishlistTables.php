<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Account-backed wishlist (accounts design spec §11): a parent list row per
 * (tenant, user) plus positioned item rows. Genuinely new tables, first published
 * in Commerce 1.8.0 -- never a fold into an earlier migration.
 *
 * The parent exists to be LOCKED. The 100-item cap and the merge ordering are
 * guarantees about the whole set, and a set has no row to serialize on; this
 * mirrors `commerce_customer_address_books`, whose `revision` claim is what makes
 * two concurrent writes to one account line up instead of interleaving.
 *
 * `position` (not `created_at`) is the display order, ascending. Saves go to the
 * front, imports append to the back. Ordering by timestamp would put freshly
 * imported rows ahead of the account's own older items -- the opposite of the rule.
 *
 * Commerce stores only the tenant-scoped commerce fact; identity lives in the host.
 */
final class CreateWishlistTables implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('commerce_wishlists')) {
            $schema->createTable('commerce_wishlists', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('tenant_uuid', 12)->default('');
                $table->string('user_uuid', 12);
                // Claim counter: the affected-row-checked bump every growth path takes.
                $table->bigInteger('revision')->default(0);
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
                $table->timestamp('updated_at')->nullable();

                $table->unique(['tenant_uuid', 'user_uuid'], 'commerce_wishlists_tenant_user_unique');
                $table->unique(['tenant_uuid', 'uuid'], 'commerce_wishlists_tenant_uuid_unique');
            });
        }

        if (!$schema->hasTable('commerce_wishlist_items')) {
            $schema->createTable('commerce_wishlist_items', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('tenant_uuid', 12)->default('');
                $table->string('user_uuid', 12);
                $table->string('product_uuid', 12);
                // Display order, ascending. Negative values are expected: saves take
                // `min - 1` so a new item leads without renumbering the whole list.
                $table->bigInteger('position')->default(0);
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
                $table->timestamp('updated_at')->nullable();

                // Saving the same product twice is idempotent, not a duplicate row.
                $table->unique(
                    ['tenant_uuid', 'user_uuid', 'product_uuid'],
                    'commerce_wishlist_items_tenant_user_product_unique'
                );
                $table->unique(['tenant_uuid', 'uuid'], 'commerce_wishlist_items_tenant_uuid_unique');
                $table->index(
                    ['tenant_uuid', 'user_uuid', 'position'],
                    'commerce_wishlist_items_tenant_user_position_index'
                );
            });
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('commerce_wishlist_items');
        $schema->dropTableIfExists('commerce_wishlists');
    }

    public function getDescription(): string
    {
        return 'Creates the account-backed wishlist tables (commerce_wishlists, commerce_wishlist_items).';
    }
}
