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
use LBM\Service\Client;
use LBM\Service\Invoice;
use LBM\Service\Money;
use LBM\Service\Order;
use LBM\Service\Staff;
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

    /** @var int How Many Feedback Comments a Summary Screen Shows */
    public const COMMENTS = 20;

    /**
     * @var int The Oldest Year The Annual Report Offers
     *
     * A floor rather than the first invoice's year: finding that would mean a
     * query on every render of a screen whose year list nobody reads past the
     * top two entries.
     */
    public const EARLIEST_YEAR = 2020;

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
     * A Year Against The One Before It
     *
     * Calendar years rather than the last twelve months, because that is the
     * comparison somebody asking for annual sales means: January against
     * January. The month-by-month view on income() answers the other question
     * and stays where it is.
     * @return string
     */
    public function annual(): string
    {
        $year = (int) Request::input('year', date('Y'));

        // A hand-typed year outside anything the install could hold would
        // produce twelve empty months and look like a bug in the report.
        if ($year < self::EARLIEST_YEAR || $year > (int) date('Y')) {
            $year = (int) date('Y');
        }

        $current = $this->calendarMonths($year);
        $previous = $this->calendarMonths($year - 1);

        return $this->screen('report-annual', 'Annual income', [
            'year'      =>  $year,
            'previous'  =>  $year - 1,
            'years'     =>  range((int) date('Y'), self::EARLIEST_YEAR),
            'months'    =>  $current,
            'compare'   =>  $previous,
            'peak'      =>  Money::max($this->peak($current), $this->peak($previous)),
            'total'     =>  $this->sum($current),
            'total_previous' => $this->sum($previous),
        ]);
    }

    /**
     * New Clients
     * @return string
     */
    public function clients(): string
    {
        $range = $this->range();
        $months = $this->clientMonths();

        return $this->screen('report-clients', 'New clients', [
            'range'   =>  $range,
            'total'   =>  Client::count(),
            'in_range' =>  Client::countBetween($range['from'], $range['to']),
            'months'  =>  $months,
            'peak'    =>  max(array_column($months, 'count') ?: [0]),
            'statuses' =>  $this->countByStatus(Client::statuses(), static fn(int $id): int => Client::count(['status_relid' => $id])),
        ]);
    }

    /**
     * How Support Is Doing
     *
     * Bounded by the range like every other report, and deliberately reported
     * per staff member rather than as one number: an average response time
     * across a team says nothing anybody can act on.
     * @return string
     */
    public function performance(): string
    {
        $range = $this->range();

        return $this->screen('report-performance', 'Performance', [
            'range' =>  $range,
            'staff' =>  $this->staffPerformance($range['from'], $range['to']),
        ]);
    }

    /**
     * What Clients Thought Of The Support They Got
     * @return string
     */
    public function feedback(): string
    {
        $range = $this->range();
        $summary = Support::feedbackSummary($range['from'], $range['to']);

        return $this->screen('report-feedback', 'Ticket feedback', [
            'range'    =>  $range,
            'summary'  =>  $summary,
            'spread'   =>  $this->ratingSpread($summary),
            'comments' =>  $this->recentComments($range['from'], $range['to']),
            'ratings'  =>  Support::ratings(),
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
     * One Calendar Year, Month By Month
     *
     * Twelve range queries, same as monthly(), and for the same reason: GROUP BY
     * on a date needs a driver-specific function and the no-raw-SQL rule would
     * mean writing one per grammar.
     * @param int $year Four-Digit Year
     * @return array<int,array{label:string,total:string}>
     */
    private function calendarMonths(int $year): array
    {
        $months = [];

        for ($month = 1; $month <= 12; $month++) {
            $start = sprintf('%04d-%02d-01 00:00:00', $year, $month);
            $end = date('Y-m-t 23:59:59', (int) strtotime($start));

            $months[] = [
                'label' =>  date('M', (int) strtotime($start)),
                'total' =>  Transaction::income($start, $end),
            ];
        }

        return $months;
    }

    /**
     * New Clients Month By Month
     * @return array<int,array{label:string,count:int}>
     */
    private function clientMonths(): array
    {
        $months = [];

        for ($back = self::MONTHS - 1; $back >= 0; $back--) {
            $start = date('Y-m-01 00:00:00', strtotime("-{$back} months"));
            $end = date('Y-m-t 23:59:59', strtotime("-{$back} months"));

            $months[] = [
                'label' =>  date('M Y', strtotime($start)),
                'count' =>  Client::countBetween($start, $end),
            ];
        }

        return $months;
    }

    /**
     * Each Staff Member's Support Workload And What It Earned Them
     *
     * Built from the tickets in the window rather than from the staff list, so
     * a ticket nobody was assigned to still counts somewhere - it lands under
     * the unassigned row. Dropping those would make this disagree with the
     * ticket report over the same range, which is the kind of discrepancy that
     * costs an afternoon to explain.
     * @param string $from Datetime, Inclusive
     * @param string $to Datetime, Inclusive
     * @return array
     */
    private function staffPerformance(string $from, string $to): array
    {
        $tickets = Support::ticketsBetween($from, $to);

        if ($tickets === []) {
            return [];
        }

        $replies = Support::firstStaffReplyAt(array_column($tickets, 'ticket_id'));
        $ratings = Support::feedbackByStaff($from, $to);

        $rows = [];

        foreach ($tickets as $ticket) {
            $key = (int) ($ticket['assigned_staff_relid'] ?? 0);
            $id = (int) $ticket['ticket_id'];

            $rows[$key] ??= [
                'staff'     =>  $key > 0 ? Staff::find($key) : null,
                'tickets'   =>  0,
                'closed'    =>  0,
                'answered'  =>  0,
                'wait'      =>  0.0,
            ];

            $rows[$key]['tickets']++;

            if (Support::isClosed($ticket)) {
                $rows[$key]['closed']++;
            }

            // Only tickets staff actually answered contribute to the average.
            // Counting an unanswered one as zero wait would reward ignoring it.
            if (isset($replies[$id])) {
                $opened = strtotime((string) $ticket['ticket_created_at']);
                $answered = strtotime($replies[$id]);

                if ($opened !== false && $answered !== false && $answered >= $opened) {
                    $rows[$key]['answered']++;
                    $rows[$key]['wait'] += ($answered - $opened) / 3600;
                }
            }
        }

        foreach ($rows as $key => $row) {
            $rating = $ratings[$key === 0 ? '' : $key] ?? null;

            $rows[$key]['response'] = $row['answered'] > 0
                ? round($row['wait'] / $row['answered'], 1)
                : null;

            $rows[$key]['rated'] = $rating['count'] ?? 0;
            $rows[$key]['rating'] = ($rating['count'] ?? 0) > 0
                ? round($rating['sum'] / $rating['count'], 2)
                : null;
        }

        // Busiest first - that is the row somebody opening this screen is
        // looking for, and it puts the unassigned pile where it gets noticed.
        uasort($rows, static fn(array $a, array $b): int => $b['tickets'] <=> $a['tickets']);

        return $rows;
    }

    /**
     * The Rating Spread, As Bars
     * @param array $summary feedbackSummary() Output
     * @return array
     */
    private function ratingSpread(array $summary): array
    {
        $rows = [];
        $total = (int) $summary['count'];

        foreach ($summary['spread'] as $rating => $count) {
            $rows[] = [
                'rating'  =>  (int) $rating,
                'count'   =>  (int) $count,
                'percent' =>  $total > 0 ? round($count / $total * 100, 1) : 0,
            ];
        }

        return array_reverse($rows);
    }

    /**
     * The Comments People Left, Newest First
     *
     * A rating is a number; the comment beside it is the part that says why.
     * Capped, because this is a summary screen and not the place to read a
     * year of them.
     * @param string $from Datetime, Inclusive
     * @param string $to Datetime, Inclusive
     * @return array
     */
    private function recentComments(string $from, string $to): array
    {
        $rows = [];

        foreach (Support::feedbackBetween($from, $to) as $row) {
            if (trim((string) ($row['comment'] ?? '')) === '') {
                continue;
            }

            $rows[] = $row;
        }

        return array_slice(array_reverse($rows), 0, self::COMMENTS);
    }

    /**
     * Add Up a Set Of Monthly Totals
     * @param array $months Monthly Totals
     * @return string
     */
    private function sum(array $months): string
    {
        $total = '0';

        foreach ($months as $month) {
            $total = Money::add($total, $month['total']);
        }

        return $total;
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
