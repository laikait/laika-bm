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
use LBM\Service\Invoice;
use LBM\Service\Money;
use LBM\Service\Order;
use LBM\Service\Support;
use LBM\Service\Transaction;

/**
 * Reports.
 *
 * Three questions, one screen each: what came in, what was ordered, what
 * support is dealing with. Every one is bounded by a date range read from the
 * query string, so a report is a URL - which is what makes it something you can
 * bookmark, or send to somebody who asked.
 *
 * The figures come from the actions, not from queries written here. A report
 * that counted differently from the screen it summarises would be worse than no
 * report at all.
 */
class ReportController extends AdminController
{
    /** @var int How Many Months a Trend Covers */
    public const MONTHS = 12;

    protected function nav(): string
    {
        return 'reports';
    }

    /**
     * The Report Index
     * @return string
     */
    public function index(): string
    {
        return $this->screen('reports', 'Reports', [
            'range' =>  $this->range(),
        ]);
    }

    /**
     * Money In
     * @return string
     */
    public function income(): string
    {
        $range = $this->range();
        $months = $this->monthly();

        return $this->screen('report-income', 'Income', [
            'range'   =>  $range,
            'total'   =>  Transaction::income($range['from'], $range['to']),
            'months'  =>  $months,
            'peak'    =>  $this->peak($months),
            'unpaid'  =>  $this->unpaidTotal(),
            'counts'  =>  [
                'payments' =>  Transaction::count(['type' => 'payment']),
                'refunds'  =>  Transaction::count(['type' => 'refund']),
                'invoices' =>  Invoice::count(),
            ],
        ]);
    }

    /**
     * Orders
     * @return string
     */
    public function orders(): string
    {
        $range = $this->range();

        return $this->screen('report-orders', 'Orders', [
            'range'    =>  $range,
            'total'    =>  Order::count(),
            'statuses' =>  $this->countByStatus(Order::statuses(), static fn(int $id): int => Order::count(['status_relid' => $id])),
        ]);
    }

    /**
     * Support
     * @return string
     */
    public function tickets(): string
    {
        $range = $this->range();

        return $this->screen('report-tickets', 'Support', [
            'range'       =>  $range,
            'total'       =>  Support::count(),
            'open'        =>  Support::openCount(),
            'statuses'    =>  $this->countByStatus(Support::statuses(), static fn(int $id): int => Support::count(['status_relid' => $id])),
            'priorities'  =>  $this->countByStatus(Support::priorities(), static fn(int $id): int => Support::count(['priority_relid' => $id])),
            'departments' =>  $this->byDepartment(),
        ]);
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * The Date Range This Report Covers
     *
     * Defaults to the current month, which is what somebody opening a report
     * without choosing anything almost always means.
     * @return array{from:string,to:string,from_day:string,to_day:string}
     */
    private function range(): array
    {
        $from = trim((string) Request::input('from', ''));
        $to = trim((string) Request::input('to', ''));

        $fromDay = $from !== '' && strtotime($from) !== false
            ? date('Y-m-d', (int) strtotime($from))
            : date('Y-m-01');

        $toDay = $to !== '' && strtotime($to) !== false
            ? date('Y-m-d', (int) strtotime($to))
            : date('Y-m-t');

        return [
            'from'     =>  $fromDay . ' 00:00:00',
            'to'       =>  $toDay . ' 23:59:59',
            'from_day' =>  $fromDay,
            'to_day'   =>  $toDay,
        ];
    }

    /**
     * Income Month By Month
     *
     * Twelve queries rather than one grouped by month: GROUP BY on a date needs
     * a driver-specific date function, and the no-raw-SQL rule means every one
     * of them would have to be written per grammar. Twelve indexed range counts
     * on a table this size is not the bottleneck anybody will hit first.
     * @return array<int,array{label:string,total:string}>
     */
    private function monthly(): array
    {
        $months = [];

        for ($back = self::MONTHS - 1; $back >= 0; $back--) {
            $start = date('Y-m-01 00:00:00', strtotime("-{$back} months"));
            $end = date('Y-m-t 23:59:59', strtotime("-{$back} months"));

            $months[] = [
                'label' =>  date('M Y', strtotime($start)),
                'total' =>  Transaction::income($start, $end),
            ];
        }

        return $months;
    }

    /**
     * The Tallest Bar, So The Chart Can Be Scaled To It
     * @param array $months Monthly Totals
     * @return string
     */
    private function peak(array $months): string
    {
        $peak = '0';

        foreach ($months as $month) {
            $peak = Money::max($peak, $month['total']);
        }

        return $peak;
    }

    /**
     * How Much Is Still Owed Across Every Unsettled Invoice
     * @return string
     */
    private function unpaidTotal(): string
    {
        $model = Invoice::model();
        $excluded = Invoice::settledStatusIds();

        if ($excluded !== []) {
            $model->whereNotIn('status_relid', $excluded);
        }

        $total = '0';

        foreach ($model->cursor() as $row) {
            $total = Money::add($total, Invoice::balance($row));
        }

        return $total;
    }

    /**
     * Count Rows Against Each Status
     * @param array $statuses Status Rows
     * @param callable $count Counter, Given a Status ID
     * @return array
     */
    private function countByStatus(array $statuses, callable $count): array
    {
        $rows = [];
        $total = 0;

        foreach ($statuses as $status) {
            $number = $count((int) $status['id']);

            if ($number === 0) {
                continue;
            }

            $total += $number;

            $rows[] = [
                'label' =>  status_label((string) $status['name']),
                'color' =>  (string) $status['color'],
                'count' =>  $number,
            ];
        }

        foreach ($rows as $i => $row) {
            $rows[$i]['percent'] = $total > 0 ? round($row['count'] / $total * 100, 1) : 0;
        }

        return $rows;
    }

    /**
     * Tickets Per Department
     * @return array
     */
    private function byDepartment(): array
    {
        $rows = [];

        foreach (Support::departments() as $department) {
            $id = (int) $department['dep_id'];

            $rows[] = [
                'label' =>  (string) $department['dep_name'],
                'count' =>  Support::count(['department_relid' => $id]),
            ];
        }

        return $rows;
    }
}
