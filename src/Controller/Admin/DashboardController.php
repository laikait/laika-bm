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

use LBM\Service\Activity;
use LBM\Service\Client;
use LBM\Service\Invoice;
use LBM\Service\Money;
use LBM\Service\Order;
use LBM\Service\Support;
use LBM\Service\Transaction;
use LBM\Support\Health;

/**
 * The admin dashboard.
 *
 * Deliberately a small number of counts rather than a wall of charts. Each one
 * answers a question somebody actually opens this page to ask: how much am I
 * owed, what has come in this month, what is waiting for me.
 *
 * Every figure is a count or a sum the database can do on an index. Nothing
 * here walks a table in PHP, because the dashboard is the one screen that gets
 * hit on every single visit and it must stay cheap as the data grows.
 */
class DashboardController extends AdminController
{
    protected function nav(): string
    {
        return 'dashboard';
    }

    /**
     * The Dashboard
     * @return string
     */
    public function index(): string
    {
        return $this->screen('dashboard', 'Dashboard', [
            'cron'        =>  $this->cron(),
            'stats'       =>  $this->stats(),
            'revenue'     =>  $this->revenue(),
            'invoices'    =>  $this->invoiceMix(),
            'tickets'     =>  $this->recentTickets(),
            'unpaid'      =>  $this->unpaidInvoices(),
            'activities'  =>  Activity::recent(8),
        ]);
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * The Headline Counts
     * @return array
     */
    private function stats(): array
    {
        // statusId() rather than the actions' STATUSES constants: a relay facade
        // forwards method calls, not constants, so LBM\Service\Client::STATUSES
        // does not exist even though LBM\Action\Client::STATUSES does.
        $active = Client::statusId('active');
        $pending = Order::statusId('pending');

        return [
            'clients'  =>  [
                'label' =>  local('active_clients'),
                'value' =>  $active === null ? Client::count() : Client::count(['status_relid' => $active]),
                'icon'  =>  'clients',
                'route' =>  'staff.clients',
            ],
            'orders'   =>  [
                'label' =>  local('orders_awaiting_review'),
                'value' =>  $pending === null ? 0 : Order::count(['status_relid' => $pending]),
                'icon'  =>  'orders',
                'route' =>  'staff.orders',
            ],
            'tickets'  =>  [
                'label' =>  local('open_tickets_stat'),
                'value' =>  Support::openCount(),
                'icon'  =>  'tickets',
                'route' =>  'staff.tickets',
            ],
            'overdue'  =>  [
                'label' =>  local('overdue_invoices'),
                'value' =>  $this->overdueCount(),
                'icon'  =>  'invoices',
                'route' =>  'staff.invoices',
            ],
        ];
    }

    /**
     * Money In, This Month And Last
     *
     * Last month is there so the figure means something: revenue on its own is
     * a number, revenue next to the month before it is information.
     * @return array
     */
    private function revenue(): array
    {
        $thisMonth = Transaction::income(
            date('Y-m-01 00:00:00'),
            date('Y-m-t 23:59:59')
        );

        $lastMonth = Transaction::income(
            date('Y-m-01 00:00:00', strtotime('first day of last month')),
            date('Y-m-t 23:59:59', strtotime('last day of last month'))
        );

        return [
            'this_month'   =>  $thisMonth,
            'last_month'   =>  $lastMonth,
            'change'       =>  $this->change($lastMonth, $thisMonth),
            'outstanding'  =>  $this->outstanding(),
        ];
    }

    /**
     * How Invoices Are Split Across Their Statuses
     *
     * One count per status rather than one query per invoice. A status with
     * nothing in it is left out, so the counts returned always add up to the
     * total - the dashboard prints no separate total and relies on that.
     * @return array
     */
    private function invoiceMix(): array
    {
        $slices = [];
        $total = 0;

        foreach (Invoice::statuses() as $status) {
            $count = Invoice::count(['status_relid' => (int) $status['id']]);

            if ($count === 0) {
                continue;
            }

            $total += $count;

            $slices[] = [
                'label' =>  status_label((string) $status['name']),
                'color' =>  (string) $status['color'],
                'count' =>  $count,
            ];
        }

        return ['slices' => $slices, 'total' => $total];
    }

    /**
     * How Much Is Owed Across Every Unsettled Invoice
     *
     * The balance is total less payments and credit, so there is no column to
     * SUM - it has to be worked out per row. cursor() rather than get(),
     * because this runs on every dashboard load and an install with years of
     * history should not materialise the whole table to add it up.
     * @return string
     */
    private function outstanding(): string
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
     * How Many Invoices Are Past Due
     * @return int
     */
    private function overdueCount(): int
    {
        $overdue = Invoice::statusId('overdue');

        return $overdue === null ? 0 : Invoice::count(['status_relid' => $overdue]);
    }

    /**
     * The Invoices Worth Chasing First
     * @return array
     */
    private function unpaidInvoices(): array
    {
        return Invoice::browseUnpaid(6)['rows'];
    }

    /**
     * The Most Recent Tickets
     * @return array
     */
    private function recentTickets(): array
    {
        return Support::browseWithClients([], null, 6)['rows'];
    }

    /**
     * Whether Scheduled Tasks Are Actually Running
     *
     * "Cron was never set up" is the most common way a self-hosted billing
     * install quietly stops billing anybody, and it is invisible from every
     * other screen: nothing errors, invoices simply never appear and queued
     * email never leaves. So the dashboard says so, because the dashboard is
     * the screen an operator opens without being asked to.
     *
     * `cron_last_run` is written by LBM\Support\Cron on every invocation,
     * including one where a task failed - a run that happened and went wrong is
     * a different problem from a run that never happened, and conflating them
     * would send somebody to fix the wrong thing.
     *
     * Computed by LBM\Support\Health rather than here. This screen and the
     * automation utility both report on cron, and while they each worked it out
     * for themselves they could disagree - the dashboard calling it fine while
     * the status screen called it stale. One definition, two readers.
     * @return array
     */
    private function cron(): array
    {
        return (new Health())->cron();
    }

    /**
     * The Percentage Difference Between Two Figures
     * @param string $from Earlier Figure
     * @param string $to Later Figure
     * @return ?float Null when there is nothing to compare against
     */
    private function change(string $from, string $to): ?float
    {
        // No baseline means no percentage. "Up 100%" from zero says nothing.
        if (Money::isZero($from)) {
            return null;
        }

        $delta = Money::sub($to, $from);

        return round((float) Money::mul(Money::div($delta, $from), '100'), 1);
    }
}
