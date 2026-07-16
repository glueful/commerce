<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateCommerceCartTables implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('commerce_carts')) {
            $schema->createTable('commerce_carts', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('tenant_uuid', 12)->default('');
                $table->string('token_hash', 64);
                $table->string('user_uuid', 12)->nullable();
                $table->string('discount_code', 64)->nullable();
                $table->string('status', 16)->default('active');
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
                $table->timestamp('updated_at')->nullable();

                $table->unique('uuid');
                $table->unique(['tenant_uuid', 'token_hash']);
                $table->index(['tenant_uuid', 'user_uuid']);
            });
        }

        if (!$schema->hasTable('commerce_cart_lines')) {
            $schema->createTable('commerce_cart_lines', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('cart_uuid', 12);
                $table->string('variant_uuid', 12);
                $table->integer('quantity');
                $table->json('addons')->nullable();
                $table->string('addons_hash', 64)->default('');
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
                $table->timestamp('updated_at')->nullable();

                $table->unique('uuid');
                $table->unique(['cart_uuid', 'variant_uuid', 'addons_hash']);
            });
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('commerce_cart_lines');
        $schema->dropTableIfExists('commerce_carts');
    }

    public function getDescription(): string
    {
        return 'Creates commerce cart and cart line tables.';
    }
}
