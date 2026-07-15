<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateCommerceCatalogBreadthTables implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('commerce_product_media')) {
            $schema->createTable('commerce_product_media', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('tenant_uuid', 12)->default('');
                $table->string('product_uuid', 12);
                $table->string('variant_uuid', 12)->nullable();
                $table->string('blob_uuid', 12);
                $table->string('role', 16)->default('gallery');
                $table->integer('position')->default(0);
                $table->string('alt', 255)->nullable();

                $table->unique('uuid');
                $table->unique(['product_uuid', 'blob_uuid']);
                $table->index('tenant_uuid');
                $table->index('product_uuid');
            });
        }

        if (!$schema->hasTable('commerce_categories')) {
            $schema->createTable('commerce_categories', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('tenant_uuid', 12)->default('');
                $table->string('parent_uuid', 12)->nullable();
                $table->string('slug', 191);
                $table->string('name', 255);
                $table->text('description')->nullable();
                $table->integer('position')->default(0);
                $table->integer('revision')->default(0);
                $table->string('blob_uuid', 12)->nullable();

                $table->unique('uuid');
                $table->unique(['tenant_uuid', 'slug']);
                $table->index('tenant_uuid');
                $table->index('parent_uuid');
            });
        }

        if (!$schema->hasTable('commerce_product_categories')) {
            $schema->createTable('commerce_product_categories', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('product_uuid', 12);
                $table->string('category_uuid', 12);

                $table->unique(['product_uuid', 'category_uuid']);
                $table->index('product_uuid');
                $table->index('category_uuid');
            });
        }

        if (!$schema->hasTable('commerce_tags')) {
            $schema->createTable('commerce_tags', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('tenant_uuid', 12)->default('');
                $table->string('slug', 191);
                $table->string('name', 255);
                $table->integer('revision')->default(0);

                $table->unique('uuid');
                $table->unique(['tenant_uuid', 'slug']);
                $table->index('tenant_uuid');
            });
        }

        if (!$schema->hasTable('commerce_product_tags')) {
            $schema->createTable('commerce_product_tags', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('product_uuid', 12);
                $table->string('tag_uuid', 12);

                $table->unique(['product_uuid', 'tag_uuid']);
                $table->index('product_uuid');
                $table->index('tag_uuid');
            });
        }

        if (!$schema->hasTable('commerce_attributes')) {
            $schema->createTable('commerce_attributes', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('tenant_uuid', 12)->default('');
                $table->string('slug', 191);
                $table->string('name', 255);
                $table->integer('position')->default(0);
                $table->integer('revision')->default(0);

                $table->unique('uuid');
                $table->unique(['tenant_uuid', 'slug']);
                $table->index('tenant_uuid');
            });
        }

        if (!$schema->hasTable('commerce_attribute_values')) {
            $schema->createTable('commerce_attribute_values', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('attribute_uuid', 12);
                $table->string('slug', 191);
                $table->string('value', 255);
                $table->integer('position')->default(0);

                $table->unique('uuid');
                $table->unique(['attribute_uuid', 'slug']);
                $table->index('attribute_uuid');
            });
        }

        if (!$schema->hasTable('commerce_product_attributes')) {
            $schema->createTable('commerce_product_attributes', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('product_uuid', 12);
                $table->string('attribute_uuid', 12)->nullable();
                $table->string('name', 255)->nullable();
                $table->json('values');
                $table->boolean('used_for_variants')->default(false);
                $table->boolean('visible')->default(true);
                $table->integer('position')->default(0);

                $table->unique('uuid');
                $table->unique(['product_uuid', 'attribute_uuid']);
                $table->index('product_uuid');
            });
        }

        if (!$schema->hasTable('commerce_product_children')) {
            $schema->createTable('commerce_product_children', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('product_uuid', 12);
                $table->string('child_uuid', 12);
                $table->integer('position')->default(0);

                $table->unique(['product_uuid', 'child_uuid']);
                $table->index('product_uuid');
                $table->index('child_uuid');
            });
        }

        if (!$schema->hasTable('commerce_product_addons')) {
            $schema->createTable('commerce_product_addons', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('tenant_uuid', 12)->default('');
                $table->string('product_uuid', 12);
                $table->string('name', 255);
                $table->string('field_type', 16);
                $table->boolean('required')->default(false);
                $table->json('choices')->nullable();
                $table->bigInteger('price_delta')->default(0);
                $table->integer('position')->default(0);
                $table->string('status', 16)->default('active');

                $table->unique('uuid');
                $table->index('tenant_uuid');
                $table->index('product_uuid');
            });
        }

        if (!$schema->hasTable('commerce_reviews')) {
            $schema->createTable('commerce_reviews', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('tenant_uuid', 12)->default('');
                $table->string('product_uuid', 12);
                $table->string('user_uuid', 12)->nullable();
                $table->string('author_name', 255);
                $table->string('author_email', 255);
                $table->integer('rating');
                $table->text('body');
                $table->string('status', 16)->default('pending');
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
                $table->timestamp('updated_at')->nullable();

                $table->unique('uuid');
                $table->index('tenant_uuid');
                $table->index('product_uuid');
            });
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('commerce_product_categories');
        $schema->dropTableIfExists('commerce_product_tags');
        $schema->dropTableIfExists('commerce_attribute_values');
        $schema->dropTableIfExists('commerce_product_attributes');
        $schema->dropTableIfExists('commerce_product_children');
        $schema->dropTableIfExists('commerce_product_media');
        $schema->dropTableIfExists('commerce_product_addons');
        $schema->dropTableIfExists('commerce_reviews');
        $schema->dropTableIfExists('commerce_categories');
        $schema->dropTableIfExists('commerce_tags');
        $schema->dropTableIfExists('commerce_attributes');
    }

    public function getDescription(): string
    {
        return 'Creates commerce catalog breadth tables: media, categories, tags, attributes, '
            . 'product children, add-ons, and reviews.';
    }
}
