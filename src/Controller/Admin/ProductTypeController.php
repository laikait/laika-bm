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

namespace LBM\Controller\Admin;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Laika\Service\Request;
use LBM\Service\ProductType;

/**
 * Product types.
 *
 * `product_types` has been in the schema since Phase 0 with seven seeded rows
 * and no screen at all - it was read by one private method on the product
 * controller to fill a dropdown, and an operator could neither add a type nor
 * see what any of them meant.
 *
 * Gated on **`product`** rather than a group of its own. 20.5's rule: a group
 * in `Permission::GROUPS` is granted only when a ROLE IS CREATED, so a new one
 * would be invisible on every installation that already has roles - and a
 * product type IS catalogue, so whoever may edit a product may edit these.
 */
class ProductTypeController extends AdminController
{
    protected function nav(): string
    {
        return 'products';
    }

    /**
     * The List
     * @return string
     */
    public function index(): string
    {
        return $this->screen('product-types', local('product_types'), [
            'types' =>  ProductType::allTypes(),
        ]);
    }

    /**
     * The New-Type Form
     * @return string
     */
    public function create(): string
    {
        return $this->form(null);
    }

    /**
     * One Type
     * @param string $type Type Uid
     * @return string
     */
    public function edit(string $type): string
    {
        return $this->form($this->record(ProductType::find($type), 'product_type'));
    }

    /**
     * Create Or Update
     * @param ?string $type Type Uid. Null to create
     * @return ?string
     */
    public function save(?string $type = null): ?string
    {
        $row = $type === null ? null : $this->record(ProductType::find($type), 'product_type');
        $input = Request::inputs();

        return $this->attempt(
            function () use ($row, $input): void {
                $id = ProductType::save($input, (int) ($row['product_type_id'] ?? 0));

                $this->log(
                    $row === null ? 'product_type.created' : 'product_type.updated',
                    ($row === null ? 'Added' : 'Updated') . ' the '
                        . trim((string) ($input['type_name'] ?? '?')) . ' product type.',
                    ['product_type_id' => $id]
                );
            },
            'staff.product.types',
            $row === null ? local('product_type_added') : local('product_type_updated')
        );
    }

    /**
     * Delete a Type
     * @param string $type Type Uid
     * @return ?string
     */
    public function delete(string $type): ?string
    {
        $row = $this->record(ProductType::find($type), 'product_type');

        return $this->attempt(
            function () use ($row): void {
                // remove() refuses while any product uses it, and says how
                // many. products.type_relid is NOT NULL, so deleting one in use
                // leaves rows pointing at nothing - which MySQL's default
                // non-strict mode turns into a type id of zero rather than an
                // error, and nobody can then say why the product will not save.
                ProductType::remove((int) $row['product_type_id']);

                $this->log(
                    'product_type.deleted',
                    'Deleted the ' . (string) $row['type_name'] . ' product type.'
                );
            },
            'staff.product.types',
            local('product_type_deleted')
        );
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * The Form, For Both Create And Edit
     * @param ?array $row Type Row, Or Null
     * @return string
     */
    private function form(?array $row): string
    {
        return $this->screen(
            'product-type-form',
            $row === null ? local('add_product_type') : local('edit_product_type'),
            ['type' => $row]
        );
    }
}
