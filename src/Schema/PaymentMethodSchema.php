<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Core\Abstracts\SchemaAbstract;

class PaymentMethodSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'payment_methods';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->id('pm_id');
            $t->unsignedBigInteger('client_relid')->comment('clients -> id');
            $t->unsignedInteger('gateway_relid')->comment('payment_gateways -> gateway_id');
            $t->unsignedInteger('type_relid')->comment('payment_method_types -> pm_type_id');
            $t->string('token')->comment('Gateway Vault Token');
            $t->char('last_four', 4)->nullable()->default(NULL);
            $t->string('card_brand', 20)->nullable()->default(NULL);
            $t->tinyInteger('expiry_month')->nullable()->default(NULL);
            $t->tinyInteger('expiry_year')->nullable()->default(NULL);
            $t->enum('is_default', ['yes', 'no'])->default('no');
            $t->string('billing_name')->nullable()->default(NULL);
            $t->json('billing_address')->nullable()->default(NULL);
            $t->timestamp('method_created_at');

            // Indexes
            $t->index('client_relid');
            $t->index('gateway_relid');
            $t->index('type_relid');
            $t->index('is_default');
            $t->index('method_created_at');
        });
    }
}
