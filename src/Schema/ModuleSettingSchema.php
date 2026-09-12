<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Model\Contract\SchemaAbstract;

/**
 * What an operator saved for a module that has no table of its own - Phase 40.
 *
 * Gateways keep their settings on `payment_gateways.settings`, registrars on
 * `domain_registrars.credentials`, a server module's per-product fields on
 * `products.module_config` - each kind already had a row that WAS its
 * configuration. Lookup, fraud and plugin modules had none, so their settings
 * live here, one row per declared field, keyed to the `modules` row.
 *
 * One row per field rather than a serialize blob, so `is_secret` can be said
 * per value and a stored secret can be told from a plain one without opening
 * anything. The Live/Test switch is a row too, under `mode` - the one name no
 * field may take.
 *
 * A NEW table, so `up()` creates it on every installation that already exists
 * and no migration is needed.
 */
class ModuleSettingSchema extends SchemaAbstract
{
    protected string $table = 'module_settings';

    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->bigId('ms_id');
            $t->unsignedBigInteger('module_relid')->comment('modules -> module_id');
            $t->string('setting_key', 60)->comment('The name the module declared, or `mode`');

            // Sealed with Vault when is_secret says so, and never rendered
            // back - ModuleSettings::forForm() is the only view a screen gets.
            $t->text('setting_value')->nullable()->default(NULL);
            $t->enum('is_secret', ['yes', 'no'])->default('no');
            $t->timestamps('ms_created_at', 'ms_updated_at');

            // Indexes
            $t->unique(['module_relid', 'setting_key'], 'module_settings_key_unique');
            $t->index('module_relid');
        });
    }
}
