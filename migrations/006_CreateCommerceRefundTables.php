<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateCommerceRefundTables implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('commerce_refunds')) {
            $schema->createTable('commerce_refunds', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('tenant_uuid', 12)->default('');
                $table->string('order_uuid', 12);
                $table->string('idempotency_key', 128);
                $table->string('request_fingerprint', 64);
                $table->bigInteger('amount');
                $table->string('currency', 3);
                $table->string('method', 16);
                $table->string('status', 16)->default('pending');
                $table->text('reason')->nullable();
                $table->boolean('restocked')->default(false);
                $table->string('provider_ref', 191)->nullable();
                $table->text('failure_reason')->nullable();
                $table->string('initiated_by', 12)->nullable();
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
                $table->timestamp('updated_at')->nullable();
                $table->timestamp('completed_at')->nullable();

                $table->unique('uuid');
                $table->unique(['tenant_uuid', 'idempotency_key']);
                $table->index('tenant_uuid');
                $table->index('order_uuid');
            });
        }

        if (!$schema->hasTable('commerce_refund_lines')) {
            $schema->createTable('commerce_refund_lines', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('refund_uuid', 12);
                $table->string('order_line_uuid', 12);
                $table->integer('quantity');
                $table->bigInteger('amount');

                $table->unique(['refund_uuid', 'order_line_uuid']);
                $table->index('refund_uuid');
            });
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('commerce_refund_lines');
        $schema->dropTableIfExists('commerce_refunds');
    }

    public function getDescription(): string
    {
        return 'Creates commerce refunds and refund line tables.';
    }
}
