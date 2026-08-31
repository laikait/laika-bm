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

use Throwable;
use Laika\Service\Request;
use Laika\Service\Redirect;
use Laika\Core\Exceptions\HttpException;
use LBM\Controller\Controller;
use LBM\Pipeline\Auth;
use LBM\Service\Activity;

/**
 * Base for every admin screen.
 *
 * Controllers here are thin on purpose: read the request, call an action,
 * redirect or render. Anything that decides *what happens to the data* belongs
 * in LBM\Action\*, so the same operation is reachable from a CLI command or a
 * queue job without being written twice.
 *
 * Three conventions everything below relies on.
 *
 * A record is addressed by uid in the URL and never by primary key, so a URL
 * leaks nothing about how many clients or invoices exist. `record()` resolves
 * one and 404s rather than returning null, so no action method has to remember
 * to check.
 *
 * Every mutation ends in a redirect, never a re-render (instruction 16). Two
 * reasons: a refresh must not repeat the write, and option() memoises per key
 * for the whole request - so a settings screen that re-rendered would show the
 * value it had just replaced.
 *
 * Lists are GET and searched through the query string (instruction 17), so a
 * filtered view is a URL somebody can bookmark or send to a colleague.
 */
abstract class AdminController extends Controller
{
    /** @var ?array The Signed-In Staff Member, Resolved Once */
    private ?array $staff = null;

    /**
     * The Admin Template, From The Operator's Settings
     *
     * Pinned to ADMIN rather than left to the current request: this controller
     * only ever renders admin screens, and current_template() reads the URL.
     * @return string Example: 'admin/bootstrap'
     */
    protected function theme(): string
    {
        return template_dir(ADMIN);
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * The Sidebar Section This Controller Belongs To
     *
     * Assigned to every render so the sidebar can mark itself active without
     * guessing from the URL - which would break the moment a screen lived
     * somewhere its section name did not appear.
     * @return string
     */
    abstract protected function nav(): string;

    /**
     * Render an Admin Screen
     * @param string $view View Name Below The Template. Example: 'clients'
     * @param string $title Page Title
     * @param array<string,mixed> $vars Variables
     * @return string
     */
    protected function screen(string $view, string $title, array $vars = []): string
    {
        return $this->render($view, array_merge([
            'nav'        =>  $this->nav(),
            'page_title' =>  $title,
            'staff'      =>  $this->staff(),
        ], $vars));
    }

    /**
     * The Signed-In Staff Member
     *
     * The Auth pipeline already resolved and validated them; this is only a
     * convenience so a controller does not repeat the lookup.
     * @return ?array
     */
    protected function staff(): ?array
    {
        return $this->staff ??= Auth::user(ADMIN);
    }

    /**
     * The Signed-In Staff Member's ID
     * @return ?int
     */
    protected function staffId(): ?int
    {
        $staff = $this->staff();

        return $staff === null ? null : ((int) ($staff['sid'] ?? 0) ?: null);
    }

    /**
     * Resolve a Record Or Stop With a 404
     *
     * Not a null return: every caller would then have to remember to check, and
     * the one that forgot would render a page full of empty fields rather than
     * saying the thing does not exist.
     * @param ?array $record Record
     * @param string $what What Was Being Looked For
     * @return array
     * @throws HttpException
     */
    protected function record(?array $record, string $what = 'record'): array
    {
        if ($record === null) {
            throw new HttpException(404, "That {$what} does not exist.");
        }

        return $record;
    }

    /**
     * Finish a Mutation
     *
     * Always a redirect (instruction 16). Redirect::to() calls exit(), so the
     * return only exists to keep the declared ?string signature honest.
     * @param string $route Route Name
     * @param string $message Flash Message
     * @param bool $ok Whether It Went Well
     * @param array $params Route Parameters
     * @return null
     */
    protected function done(string $route, string $message, bool $ok = true, array $params = []): null
    {
        Redirect::with($message, $ok)->to($route, $params);

        return null;
    }

    /**
     * Run a Mutation, Turning a Refusal Into a Message
     *
     * The actions refuse things by throwing - deleting the last administrator,
     * invoicing an order twice, over-refunding a payment. Those are answers to
     * the operator, not faults, so they belong in the flash rather than on an
     * error page. Anything else is a real fault and is left to propagate.
     * @param callable $work The Mutation
     * @param string $route Route To Return To
     * @param string $success Message When It Works
     * @param array $params Route Parameters
     * @return null
     */
    protected function attempt(callable $work, string $route, string $success, array $params = []): null
    {
        try {
            $work();
        } catch (HttpException $e) {
            throw $e;
        } catch (Throwable $e) {
            return $this->done($route, $e->getMessage(), false, $params);
        }

        return $this->done($route, $success, true, $params);
    }

    /**
     * Record What Just Happened, Against The Signed-In Staff Member
     * @param string $event Event Name. Example: 'client.created'
     * @param string $log What Happened, In Words
     * @param array $changes Changed Fields
     * @return void
     */
    protected function log(string $event, string $log, array $changes = []): void
    {
        Activity::recordStaff($event, $log, $this->staffId(), $changes);
    }

    /**
     * The Search Term From The Query String
     * @return ?string
     */
    protected function search(): ?string
    {
        $search = trim((string) Request::input('search', ''));

        return $search === '' ? null : $search;
    }

    /**
     * Read a Filter From The Query String
     *
     * Returns null for anything blank, so an unset dropdown drops out of the
     * conditions rather than filtering on an empty string.
     * @param string $key Query Key
     * @return ?int
     */
    protected function filter(string $key): ?int
    {
        $value = Request::input($key);

        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        return (int) $value > 0 ? (int) $value : null;
    }

    /**
     * Build The Conditions For a List Screen
     *
     * Only the filters that were actually set. A filter left blank must not
     * become `WHERE status_relid = ''`.
     * @param array<string,string> $map Query Key => Column
     * @return array<string,int>
     */
    protected function conditions(array $map): array
    {
        $where = [];

        foreach ($map as $key => $column) {
            $value = $this->filter($key);

            if ($value !== null) {
                $where[$column] = $value;
            }
        }

        return $where;
    }

    /**
     * Turn a Status List Into Select-Box Choices
     *
     * Status::all() returns rows carrying an id, a name and a colour, which is
     * what a filter or a pill needs. A <select> wants id => label, and building
     * that in Twig means an arrow function and a reduce filter to do what one
     * loop does plainly here.
     * @param array $statuses Status Rows
     * @return array<int,string>
     */
    protected function statusChoices(array $statuses): array
    {
        $choices = [];

        foreach ($statuses as $status) {
            $choices[(int) $status['id']] = status_label((string) $status['name']);
        }

        return $choices;
    }

    /**
     * Require Some Fields To Be Present
     *
     * Adds an error per missing field and reports whether anything was missing,
     * so a controller reads `if ($this->require([...])) { ... }` rather than
     * repeating the same four lines on every form.
     * @param array<string,string> $fields Field => Message
     * @param array $input Submitted Data
     * @return bool True when everything required is there
     */
    protected function require(array $fields, array $input): bool
    {
        foreach ($fields as $field => $message) {
            if (trim((string) ($input[$field] ?? '')) === '') {
                Request::addError($field, $message);
            }
        }

        return Request::errors() === [];
    }

    /**
     * Check An Email Address Looks Like One
     * @param string $field Field Name
     * @param array $input Submitted Data
     * @return void
     */
    protected function requireEmail(string $field, array $input): void
    {
        $value = trim((string) ($input[$field] ?? ''));

        if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            Request::addError($field, 'That does not look like an email address.');
        }
    }
}
