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
            'front_templates' =>  $this->templateChoices(FRONT),
            'admin_templates' =>  $this->templateChoices(ADMIN),
            'panel_templates' =>  $this->templateChoices(PANEL),
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
            'languages'   =>  language_choices(),
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
            'drivers'     =>  ['smtp' => 'SMTP', 'sendmail' => 'Sendmail', 'mail' => local('php_mail'), 'qmail' => 'qmail'],
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
            return $this->done('staff.settings.mail', local('enter_test_address'), false);
        }

        return $this->attempt(
            function () use ($to): void {
                Mail::queueTest($to);

                $this->log('settings.mail.test', "Queued a test message to {$to}.");
            },
            'staff.settings.mail',
            local('test_queued_for', $to)
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

        return $this->screen('settings-email-templates', local('email_templates'), [
            'tab'       =>  'templates',
            'templates' =>  $model->order('name', 'ASC')->get(),
        ]);
    }

    /**
     * Write a New Template
     *
     * The screen was list-and-edit only until now, so the set of templates was
     * whatever the seed had put there and nothing else. An operator who deleted
     * one could not get it back, and a module wanting its own message had
     * nowhere to put it.
     *
     * The slug is the only part that is not cosmetic: it is the name the code
     * asks for, so `Mail::queueTemplate('invoice-created')` finds a row only if
     * some row carries exactly that slug. Normalised here rather than trusted,
     * because an operator typing "Invoice Created" would otherwise create a
     * template that can never be found.
     * @return ?string
     */
    public function emailTemplateCreate(): ?string
    {
        if (Request::isPost()) {
            $input = Request::inputs();

            $slug = $this->templateSlug((string) ($input['slug'] ?? ''));
            $name = trim((string) ($input['name'] ?? ''));

            $this->require([
                'slug'    =>  local('slug_required'),
                'name'    =>  local('name_required'),
                'subject' =>  local('subject_required'),
                'body'    =>  local('message_cannot_be_empty'),
            ], $input);

            // Both columns are UNIQUE, so a duplicate would surface as a driver
            // error rather than something the operator can act on.
            if ($slug !== '' && (new EmailTemplateModel())->where(['slug' => $slug])->exists()) {
                Request::addError('slug', local('slug_taken', $slug));
            }

            if ($name !== '' && (new EmailTemplateModel())->where(['name' => $name])->exists()) {
                Request::addError('name', local('template_name_taken', $name));
            }

            if (Request::errors() === []) {
                $uid = lbm_uid();

                (new EmailTemplateModel())->insert([
                    'uid'        =>  $uid,
                    'slug'       =>  $slug,
                    'name'       =>  $name,
                    'subject'    =>  trim((string) $input['subject']),
                    'body'       =>  (string) $input['body'],
                    'variables'  =>  json_encode(new \stdClass(), JSON_PRETTY_PRINT),
                    'is_active'  =>  empty($input['is_active']) || $input['is_active'] === 'false' ? 'no' : 'yes',
                    'created_at' =>  date('Y-m-d H:i:s'),
                    'updated_at' =>  date('Y-m-d H:i:s'),
                ]);

                $this->log('settings.template.created', 'Added the ' . $name . ' email template.');

                return $this->done(
                    'staff.settings.template',
                    local('template_added'),
                    true,
                    ['template' => $uid]
                );
            }
        }

        return $this->screen('settings-email-template-new', local('add_template'), [
            'tab' =>  'templates',
        ]);
    }

    /**
     * Delete One Template
     *
     * No guard on whether the application still sends this slug. A template the
     * code asks for and cannot find is already a handled case - it is recorded
     * on the activity log and the surrounding work carries on - and the seed
     * puts a missing core template back on the next app:migrate. Refusing the
     * delete would leave an operator stuck with a row they cannot remove.
     * @param string $template Template Uid
     * @return ?string
     */
    public function emailTemplateDelete(string $template): ?string
    {
        $model = new EmailTemplateModel();
        $row = $this->record(
            is_array($found = $model->where([$model->uid => $template])->first()) ? $found : null,
            'email template'
        );

        (new EmailTemplateModel())->where(['et_id' => (int) $row['et_id']])->delete();

        $this->log('settings.template.deleted', 'Deleted the ' . $row['name'] . ' email template.');

        return $this->done('staff.settings.templates', local('template_deleted'));
    }

    /**
     * Reduce a Typed Slug To What The Code Can Look Up
     *
     * Lowercase, hyphen-separated, nothing else. The slug is an identifier the
     * application matches exactly, never something a customer reads, so it is
     * normalised rather than validated - rejecting "Invoice Created" would be
     * correct but unhelpful when turning it into `invoice-created` is what the
     * operator meant.
     * @param string $slug Typed Slug
     * @return string
     */
    private function templateSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';

        return trim($slug, '-');
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
                'subject' =>  local('subject_required'),
                'body'    =>  local('message_cannot_be_empty'),
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
                    local('template_saved'),
                    true,
                    ['template' => $row['uid']]
                );
            }
        }

        return $this->screen('settings-email-template', $row['name'], [
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

        return $this->screen('settings-statuses', 'Statuses', [
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
        return $this->screen('settings-' . $group, $title, array_merge([
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

        return $this->done($route, local('settings_saved'));
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
            return $this->done('staff.settings.statuses', local('nothing_to_save'), false);
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

        return $this->done('staff.settings.statuses', local('statuses_saved'));
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
            'support_priorities'      =>  local('ticket_priorities'),
            'email_queue_statuses'    =>  local('email_queue'),
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
     * Installed Template Choices For One Area
     *
     * Each area has its own directory of templates, so the admin select must
     * not offer a client template - picking one would render nothing and fall
     * back to bootstrap without saying why.
     * @param string $area FRONT, ADMIN or PANEL
     * @return array<string,string>
     */
    private function templateChoices(string $area): array
    {
        $choices = [];

        foreach (glob(APP_PATH . '/template/' . $area . '/*', GLOB_ONLYDIR) ?: [] as $path) {
            $name = basename($path);
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
