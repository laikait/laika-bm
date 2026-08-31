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

namespace LBM\Controller\Client;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Throwable;
use Laika\Service\Request;
use Laika\Service\Redirect;
use Laika\Core\Exceptions\HttpException;
use LBM\Controller\Controller;
use LBM\Service\Activity;
use LBM\Service\ClientContact;

/**
 * Base for every client-area screen.
 *
 * The admin panel and this area run on the same actions. What differs is how
 * reach is decided: a staff member is bounded by their *role*, and a client by
 * *ownership*. So there is no Permission pipeline on these routes - instead
 * every read goes through a lookup that has the client id in it, and a record
 * belonging to somebody else is not found rather than found and refused.
 *
 * That distinction is the whole design. `mine()` is the only way a controller
 * here is meant to resolve a record: hand it the uid from the URL and the
 * action's own client-scoped finder, and it either returns the row or 404s. A
 * controller that fetched by uid and compared client ids afterwards would work
 * until the day somebody forgot the second half.
 *
 * Two logins land here. A client signs in as themselves and owns everything on
 * the account. A client contact signs in as a sub-login and reaches only what
 * the account holder ticked for them - checked by allow(), against the same
 * permission JSON shape the admin panel uses for staff roles. Both see the same
 * records; only the contact can be told no.
 */
abstract class ClientController extends Controller
{
    /** @var string Reading Something */
    protected const READ = 'read';

    /** @var string Creating Something */
    protected const CREATE = 'create';

    /** @var string Changing Something */
    protected const UPDATE = 'update';

    /** @var string Removing Something */
    protected const DELETE = 'delete';

    /**
     * The Client Area Template, From The Operator's Settings
     *
     * Pinned to PANEL rather than left to the current request: this controller
     * only ever renders client screens, and current_template() reads the URL.
     * @return string Example: 'panel/bootstrap'
     */
    protected function theme(): string
    {
        return template_dir(PANEL);
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * The Sidebar Section This Controller Belongs To
     * @return string
     */
    abstract protected function nav(): string;

    /**
     * Render a Client-Area Screen
     * @param string $view View Name Below The Template. Example: 'invoices'
     * @param string $title Page Title
     * @param array<string,mixed> $vars Variables
     * @return string
     */
    protected function screen(string $view, string $title, array $vars = []): string
    {
        return $this->render($view, array_merge([
            'nav'        =>  $this->nav(),
            'page_title' =>  $title,
            'client'     =>  $this->client(),
            'contact'    =>  $this->contact(),
        ], $vars));
    }

    ####################################################################################
    /*=================================== IDENTITY ===================================*/
    ####################################################################################

    /**
     * The Account These Records Belong To
     *
     * On a contact session this is the parent client, not the contact - which
     * is what makes every scoped lookup below work the same for both logins.
     * @return ?array
     */
    protected function client(): ?array
    {
        return current_client();
    }

    /**
     * The Account's Id
     *
     * Never null in practice: the Auth pipeline has already refused anybody who
     * is not signed in. Typed nullable anyway, because a controller reached
     * outside that pipeline would otherwise get a fatal instead of a 404.
     * @return ?int
     */
    protected function clientId(): ?int
    {
        $client = $this->client();

        return $client === null ? null : ((int) ($client['cid'] ?? 0) ?: null);
    }

    /**
     * The Account's Id, Or Stop
     *
     * For the paths that cannot proceed without one. Every route here is behind
     * the Auth pipeline, so reaching this is a bug rather than a visitor doing
     * something unusual - but it fails as a 401 rather than as a type error.
     * @return int
     * @throws HttpException
     */
    protected function owner(): int
    {
        $id = $this->clientId();

        if ($id === null) {
            throw new HttpException(401, 'You are not signed in.');
        }

        return $id;
    }

    /**
     * The Sub-Login Actually Looking, If This Is One
     *
     * Null when the account holder signed in directly.
     * @return ?array
     */
    protected function contact(): ?array
    {
        return current_contact();
    }

    /**
     * Whether The Person Looking Is a Sub-Login
     * @return bool
     */
    protected function isContact(): bool
    {
        return $this->contact() !== null;
    }

    ####################################################################################
    /*=================================== OWNERSHIP ==================================*/
    ####################################################################################

    /**
     * Resolve One Of The Account's Own Records, Or 404
     *
     * The finder must be a client-scoped one - forClientKey() on the action -
     * so the ownership test is part of the query rather than a comparison this
     * method could be called without.
     *
     * A record belonging to another account gives the same 404 as one that was
     * never there. Saying "forbidden" instead would confirm the uid exists,
     * which is a slow way of enumerating other people's invoices.
     * @param callable $finder fn(int|string $key, int $clientId): ?array
     * @param int|string $key Uid From The URL
     * @param string $what What Was Being Looked For
     * @return array
     * @throws HttpException
     */
    protected function mine(callable $finder, int|string $key, string $what = 'record'): array
    {
        $row = $finder($key, $this->owner());

        if (!is_array($row) || $row === []) {
            throw new HttpException(404, "That {$what} does not exist.");
        }

        return $row;
    }

    /**
     * Refuse a Sub-Login That Was Not Granted Something
     *
     * The account holder passes everything - they own the records. A contact is
     * checked against the permission JSON the client set for them.
     *
     * 403 rather than 404 here, and deliberately so: unlike an ownership miss,
     * this says nothing a contact does not already know. They can see the
     * account has invoices; they have simply not been given them.
     * @param string $group Permission Group. Example: 'invoice'
     * @param string $action read, create, update or delete
     * @return void
     * @throws HttpException
     */
    protected function allow(string $group, string $action = self::READ): void
    {
        $contact = $this->contact();

        if ($contact === null) {
            return;
        }

        if (!ClientContact::allows($contact, $group . '.' . $action)) {
            throw new HttpException(403, 'Your account does not have access to that.');
        }
    }

    ####################################################################################
    /*=================================== PLUMBING ===================================*/
    ####################################################################################

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
     * The actions refuse things by throwing - paying an invoice that is already
     * settled, cancelling a service that already ended. Those are answers to the
     * person, not faults, so they belong in the flash rather than on an error
     * page. An HttpException is a real refusal and is left to propagate.
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
     * Record What Just Happened, Against Whoever Did It
     *
     * A contact's actions are recorded as the contact's, not the client's. When
     * five people share one account, "who changed the billing address" is the
     * question the log exists to answer.
     * @param string $event Event Name. Example: 'client.profile.updated'
     * @param string $log What Happened, In Words
     * @param array $changes Changed Fields
     * @return void
     */
    protected function log(string $event, string $log, array $changes = []): void
    {
        $contact = $this->contact();

        if ($contact !== null) {
            Activity::recordContact($event, $log, (int) ($contact['cc_id'] ?? 0) ?: null, $changes);

            return;
        }

        Activity::recordClient($event, $log, $this->clientId(), $changes);
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
     * Null for anything blank, so an unset dropdown drops out of the conditions
     * rather than filtering on an empty string.
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
     * Require Some Fields To Be Present
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
