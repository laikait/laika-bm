<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Core\Abstracts\SchemaAbstract;

class ClientServiceConfigValueSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'client_service_config_values';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->id('cscv_id')->comment('Client Service Config Values ID');
            $t->unsignedBigInteger('service_relid')->comment('client_services -> service_id');
            $t->unsignedInteger('pco_relid')->comment('product_config_options -> pco_id');
            $t->unsignedInteger('pcos_relid')->nullable()->default(null)->comment('product_config_option_subs -> pcos_id');
            $t->unsignedInteger('quantity')->nullable();
            $t->string('text_value', 500)->nullable();

            // Indexes
            $t->index('service_relid');
            $t->index('pco_relid');
            $t->index('pcos_relid');
        });
    }
}
