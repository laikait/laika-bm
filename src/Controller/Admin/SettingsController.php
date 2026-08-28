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

use DateTimeZone;
use Laika\Model\Model;
use Laika\Service\Request;
use LBM\Model\EmailTemplateModel;
use LBM\Service\Currency;
use LBM\Service\Mail;
use LBM\Service\Setting;
use LBM\Service\Status;

/**
 * Settings - everything stored in the `options` table (instruction 14).
 *
 * One method per tab, each of them GET to render and POST to save. Every save
 * ends in a redirect rather than a re-render, and that is not stylistic:
 * option() memoises per key for the whole request, so a re-render would show
 * the value it had just replaced and look as though the save had failed.
 *
 * Which keys a tab owns is declared on LBM\Action\Setting, not here. A POST that
 * carried mail_password to the general tab writes nothing, because the action
 * only ever saves keys in the group it was asked for.
 */
class SettingsController extends AdminController
{
    protected function nav(): string
    {
        return 'settings';
    }

    ####################################################################################
    /*=================================== GENERAL ====================================*/
    ####################################################################################

    /**
     * Company And Appearance
     * @return ?string
     */
    public function general(): ?string
    {
        if (Request::isPost()) {
            return $this->save('general', 'staff.settings');
        }

        return $this->tab('general', 'Settings', [
            'templates' =>  $this->templateChoices(),
        ]);
    }

    /**
     * Language, Formats And Currency
     * @return ?string
     */
    public function localisation(): ?string
    {
        if (Request::isPost()) {
            return $this->save('localisation', 'staff.settings.localisation');
        }

        return $this->tab('localisation', 'Localisation', [
            'timezones'   =>  $this->timezoneChoices(),
            'currencies'  =>  $this->currencyChoices(),
            'dates'       =>  $this->dateFormats(),
            'datetimes'   =>  $this->dateTimeFormats(),
            'times'       =>  $this->timeFormats(),
        ]);
    }

    /**
     * Invoice And Order Numbering, Terms, Late Fees
     * @return ?string
     */
    public function billing(): ?string
    {
        if (Request::isPost()) {
            return $this->save('billing', 'staff.settings.billing');
        }

        return $this->tab('billing', 'Billing');
    }

    /**
     * Sessions, Passwords And Registration
     * @return ?string
     */
    public function security(): ?string
    {
        if (Request::isPost()) {
            return $this->save('security', 'staff.settings.security');
        }

        return $this->tab('security', 'Security');
    }

    ####################################################################################
    /*===================================== MAIL =====================================*/
    ####################################################################################

    /**
     * SMTP Settings
     * @return ?string
     */
    public function mail(): ?string
    {
        if (Request::isPost()) {
            return $this->save('mail', 'staff.settings.mail');
        }

        return $this->tab('mail', 'Mail', [
            'drivers'     =>  ['smtp' => 'SMTP', 'sendmail' => 'Sendmail', 'mail' => 'PHP mail()', 'qmail' => 'qmail'],
            'encryptions' =>  ['tls' => 'TLS', 'ssl' => 'SSL', '' => 'None'],
            'queued'      =>  count(Mail::pending()),
        ]);
    }

    /**
     * Send a Test Message
     *
     * Queued rather than sent inline, deliberately: the point of the test is to
     * prove the path a real notification takes, and a test that bypassed the
     * queue would pass while every actual email sat unsent.
     * @return ?string
     */
    public function mailTest(): ?string
    {
        $to = trim((string) Request::input('to', ''));
        $to = $to !== '' ? $to : (string) option('app_email', '');

        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return $this->done('staff.settings.mail', 'Enter an address to send the test to.', false);
        }

        return $this->attempt(
            function () use ($to): void {
                Mail::queueTest($to);

                $this->log('settings.mail.test', "Queued a test message to {$to}.");
            },
            'staff.settings.mail',
            "Test message queued for {$to}. Run the worker to send it."
        );
    }

    ####################################################################################
    /*================================ EMAIL TEMPLATES ===============================*/
    ####################################################################################

    /**
     * The Template List
     * @return string
     */
    public function emailTemplates(): string
    {
        $model = new EmailTemplateModel();

        return $this->screen('admin/settings/email-templates', 'Email templates', [
            'tab'       =>  'templates',
            'templates' =>  $model->order('name', 'ASC')->get(),
        ]);
    }

    /**
     * Edit One Template
     * @param string $template Template Uid
     * @return ?string
     */
    public function emailTemplate(string $template): ?string
    {
        $model = new EmailTemplateModel();
        $row = $this->record(
            is_array($found = $model->where([$model->uid => $template])->first()) ? $found : null,
            'email template'
        );

        if (Request::isPost()) {
            $input = Request::inputs();

            $this->require([
                'subject' =>  'A subject is required.',
                'body'    =>  'The message cannot be empty.',
            ], $input);

            if (Request::errors() === []) {
                (new EmailTemplateModel())
                    ->where([$model->id => (int) $row['et_id']])
                    ->update([
                        'subject'    =>  trim((string) $input['subject']),
                        'body'       =>  (string) $input['body'],
                        'is_active'  =>  empty($input['is_active']) || $input['is_active'] === 'false' ? 'no' : 'yes',
                        'updated_at' =>  date('Y-m-d H:i:s'),
                    ]);

                $this->log('settings.template.updated', 'Updated the ' . $row['name'] . ' email template.');

                return $this->done(
                    'staff.settings.template',
                    'Template saved.',
                    true,
                    ['template' => $row['uid']]
                );
            }
        }

        return $this->screen('admin/settings/email-template', $row['name'], [
            'tab'      =>  'templates',
            'template' =>  $row,
        ]);
    }

    ####################################################################################
    /*=================================== STATUSES ===================================*/
    ####################################################################################

    /**
     * Status Names And Colours (instruction 7)
     *
     * Every status column has a lookup table carrying a name and a colour, and
     * the colour is what every pill in the application renders from - so
     * recolouring "overdue" here changes it everywhere without a template edit.
     * @return ?string
     */
    public function statuses(): ?string
    {
        if (Request::isPost()) {
            return $this->saveStatuses();
        }

        return $this->screen('admin/settings/statuses', 'Statuses', [
            'tab'    =>  'statuses',
            'tables' =>  $this->statusTables(),
        ]);
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Render One Settings Tab
     * @param string $group Group Name
     * @param string $title Page Title
     * @param array $vars Extra Variables
     * @return string
     */
    private function tab(string $group, string $title, array $vars = []): string
    {
        return $this->screen('admin/settings/' . $group, $title, array_merge([
            'tab'      =>  $group,
            'settings' =>  Setting::group($group),
        ], $vars));
    }

    /**
     * Save One Settings Tab
     * @param string $group Group Name
     * @param string $route Route To Return To
     * @return ?string
     */
    private function save(string $group, string $route): ?string
    {
        $written = Setting::saveGroup($group, Request::inputs());

        $this->log('settings.saved', "Saved {$written} {$group} setting(s).");

        return $this->done($route, 'Settings saved.');
    }

    /**
     * Save Every Status Name And Colour That Changed
     * @return ?string
     */
    private function saveStatuses(): ?string
    {
        $input = Request::inputs();
        $rows = $input['status'] ?? [];

        if (!is_array($rows)) {
            return $this->done('staff.settings.statuses', 'Nothing to save.', false);
        }

        $saved = 0;

        foreach ($rows as $table => $entries) {
            if (!is_array($entries) || !array_key_exists((string) $table, $this->statusTables())) {
                continue;
            }

            $model = (new Model())->table((string) $table);
            $columns = $this->statusColumns((string) $table);

            if ($columns === null) {
                continue;
            }

            foreach ($entries as $id => $values) {
                $name = trim((string) ($values['name'] ?? ''));
                $color = trim((string) ($values['color'] ?? ''));

                if ($name === '') {
                    continue;
                }

                (new Model())->table((string) $table)
                    ->where([$columns['id'] => (int) $id])
                    ->update([
                        $columns['name']  =>  $name,
                        $columns['color'] =>  $color !== '' ? $color : '#6c757d',
                    ]);

                $saved++;
            }
        }

        // The support class holds every lookup table for the request, so without
        // this the pills on the next render would still show the old colours.
        Status::flush();

        $this->log('settings.statuses.saved', "Updated {$saved} status row(s).");

        return $this->done('staff.settings.statuses', 'Statuses saved.');
    }

    /**
     * The Status Lookup Tables, With Their Rows
     * @return array<string,array>
     */
    private function statusTables(): array
    {
        $tables = [
            'client_statuses'         =>  'Clients',
            'client_service_statuses' =>  'Services',
            'invoice_statuses'        =>  'Invoices',
            'order_statuses'          =>  'Orders',
            'transaction_statuses'    =>  'Transactions',
            'product_statuses'        =>  'Products',
            'domain_statuses'         =>  'Domains',
            'server_statuses'         =>  'Servers',
            'staff_statuses'          =>  'Staff',
            'support_ticket_statuses' =>  'Tickets',
            'support_priorities'      =>  'Ticket priorities',
            'email_queue_statuses'    =>  'Email queue',
        ];

        $out = [];

        foreach ($tables as $table => $label) {
            $rows = Status::all($table);

            // A table with no rows is one the seed did not create on this
            // install - listing it with nothing under it would only puzzle
            // somebody looking for the statuses they can actually edit.
            if ($rows === []) {
                continue;
            }

            $out[$table] = ['label' => $label, 'rows' => $rows];
        }

        return $out;
    }

    /**
     * Which Columns a Status Table Uses
     *
     * Every one names them differently - status_name, priority_name - so the
     * columns are read off an actual row rather than guessed.
     * @param string $table Table Name
     * @return ?array{id:string,name:string,color:string}
     */
    private function statusColumns(string $table): ?array
    {
        $row = (new Model())->table($table)->limit(1)->get()[0] ?? null;

        if (!is_array($row)) {
            return null;
        }

        $columns = ['id' => null, 'name' => null, 'color' => null];

        foreach (array_keys($row) as $column) {
            if (str_ends_with($column, '_id') && $columns['id'] === null) {
                $columns['id'] = $column;
            } elseif (str_ends_with($column, '_name')) {
                $columns['name'] = $column;
            } elseif (str_ends_with($column, '_color')) {
                $columns['color'] = $column;
            }
        }

        if (in_array(null, $columns, true)) {
            return null;
        }

        /** @var array{id:string,name:string,color:string} $columns */
        return $columns;
    }

    /**
     * Installed Template Choices
     * @return array<string,string>
     */
    private function templateChoices(): array
    {
        $choices = [];

        foreach (glob(APP_PATH . '/template/*', GLOB_ONLYDIR) ?: [] as $path) {
            $name = basename($path);

            // `assets` is where theme CSS and JS are served from, not a theme.
            if ($name === 'assets') {
                continue;
            }

            $choices[$name] = ucfirst($name);
        }

        return $choices !== [] ? $choices : ['bootstrap' => 'Bootstrap'];
    }

    /**
     * Timezone Choices
     * @return array<string,string>
     */
    private function timezoneChoices(): array
    {
        $zones = ['UTC' => 'UTC'];

        foreach (DateTimeZone::listIdentifiers() as $zone) {
            $zones[$zone] = $zone;
        }

        return $zones;
    }

    /**
     * Currency Choices, By ISO Code
     *
     * The default currency option stores the code rather than the id, so that a
     * database restored into another install still names something meaningful.
     * @return array<string,string>
     */
    private function currencyChoices(): array
    {
        $choices = [];

        foreach (Currency::listing(true) as $row) {
            $code = (string) $row['currency_code'];
            $choices[$code] = trim($code . ' ' . ($row['prefix_symbol'] ?? ''));
        }

        return $choices;
    }

    /**
     * Date Format Choices, Labelled With What They Produce
     * @return array<string,string>
     */
    private function dateFormats(): array
    {
        return $this->labelled(['Y-m-d', 'd/m/Y', 'm/d/Y', 'd M Y', 'M d, Y', 'd-m-Y']);
    }

    /**
     * Date And Time Format Choices
     * @return array<string,string>
     */
    private function dateTimeFormats(): array
    {
        return $this->labelled(['Y-m-d H:i', 'Y-m-d h:i A', 'd/m/Y H:i', 'm/d/Y h:i A', 'd M Y H:i']);
    }

    /**
     * Time Format Choices
     * @return array<string,string>
     */
    private function timeFormats(): array
    {
        return $this->labelled(['H:i', 'H:i:s', 'h:i A', 'h:i a']);
    }

    /**
     * Label Each Format With What It Produces
     *
     * "Y-m-d" means nothing to most people; "2026-08-28" does.
     * @param string[] $formats Formats
     * @return array<string,string>
     */
    private function labelled(array $formats): array
    {
        $now = new \DateTimeImmutable('now', new DateTimeZone('UTC'));
        $choices = [];

        foreach ($formats as $format) {
            $choices[$format] = $now->format($format);
        }

        return $choices;
    }
}
