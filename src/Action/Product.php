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
use LBM\Model\ProductGroupModel;
use LBM\Model\ProductPricingModel;
use LBM\Model\ClientServiceModel;
use LBM\Service\Money;
use LBM\Service\Status;
use Laika\Service\Uid;

/**
 * Products, the groups they sit in, and what they cost.
 *
 * Pricing is a separate table rather than columns on the product, because a
 * price is per currency *and* per billing cycle: one product legitimately has a
 * monthly USD price, an annual USD price and a monthly EUR price at once. The
 * unique index on (product, currency, cycle) is what stops two of the same.
 */
class Product extends Action
{
    /** @var string Status Lookup Table */
    public const STATUSES = 'product_statuses';

    /** @var string[] Columns a Form May Write */
    public const FIELDS = [
        'product_slug', 'group_relid', 'product_name', 'description', 'type_relid',
        'pricing_model', 'setup_fee', 'tax_rate', 'welcome_email_relid',
        'module_name', 'stock_control', 'stock_qty', 'is_featured', 'status_relid',
    ];

    /** @var string[] How a Product Is Charged For */
    public const PRICING_MODELS = ['one_time', 'recurring', 'usage', 'free'];

    /** @var string[] Columns That Store Null Rather Than An Empty String */
    private const NULLABLE = [
        'description', 'welcome_email_relid', 'module_name', 'stock_qty',
    ];

    public function model(): Model
    {
        return new ProductModel();
    }

    protected function searchable(): array
    {
        return ['product_name', 'product_slug'];
    }

    protected function createdColumn(): ?string
    {
        return 'product_created_at';
    }

    protected function updatedColumn(): ?string
    {
        return 'product_updated_at';
    }

    ####################################################################################
    /*=================================== PRODUCTS ===================================*/
    ####################################################################################

    /**
     * Find a Product By Its Slug
     * @param string $slug Product Slug
     * @return ?array
     */
    public function findBySlug(string $slug): ?array
    {
        $row = $this->model()->where(['product_slug' => trim($slug)])->first();

        return is_array($row) ? $row : null;
    }

    /**
     * Create a Product
     * @param array $input Submitted Data
     * @return int New Product ID
     */
    public function store(array $input): int
    {
        $data = $this->fields($input);

        $data['product_slug'] = $this->uniqueSlug(
            (string) ($data['product_slug'] ?? ''),
            (string) ($data['product_name'] ?? 'product')
        );

        $data['status_relid'] = (int) ($data['status_relid'] ?? 0)
            ?: (Status::idOf(self::STATUSES, 'active') ?? 1);

        return $this->create($data);
    }

    /**
     * Update a Product
     * @param int|string $key Product ID Or Uid
     * @param array $input Submitted Data
     * @return int Affected rows
     */
    public function modify(int|string $key, array $input): int
    {
        $product = $this->find($key);

        if ($product === null) {
            return 0;
        }

        $data = $this->fields($input);

        if (array_key_exists('product_slug', $data)) {
            $data['product_slug'] = $this->uniqueSlug(
                (string) $data['product_slug'],
                (string) ($data['product_name'] ?? $product['product_name']),
                (int) $product['pid']
            );
        }

        return $this->update((int) $product['pid'], $data);
    }

    /**
     * Store The Provisioning Module's Configuration
     *
     * serialize()d here: the model's `serialize` cast decodes on read but never
     * encodes on write, so an array handed to update() would store the word
     * "Array" and the module would silently lose its settings.
     * @param int $productId Product ID
     * @param array $config Module Configuration
     * @return int Affected rows
     */
    public function setModuleConfig(int $productId, array $config): int
    {
        return $this->update($productId, ['module_config' => serialize($config)]);
    }

    /**
     * Delete a Product
     *
     * Refuses while a client still has a service on it: the service would lose
     * the description of what it actually is.
     * @param int|string $key Product ID Or Uid
     * @return int Affected rows
     * @throws RuntimeException
     */
    public function remove(int|string $key): int
    {
        $product = $this->find($key);

        if ($product === null) {
            return 0;
        }

        $id = (int) $product['pid'];
        $services = (new ClientServiceModel())->where(['product_relid' => $id])->count();

        if ($services > 0) {
            throw new RuntimeException(
                "{$services} client service(s) use this product. Deactivate it instead of deleting it."
            );
        }

        $affected = 0;

        $this->model()->transaction(function (ProductModel $m) use ($id, &$affected): void {
            (new ProductPricingModel())->where(['product_relid' => $id])->delete();

            $affected = $m->where([$m->id => $id])->delete();
        });

        return $affected;
    }

    /**
     * The Status Lookup Table This Resource Uses
     *
     * A method rather than the STATUSES constant, because a relay facade
     * forwards method calls and not constants - so a controller reaching this
     * through LBM\Service\* has no way to read the constant directly.
     * @return string
     */
    public function statusTable(): string
    {
        return self::STATUSES;
    }

    /**
     * The Id Of One Named Status
     * @param string $name Status Name. Example: 'active'
     * @return ?int Null when no status of that name exists
     */
    public function statusId(string $name): ?int
    {
        return Status::idOf(self::STATUSES, $name);
    }

    /**
     * The Status Choices a Form Offers
     * @return array
     */
    public function statuses(): array
    {
        return Status::all(self::STATUSES);
    }

    ####################################################################################
    /*==================================== GROUPS ====================================*/
    ####################################################################################

    /**
     * Every Product Group
     * @param bool $activeOnly Only Groups Currently On Sale
     * @return array
     */
    public function groups(bool $activeOnly = false): array
    {
        $model = new ProductGroupModel();

        if ($activeOnly) {
            $model->where(['is_active' => 'yes']);
        }

        return $model->order('group_name', self::ASC)->get();
    }

    /**
     * Find One Group
     * @param int|string $key Group ID Or Uid
     * @return ?array
     */
    public function group(int|string $key): ?array
    {
        $model = new ProductGroupModel();
        $row = $this->key($model, $key)->first();

        return is_array($row) ? $row : null;
    }

    /**
     * Create Or Update a Group
     *
     * One method because the groups screen is a single form listing every group
     * with a blank row at the bottom - the same POST both edits and adds.
     * @param array $input Submitted Data
     * @param int|string|null $key Group ID Or Uid. Null creates
     * @return int The group ID
     */
    public function saveGroup(array $input, int|string|null $key = null): int
    {
        $model = new ProductGroupModel();

        $data = [
            'group_name'  =>  trim((string) ($input['group_name'] ?? '')),
            'description' =>  trim((string) ($input['description'] ?? '')),
            'is_active'   =>  $this->flag($input['is_active'] ?? 'no'),
        ];

        if ($key !== null && $key !== '' && $key !== 0) {
            $group = $this->group($key);

            if ($group !== null) {
                $id = (int) $group['group_id'];

                $data['group_slug'] = $this->uniqueGroupSlug(
                    (string) ($input['group_slug'] ?? $data['group_name']),
                    $id
                );
                $data['group_updated_at'] = $this->now();

                $model->where([$model->id => $id])->update($data);

                return $id;
            }
        }

        $data['group_slug'] = $this->uniqueGroupSlug(
            (string) ($input['group_slug'] ?? $data['group_name'])
        );
        $data[$model->uid] = Uid::make();
        $data['group_created_at'] = $this->now();
        $data['group_updated_at'] = $this->now();

        return (int) $model->insert($data);
    }

    /**
     * Delete a Group
     *
     * Refuses while it still holds products - a product whose group_relid points
     * at nothing disappears from every grouped listing without explanation.
     * @param int|string $key Group ID Or Uid
     * @return int Affected rows
     * @throws RuntimeException
     */
    public function removeGroup(int|string $key): int
    {
        $group = $this->group($key);

        if ($group === null) {
            return 0;
        }

        $id = (int) $group['group_id'];
        $products = $this->count(['group_relid' => $id]);

        if ($products > 0) {
            throw new RuntimeException(
                "This group holds {$products} product(s). Move them before deleting it."
            );
        }

        $model = new ProductGroupModel();

        return $model->where([$model->id => $id])->delete();
    }

    ####################################################################################
    /*=================================== PRICING ====================================*/
    ####################################################################################

    /**
     * Every Price For a Product
     * @param int $productId Product ID
     * @return array
     */
    public function pricing(int $productId): array
    {
        $model = new ProductPricingModel();

        return $model->where(['product_relid' => $productId])
            ->order('billing_cycle_relid', self::ASC)
            ->get();
    }

    /**
     * The Price For One Product, Currency And Billing Cycle
     * @param int $productId Product ID
     * @param int $currencyId Currency ID
     * @param int $cycleId Billing Cycle ID
     * @return ?array
     */
    public function price(int $productId, int $currencyId, int $cycleId): ?array
    {
        $row = (new ProductPricingModel())->where([
            'product_relid'       =>  $productId,
            'currency_relid'      =>  $currencyId,
            'billing_cycle_relid' =>  $cycleId,
            'is_active'           =>  'yes',
        ])->first();

        return is_array($row) ? $row : null;
    }

    /**
     * Set The Price For One Product, Currency And Billing Cycle
     *
     * Upsert rather than insert: the table has a unique index across those three
     * columns, so a second insert for the same combination is a duplicate-key
     * error rather than an update.
     * @param int $productId Product ID
     * @param int $currencyId Currency ID
     * @param int $cycleId Billing Cycle ID
     * @param int|float|string $price Recurring Price
     * @param int|float|string $setupFee Setup Fee
     * @param bool $active Whether The Price Is Offered
     * @return void
     */
    public function setPrice(
        int $productId,
        int $currencyId,
        int $cycleId,
        int|float|string $price,
        int|float|string $setupFee = '0',
        bool $active = true
    ): void {
        $model = new ProductPricingModel();

        $where = [
            'product_relid'       =>  $productId,
            'currency_relid'      =>  $currencyId,
            'billing_cycle_relid' =>  $cycleId,
        ];

        $existing = (new ProductPricingModel())->where($where)->first();

        $data = [
            'price'     =>  Money::round((string) $price),
            'setup_fee' =>  Money::round((string) $setupFee),
            'is_active' =>  $active ? 'yes' : 'no',
        ];

        if (is_array($existing)) {
            $data['price_updated_at'] = $this->now();

            $model->where([$model->id => (int) $existing['price_id']])->update($data);

            return;
        }

        $model->insert([
            ...$where,
            ...$data,
            $model->uid          =>  Uid::make(),
            'price_created_at'   =>  $this->now(),
            'price_updated_at'   =>  $this->now(),
        ]);
    }

    /**
     * Remove One Price
     * @param int|string $key Price ID Or Uid
     * @return int Affected rows
     */
    public function removePrice(int|string $key): int
    {
        $model = new ProductPricingModel();

        return $this->key($model, $key)->delete();
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * How a Product Can Be Charged For
     *
     * A method rather than the constant, because a relay facade forwards method
     * calls and not constants.
     * @return string[]
     */
    public function pricingModels(): array
    {
        return self::PRICING_MODELS;
    }

    /**
     * Reduce Submitted Input To Writable Columns
     * @param array $input Submitted Data
     * @return array
     */
    private function fields(array $input): array
    {
        $data = $this->nullable($this->only($input, self::FIELDS), self::NULLABLE);

        foreach (['stock_control', 'is_featured'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $data[$flag] = $this->flag($data[$flag]);
            }
        }

        if (isset($data['pricing_model']) && !in_array($data['pricing_model'], self::PRICING_MODELS, true)) {
            $data['pricing_model'] = 'recurring';
        }

        foreach (['setup_fee', 'tax_rate'] as $decimal) {
            if (array_key_exists($decimal, $data)) {
                $data[$decimal] = Money::round((string) $data[$decimal]);
            }
        }

        return $data;
    }

    /**
     * Build a Slug That Is Not Already Taken
     *
     * product_slug is unique, and a form that lets somebody type a duplicate and
     * then dies on the index is not a form - suffix it instead.
     * @param string $slug Requested Slug
     * @param string $fallback Text To Slugify When The Slug Is Blank
     * @param ?int $ignore Product ID To Exclude, When Editing
     * @return string
     */
    private function uniqueSlug(string $slug, string $fallback, ?int $ignore = null): string
    {
        $base = $this->slugify($slug !== '' ? $slug : $fallback);
        $candidate = $base;
        $suffix = 2;

        while ($this->slugTaken($candidate, $ignore)) {
            $candidate = $base . '-' . $suffix;
            $suffix++;
        }

        return $candidate;
    }

    /**
     * Whether a Product Slug Is Taken
     * @param string $slug Slug
     * @param ?int $ignore Product ID To Exclude
     * @return bool
     */
    private function slugTaken(string $slug, ?int $ignore = null): bool
    {
        $model = $this->model();
        $model->where(['product_slug' => $slug]);

        if ($ignore !== null) {
            $model->whereNot([$model->id => $ignore]);
        }

        return $model->count() > 0;
    }

    /**
     * Build a Group Slug That Is Not Already Taken
     * @param string $slug Requested Slug
     * @param ?int $ignore Group ID To Exclude
     * @return string
     */
    private function uniqueGroupSlug(string $slug, ?int $ignore = null): string
    {
        $base = $this->slugify($slug !== '' ? $slug : 'group');
        $candidate = $base;
        $suffix = 2;

        while ($this->groupSlugTaken($candidate, $ignore)) {
            $candidate = $base . '-' . $suffix;
            $suffix++;
        }

        return $candidate;
    }

    /**
     * Whether a Group Slug Is Taken
     * @param string $slug Slug
     * @param ?int $ignore Group ID To Exclude
     * @return bool
     */
    private function groupSlugTaken(string $slug, ?int $ignore = null): bool
    {
        $model = new ProductGroupModel();
        $model->where(['group_slug' => $slug]);

        if ($ignore !== null) {
            $model->whereNot([$model->id => $ignore]);
        }

        return $model->count() > 0;
    }

    /**
     * Turn Text Into a Slug
     * @param string $text Text
     * @return string
     */
    private function slugify(string $text): string
    {
        $slug = strtolower(trim($text));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        return $slug !== '' ? $slug : 'item';
    }
}
