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
use LBM\Model\ClientModel;
use LBM\Model\TransactionModel;
use LBM\Service\Money;
use LBM\Service\Status;

/**
 * The money ledger: payments, refunds, credits, chargebacks and reversals.
 *
 * Append-only in spirit. A payment that turns out to be wrong is corrected by
 * recording a refund or a reversal against it, never by editing or deleting the
 * original - an accounts history you can quietly rewrite is not a history.
 * delete() exists for the operator who has just typed the same payment twice,
 * and is guarded accordingly.
 *
 * Recording a payment also settles the invoice it names and, when it is a
 * credit, moves the client's balance. Those three writes belong together, which
 * is why nothing outside this class writes a transactions row.
 */
class Transaction extends Action
{
    /** @var string Status Lookup Table */
    public const STATUSES = 'transaction_statuses';

    /** @var string A Payment Taken */
    public const PAYMENT = 'payment';

    /** @var string Money Returned To The Client */
    public const REFUND = 'refund';

    /** @var string Credit Added To The Client's Balance */
    public const CREDIT = 'credit';

    /** @var string A Payment Reversed By The Bank */
    public const CHARGEBACK = 'chargeback';

    /** @var string A Payment Undone By Staff */
    public const REVERSAL = 'reversal';

    /** @var string[] Every Recognised Type */
    public const TYPES = [
        self::PAYMENT, self::REFUND, self::CREDIT, self::CHARGEBACK, self::REVERSAL,
    ];

    /** @var string[] Columns a Form May Write */
    public const FIELDS = [
        'client_relid', 'invoice_relid', 'currency_relid', 'gateway_relid',
        'transaction_ref', 'amount', 'fee', 'exchange_rate', 'type',
        'status_relid', 'description',
    ];

    public function model(): Model
    {
        return new TransactionModel();
    }

    protected function searchable(): array
    {
        return ['transaction_ref', 'description'];
    }

    protected function createdColumn(): ?string
    {
        return 'tx_created_at';
    }

    protected function updatedColumn(): ?string
    {
        return 'tx_updated_at';
    }

    ####################################################################################
    /*=================================== READING ====================================*/
    ####################################################################################

    /**
     * One Page Of Transactions, With The Client They Belong To
     * @param array $where Conditions
     * @param ?string $search Search Term
     * @param ?int $limit Rows Per Page
     * @return array
     */
    public function browseWithClients(array $where = [], ?string $search = null, ?int $limit = null): array
    {
        $transactions = new TransactionModel();
        $clients = new ClientModel();

        $t = $transactions->table;
        $c = $clients->table;

        $qualified = [];

        foreach ($where as $column => $value) {
            $key = str_contains((string) $column, '.') ? (string) $column : "{$t}.{$column}";
            $qualified[$key] = $value;
        }

        $counted = new TransactionModel();
        $this->conditions($counted, $qualified);

        $listed = new TransactionModel();
        $listed->select([
            "{$t}.*",
            "{$c}.first_name AS client_first_name",
            "{$c}.last_name AS client_last_name",
            "{$c}.company_name AS client_company_name",
        ])->join($c, "{$c}.{$clients->id}", '=', "{$t}.client_relid");

        $this->conditions($listed, $qualified);

        if ($search !== null && trim($search) !== '') {
            $term = '%' . trim($search) . '%';
            $columns = [
                "{$t}.transaction_ref" =>  $term,
                "{$t}.description"     =>  $term,
                "{$c}.first_name"      =>  $term,
                "{$c}.last_name"       =>  $term,
            ];

            $counted->join($c, "{$c}.{$clients->id}", '=', "{$t}.client_relid")
                ->whereGroup(static function (Model $group) use ($columns): void {
                    $group->where($columns, 'LIKE', 'OR');
                });

            $listed->whereGroup(static function (Model $group) use ($columns): void {
                $group->where($columns, 'LIKE', 'OR');
            });
        }

        return $this->paginate($listed, $counted, $limit, self::DESC);
    }

    /**
     * Every Transaction Against One Invoice
     * @param int $invoiceId Invoice ID
     * @return array
     */
    public function forInvoice(int $invoiceId): array
    {
        return $this->all(['invoice_relid' => $invoiceId], self::ASC);
    }

    /**
     * Every Transaction For One Client
     * @param int $clientId Client ID
     * @param ?int $limit Rows Per Page
     * @return array
     */
    public function browseForClient(int $clientId, ?int $limit = null): array
    {
        return $this->browse(['client_relid' => $clientId], null, $limit, self::DESC);
    }

    /**
     * Total Money Taken Over a Period
     *
     * Completed payments only - a pending or failed one is not income, and a
     * refund is subtracted rather than counted.
     * @param ?string $from Start Date. Y-m-d H:i:s
     * @param ?string $to End Date. Y-m-d H:i:s
     * @return string Decimal string
     */
    public function income(?string $from = null, ?string $to = null): string
    {
        $completed = Status::idOf(self::STATUSES, 'completed');

        $model = $this->model();
        $model->whereIn('type', [self::PAYMENT, self::REFUND]);

        if ($completed !== null) {
            $model->where(['status_relid' => $completed]);
        }

        if ($from !== null && $to !== null) {
            $model->between('tx_created_at', $from, $to);
        } elseif ($from !== null) {
            $model->where(['tx_created_at' => $from], '>=');
        } elseif ($to !== null) {
            $model->where(['tx_created_at' => $to], '<=');
        }

        $total = '0';

        // cursor() rather than get(): a year of payments is a lot of rows to
        // hold in memory only to add them up one at a time.
        foreach ($model->cursor() as $row) {
            $amount = (string) ($row['amount'] ?? '0');

            $total = ($row['type'] ?? '') === self::REFUND
                ? Money::sub($total, $amount)
                : Money::add($total, $amount);
        }

        return $total;
    }

    ####################################################################################
    /*=================================== WRITING ====================================*/
    ####################################################################################

    /**
     * Record a Payment
     *
     * The ledger row, the invoice's amount_paid and - when the payment settles
     * it - the invoice's status all move together. Splitting them would let a
     * payment exist that no invoice knows about.
     * @param array $input Submitted Data
     * @return int New Transaction ID
     * @throws RuntimeException
     */
    public function pay(array $input): int
    {
        $data = $this->fields($input);
        $data['type'] = self::PAYMENT;

        $amount = (string) ($data['amount'] ?? '0');

        if (Money::isZero($amount) || !Money::isGreater($amount, '0')) {
            throw new RuntimeException('A payment must be greater than zero.');
        }

        $data['status_relid'] = (int) ($data['status_relid'] ?? 0)
            ?: (Status::idOf(self::STATUSES, 'completed') ?? 1);

        $id = $this->create($data);

        $invoiceId = (int) ($data['invoice_relid'] ?? 0);

        if ($invoiceId > 0) {
            (new Invoice())->applyPayment($invoiceId, $amount);
        }

        return $id;
    }

    /**
     * Record a Refund Against An Existing Payment
     *
     * The refund is a new row referring to the same invoice and client, not an
     * edit of the payment: the original still happened, and both belong in the
     * history.
     * @param int|string $key Transaction ID Or Uid Of The Original Payment
     * @param int|float|string|null $amount Amount. Null refunds the whole payment
     * @param string $reason Description
     * @return int New Transaction ID
     * @throws RuntimeException
     */
    public function refund(int|string $key, int|float|string|null $amount = null, string $reason = ''): int
    {
        $original = $this->find($key);

        if ($original === null) {
            throw new RuntimeException('That transaction no longer exists.');
        }

        if (($original['type'] ?? '') !== self::PAYMENT) {
            throw new RuntimeException('Only a payment can be refunded.');
        }

        $paid = (string) ($original['amount'] ?? '0');
        $already = $this->refundedAgainst((int) $original['tx_id']);
        $remaining = Money::sub($paid, $already);

        $amount = $amount === null ? $remaining : (string) $amount;

        if (!Money::isGreater($amount, '0')) {
            throw new RuntimeException('A refund must be greater than zero.');
        }

        if (Money::isGreater($amount, $remaining)) {
            throw new RuntimeException(
                'That is more than is left to refund on this payment (' . Money::format($remaining) . ').'
            );
        }

        return $this->create([
            'client_relid'    =>  $original['client_relid'] ?? null,
            'invoice_relid'   =>  $original['invoice_relid'] ?? null,
            'currency_relid'  =>  $original['currency_relid'] ?? null,
            'gateway_relid'   =>  $original['gateway_relid'] ?? null,
            'transaction_ref' =>  $original['transaction_ref'] ?? null,
            'amount'          =>  Money::round($amount),
            'fee'             =>  '0',
            'exchange_rate'   =>  $original['exchange_rate'] ?? '1',
            'type'            =>  self::REFUND,
            'status_relid'    =>  Status::idOf(self::STATUSES, 'completed') ?? 1,
            'description'     =>  trim($reason) !== ''
                ? trim($reason)
                : 'Refund of transaction #' . $original['tx_id'],
        ]);
    }

    /**
     * Add Credit To a Client's Balance
     * @param int $clientId Client ID
     * @param int|float|string $amount Amount
     * @param string $description Description
     * @param ?int $currencyId Currency ID. Defaults to the client's own
     * @return int New Transaction ID
     */
    public function credit(
        int $clientId,
        int|float|string $amount,
        string $description = '',
        ?int $currencyId = null
    ): int {
        $client = (new Client())->find($clientId);

        $id = $this->create([
            'client_relid'   =>  $clientId,
            'invoice_relid'  =>  null,
            'currency_relid' =>  $currencyId ?? ($client['currency_relid'] ?? null),
            'amount'         =>  Money::round((string) $amount),
            'type'           =>  self::CREDIT,
            'status_relid'   =>  Status::idOf(self::STATUSES, 'completed') ?? 1,
            'description'    =>  trim($description) !== '' ? trim($description) : 'Credit added',
        ]);

        (new Client())->adjustCredit($clientId, (string) $amount);

        return $id;
    }

    /**
     * How Much Has Already Been Refunded Against a Payment
     * @param int $transactionId Original Payment ID
     * @return string Decimal string
     */
    public function refundedAgainst(int $transactionId): string
    {
        $original = $this->find($transactionId);

        if ($original === null) {
            return '0';
        }

        $invoiceId = $original['invoice_relid'] ?? null;

        if ($invoiceId === null) {
            return '0';
        }

        $rows = $this->all([
            'invoice_relid' =>  (int) $invoiceId,
            'type'          =>  self::REFUND,
        ]);

        return Money::sum(array_map(
            static fn(array $row): string => (string) ($row['amount'] ?? '0'),
            $rows
        ));
    }

    /**
     * Store Gateway Response Data On a Transaction
     *
     * serialize()d here rather than left to the model's `serialize` cast: casts
     * run on read only, so handing insert() or update() an array would store the
     * word "Array".
     * @param int $transactionId Transaction ID
     * @param array $data Gateway Data
     * @return int Affected rows
     */
    public function recordGatewayData(int $transactionId, array $data): int
    {
        return $this->update($transactionId, ['gateway_data' => serialize($data)]);
    }

    /**
     * Delete a Transaction
     *
     * For an operator undoing a mis-keyed entry, not for tidying history. A
     * payment that has already moved an invoice cannot be removed this way -
     * refund or reverse it instead, so the invoice moves back with it.
     * @param int|string $key Transaction ID Or Uid
     * @return int Affected rows
     * @throws RuntimeException
     */
    public function remove(int|string $key): int
    {
        $transaction = $this->find($key);

        if ($transaction === null) {
            return 0;
        }

        if (($transaction['type'] ?? '') === self::PAYMENT && !empty($transaction['invoice_relid'])) {
            throw new RuntimeException(
                'This payment has been applied to an invoice. Refund or reverse it instead of deleting it.'
            );
        }

        return $this->delete((int) $transaction['tx_id']);
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
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Every Kind Of Ledger Entry
     *
     * A method rather than the constant, because a relay facade forwards method
     * calls and not constants.
     * @return string[]
     */
    public function types(): array
    {
        return self::TYPES;
    }

    /**
     * Reduce Submitted Input To Writable Columns
     * @param array $input Submitted Data
     * @return array
     */
    private function fields(array $input): array
    {
        $data = $this->nullable(
            $this->only($input, self::FIELDS),
            ['invoice_relid', 'gateway_relid', 'transaction_ref', 'description']
        );

        if (isset($data['type']) && !in_array($data['type'], self::TYPES, true)) {
            unset($data['type']);
        }

        foreach (['amount', 'fee', 'exchange_rate'] as $money) {
            if (array_key_exists($money, $data)) {
                $data[$money] = Money::round((string) $data[$money]);
            }
        }

        return $data;
    }
}
