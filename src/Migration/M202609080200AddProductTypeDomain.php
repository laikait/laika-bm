<?php
/**
 * Laika Bill Manager
 * Author: Showket Ahmed
 * Email: riyadhtayf@gmail.com
 * License: MIT
 * This file is part of Laika Bill Manager.
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace LBM\Migration;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use RuntimeException;
use Laika\Model\Model;
use LBM\Contract\MigrationAbstract;

/**
 * The fifth real migration: Phase 33 lets a product type say it needs a domain.
 *
 * ---------------------------------------------------------------------------
 * TWO COLUMNS, ONE MIGRATION
 * ---------------------------------------------------------------------------
 * `requires_domain` is the feature. `display_name` is what makes the screen
 * usable: `type_name` is UNIQUE and is the identifier seeds and code match on -
 * `shared_hosting`, not "Shared Hosting" - and until this phase it was rendered
 * raw, so the product form's type dropdown literally read `shared_hosting`.
 *
 * They ship together because they are one ALTER on one table, and splitting
 * them would mean two ledger rows describing one change.
 *
 * ---------------------------------------------------------------------------
 * A MIGRATION AND NOT A NEW TABLE
 * ---------------------------------------------------------------------------
 * `product_types` has existed since Phase 0. `up()` only ever calls
 * `createIfNotExists`, so a new COLUMN never reaches an installation that
 * already has the table - which is the whole reason Phase 21 built this
 * mechanism.
 *
 * ---------------------------------------------------------------------------
 * THE SEEDED TYPES GET THE SAME ANSWER EVERYWHERE
 * ---------------------------------------------------------------------------
 * `ProductTypeSchema::seed()` sets `requires_domain` for the seven shipped
 * types on a FRESH install; this sets the same values on an existing one. That
 * is deliberate: an installation should not behave differently because of when
 * it was created, and "shared hosting needs a domain name" is the answer both
 * would want.
 *
 * It is worth being explicit that this CHANGES BEHAVIOUR on upgrade - a
 * customer ordering shared hosting will now be asked for a domain. That is not
 * the same class of change as Phase 23's dunning or Phase 24's termination,
 * which is why it is not defaulted off: those destroy or disable something,
 * this adds a required field to an order form. The failure mode is "somebody is
 * asked for a domain name", it is visible immediately, and it is one checkbox
 * to turn off on a screen this phase also builds.
 *
 * Only rows whose `type_name` is one of the seven shipped ones are touched.
 * Anything an operator added themselves keeps the column's `no` default, which
 * is the only safe answer for a type this product knows nothing about.
 */
class M202609080200AddProductTypeDomain extends MigrationAbstract
{
    /** @var string Ledger Key. Written once, never edited */
    protected string $id = '20260908_0200_add_product_type_domain';

    /** @var string What This Does */
    protected string $description
        = 'Add product_types.requires_domain and display_name, and set them on the shipped types.';

    /**
     * @var array<string,string> The Shipped Types That Need a Domain Name
     *
     * Hosting, a VPS and a dedicated server are all ordered AGAINST a domain -
     * it is what the account is created for and what appears in the control
     * panel. An SSL certificate is ISSUED FOR one, so it cannot be ordered
     * without knowing which.
     *
     * `domain` itself is deliberately absent: a domain registration has its own
     * route, its own price list and its own auth-code path, and asking for a
     * domain to put a domain on would be nonsense.
     */
    private const SHIPPED = [
        'shared_hosting' =>  'Shared Hosting',
        'vps'            =>  'VPS',
        'dedicated'      =>  'Dedicated Server',
        'ssl'            =>  'SSL Certificate',
        'domain'         =>  'Domain',
        'software'       =>  'Software',
        'other'          =>  'Other',
    ];

    /** @var string[] Which Of Those Need a Domain */
    private const NEEDS_DOMAIN = ['shared_hosting', 'vps', 'dedicated', 'ssl'];

    /**
     * Whether This Install Needs It
     *
     * A fresh install created the table from ProductTypeSchema, which carries
     * both columns, so this answers false there and is recorded `baselined`
     * without run() ever being called.
     * @return bool
     */
    public function applies(): bool
    {
        return $this->hasTable('product_types')
            && !$this->hasColumn('product_types', 'requires_domain');
    }

    /**
     * Add The Columns, Then Fill Them In
     * @return void
     * @throws RuntimeException
     */
    public function run(): void
    {
        // statement() returns false on success - it is (bool) PDO::exec() and
        // DDL returns 0 - so nothing here branches on the return value. A real
        // failure arrives as a PDOException and stops the run, the ledger
        // records nothing, and the next migrate tries again.
        match ($this->driver()) {
            'mysql' => $this->schema()->statement(
                "ALTER TABLE `product_types` ADD COLUMN `requires_domain` ENUM('yes','no') NOT NULL DEFAULT 'no'"
            ),
            'pgsql' => $this->schema()->statement(
                'ALTER TABLE "product_types" ADD COLUMN "requires_domain" VARCHAR(3) NOT NULL DEFAULT \'no\''
            ),
            default => throw new RuntimeException(
                'Unsupported driver for ' . $this->id() . ': ' . $this->driver()
            ),
        };

        match ($this->driver()) {
            'mysql' => $this->schema()->statement(
                'ALTER TABLE `product_types` ADD COLUMN `display_name` VARCHAR(100) NULL DEFAULT NULL'
            ),
            'pgsql' => $this->schema()->statement(
                'ALTER TABLE "product_types" ADD COLUMN "display_name" VARCHAR(100) NULL DEFAULT NULL'
            ),
            default => throw new RuntimeException(
                'Unsupported driver for ' . $this->id() . ': ' . $this->driver()
            ),
        };

        // MODEL METHODS FROM HERE. The raw-DDL exception `verify-stage.php`
        // enforces exists for DDL, which has no model-layer expression. Filling
        // in rows does, so it uses the model layer like everything else.
        foreach (self::SHIPPED as $name => $label) {
            $this->table()->where(['type_name' => $name])->update([
                'display_name'    =>  $label,
                'requires_domain' =>  in_array($name, self::NEEDS_DOMAIN, true) ? 'yes' : 'no',
            ]);
        }
    }

    /**
     * A Model On This Migration's Own Connection
     * @return Model
     */
    private function table(): Model
    {
        return (new Model($this->connection()))->table('product_types');
    }
}
