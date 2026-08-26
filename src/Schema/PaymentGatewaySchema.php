<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Core\Abstracts\SchemaAbstract;

class PaymentGatewaySchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'payment_gateways';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->id('gateway_id');
            $t->string('gateway_name', 100)->comment('stripe, paypal, paypal');
            $t->string('gateway_slug');
            $t->string('display_name', 100);
            $t->string('module_class', 100);
            $t->string('logo_url')->nullable()->default(NULL);
            $t->serialize('settings')->nullable()->default(NULL)->comment('encrypted API keys/secrets');
            $t->enum('test_mode', ['yes', 'no'])->default('no');
            $t->enum('is_active', ['yes', 'no'])->default('yes');
            $t->json('features')->nullable()->default(NULL)->comment('{"refunds":true,"subscriptions":true}');
            $t->timestamps('gateway_created_at', 'gateway_updated_at');

            // Indexes
            $t->unique('gateway_name');
            $t->unique('gateway_slug');
            $t->index('is_active');
        });
    }
}
