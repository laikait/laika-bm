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

namespace LBM\Action;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use RuntimeException;
use Laika\Model\Model;
use LBM\Model\ProductModel;
use LBM\Model\ProductTypeModel;

/**
 * The kinds of thing a product can be.
 *
 * `product_types` has existed since Phase 0 with seven seeded rows, and until
 * Phase 33 exactly ONE thing read it: `ProductController::typeChoices()`, to
 * fill a dropdown. There was no screen, no controller, no route and no action -
 * so an operator could not add a type, rename one, or find out what any of them
 * meant, and the dropdown showed them `shared_hosting`.
 *
 * ---------------------------------------------------------------------------
 * WHAT A TYPE IS FOR, NOW THAT IT DOES SOMETHING
 * ---------------------------------------------------------------------------
 * `requires_domain` is the first thing on this table that changes behaviour: a
 * product whose type requires a domain cannot be ordered without one, and its
 * order form grows a field to type it into.
 *
 * That is the whole feature, and it is worth saying what it is NOT. It does not
 * register the domain, price it, or check whether anybody owns it - a domain
 * ordered that way goes through `/cart/domain`, which has its own price list
 * and its own registrar path. This is the name an account is created FOR, which
 * is what `order_items.domain` has carried since Phase 0 and what
 * `Provision::groupLines()` groups by.
 */
class ProductType extends Action
{
    public function model(): Model
    {
        return new ProductTypeModel();
    }

    /** @var string[] Columns a Form May Write */
    public const FIELDS = ['type_name', 'display_name', 'requires_domain'];

    protected function searchable(): array
    {
        return ['type_name', 'display_name'];
    }

    ####################################################################################
    /*================================= EXTERNAL API =================================*/
    ####################################################################################

    /**
     * Every Type, With What To Call It
     * @return array
     */
    public function allTypes(): array
    {
        $model = $this->model();
        $rows = [];

        foreach ($model->order('type_name', self::ASC)->get() as $row) {
            $row['label'] = self::label($row);
            $row['in_use'] = $this->countProducts((int) $row['product_type_id']);
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * The Dropdown For The Product Form
     * @return array<int,string>
     */
    public function choices(): array
    {
        $choices = [];

        foreach ($this->model()->order('type_name', self::ASC)->get() as $row) {
            $choices[(int) $row['product_type_id']] = self::label($row);
        }

        return $choices;
    }

    /**
     * Whether a Product's Type Wants a Domain Name
     *
     * The one question the order form and the cart both ask. Answers FALSE for
     * a product with no type, a type that has been deleted, or a table that has
     * not been migrated yet - because the alternative is a shop that stops
     * taking orders when something is missing, and "no" is the behaviour every
     * install had before this phase.
     * @param int $productId Product ID
     * @return bool
     */
    public function productNeedsDomain(int $productId): bool
    {
        if ($productId <= 0) {
            return false;
        }

        $product = (new ProductModel())->where(['pid' => $productId])->first();

        return $this->needsDomain((int) ($product['type_relid'] ?? 0));
    }

    /**
     * Whether One Type Wants a Domain Name
     * @param int $typeId product_types -> product_type_id
     * @return bool
     */
    public function needsDomain(int $typeId): bool
    {
        if ($typeId <= 0) {
            return false;
        }

        $row = $this->model()->where(['product_type_id' => $typeId])->first();

        return is_array($row) && (string) ($row['requires_domain'] ?? 'no') === 'yes';
    }

    /**
     * Create Or Update a Type
     *
     * @param array $input Form Input
     * @param int $typeId Zero to create
     * @return int The type's id
     * @throws RuntimeException
     */
    public function save(array $input, int $typeId = 0): int
    {
        $name = $this->slugify((string) ($input['type_name'] ?? ''));

        if ($name === '') {
            throw new RuntimeException(local('product_type_needs_a_name'));
        }

        // `type_name` is UNIQUE, and a driver error here would reach the
        // operator as an SQL message. Checked first so the refusal is in words.
        $clash = $this->model()->where(['type_name' => $name])->first();

        if (is_array($clash) && (int) $clash['product_type_id'] !== $typeId) {
            throw new RuntimeException(local('product_type_exists'));
        }

        $display = trim((string) ($input['display_name'] ?? ''));

        $data = [
            'type_name'       =>  $name,
            'display_name'    =>  $display === '' ? null : mb_substr($display, 0, 100),
            'requires_domain' =>  ($input['requires_domain'] ?? '') === 'yes' ? 'yes' : 'no',
        ];

        if ($typeId > 0) {
            $this->update($typeId, $data);

            return $typeId;
        }

        return (int) $this->create($data + ['is_default' => 'no']);
    }

    /**
     * Delete a Type
     *
     * REFUSED WHILE ANY PRODUCT USES IT. `products.type_relid` is NOT NULL, so
     * deleting a type in use leaves rows pointing at nothing - and on MySQL in
     * its default non-strict mode that is not an error, it is a product whose
     * type silently becomes id zero. The product form then cannot save it and
     * nobody can say why.
     * @param int $typeId Type ID
     * @return int Rows removed
     * @throws RuntimeException
     */
    public function remove(int $typeId): int
    {
        $count = $this->countProducts($typeId);

        if ($count > 0) {
            throw new RuntimeException(local('product_type_in_use', $count));
        }

        return $this->delete($typeId);
    }

    /**
     * How Many Products Are This Type
     * @param int $typeId Type ID
     * @return int
     */
    public function countProducts(int $typeId): int
    {
        return $typeId <= 0 ? 0 : (new ProductModel())->where(['type_relid' => $typeId])->count();
    }

    /**
     * What To Call a Type On Screen
     *
     * The stored display name, or the identifier made readable. Static so the
     * templates and the product form can both use it without a relay round
     * trip, and so there is exactly one answer to "what is this type called".
     * @param array $row Type Row
     * @return string
     */
    public static function label(array $row): string
    {
        $display = trim((string) ($row['display_name'] ?? ''));

        if ($display !== '') {
            return $display;
        }

        // `shared_hosting` becomes "Shared Hosting". Only ever a fallback: an
        // operator's own `cpanel_vps` would come out "Cpanel Vps", which is why
        // display_name exists and why the form asks for one.
        return ucwords(str_replace(['_', '-'], ' ', (string) ($row['type_name'] ?? '')));
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * The Identifier Shape
     *
     * Lowercase, underscores, nothing else - so a type an operator adds is the
     * same shape as the seven that ship, and so `type_name` stays something
     * code can match on.
     * @param string $value Submitted Name
     * @return string
     */
    private function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = (string) preg_replace('/[^a-z0-9]+/', '_', $value);

        return trim($value, '_');
    }
}
