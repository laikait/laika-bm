<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Model\Contract\SchemaAbstract;

class DomainNameserverSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'domain_nameservers';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->id('ns_id');
            $t->uid('uid');
            $t->unsignedBigInteger('domain_relid')->comment('domains -> dimain_id');
            $t->string('hostname');
            $t->string('ip_address', 100)->nullable()->default(NULL);
            $t->timestamp('ns_created_at');

            // Indexes
            $t->index('domain_relid');
            $t->index('ns_created_at');
        });
    }
}
