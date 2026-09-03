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

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use LBM\Pipeline\Auth;
use LBM\Service\Client;
use LBM\Service\ClientContact;
use LBM\Service\Money;

####################################################################################
/*-------------------------------- CLIENT IDENTITY -------------------------------*/
####################################################################################
//
// A client and a client contact are two different logins over the same records.
// A contact signs in on the `contact` guard and acts on their parent client's
// data, so screens ask for the client (whose records these are) and separately
// for the contact (who is actually looking at them).

/**
 * The Signed-In Client
 *
 * On a contact session this is the *parent client*, not the contact. A contact
 * has no company name, no credit balance and no currency of their own - those
 * belong to the account they are a sub-login of, and a screen asking "whose
 * records am I looking at" always means the client.
 *
 * Memoised against the resolved user, so a template calling it twenty times
 * costs one lookup while a sign-in part-way through a request is still seen.
 * @return ?array
 */
function current_client(): ?array
{
    static $cache = [];

    $user = Auth::user(PANEL);

    if ($user === null) {
        return null;
    }

    if (Auth::guardOf(PANEL) !== Auth::CONTACT) {
        return $user;
    }

    $parent = (int) ($user['client_relid'] ?? 0);

    if ($parent === 0) {
        return null;
    }

    return $cache[$parent] ??= Client::find($parent);
}

/**
 * The Signed-In Client Contact, When The Session Belongs To a Sub-Login
 *
 * Null when the client signed in directly - there is no contact in that case,
 * which is exactly how a screen tells the two apart.
 * @return ?array
 */
function current_contact(): ?array
{
    return Auth::guardOf(PANEL) === Auth::CONTACT ? Auth::user(PANEL) : null;
}

/**
 * Whether Somebody Is Signed Into The Client Area
 * @return bool
 */
function is_client(): bool
{
    return current_client() !== null;
}

/**
 * Whether The Public May Open An Account
 *
 * Reads the same option AuthController::register() enforces, so the public site
 * can stop offering a door that is locked.
 *
 * It defaults to off, and that made this the visible state of every fresh
 * install: the front bar showed a "Get started" button, and a visitor who
 * pressed it got an error page. An error page reading 500 rather than the 404
 * the controller threw, because HttpException carries a status the handler
 * ignores - so the first thing a stranger saw of a new installation was a server
 * error. Found by walking the front bar's own links in navwalk.
 * @return bool
 */
function registration_open(): bool
{
    return option_bool('allow_registration');
}

/**
 * Whether The Person Looking May Reach Something
 *
 * The account holder may reach everything of their own - they own the records,
 * and there is nobody to grant them anything. A contact may reach only what the
 * client ticked for them, in the same shape the admin panel uses for staff:
 * {"invoice":{"read":1,...},...}.
 *
 * Used by the client sidebar to hide what a contact cannot open. Hiding a link
 * is a courtesy - every controller checks the same thing server-side, because a
 * hidden link is not a control.
 * @param string $access Access Name. Example: 'invoice.read'
 * @return bool
 */
function client_can(string $access): bool
{
    $contact = current_contact();

    if ($contact === null) {
        return is_client();
    }

    return ClientContact::allows($contact, $access);
}

/**
 * A Client's Display Name
 *
 * Company name wins when it is set - a billing record belongs to the business,
 * and that is the name that has to match the invoice. Falls back to the person.
 * @param ?array $client Client Row. Defaults to the signed-in client
 * @return string
 */
function client_name(?array $client = null): string
{
    $client = $client ?? current_client();

    if (!$client) {
        return '';
    }

    $company = trim((string) ($client['company_name'] ?? ''));

    if ($company !== '') {
        return $company;
    }

    return client_person_name($client);
}

/**
 * A Client's Personal Name, Ignoring The Company
 * @param ?array $client Client Row. Defaults to the signed-in client
 * @return string
 */
function client_person_name(?array $client = null): string
{
    $client = $client ?? current_client();

    if (!$client) {
        return '';
    }

    $parts = array_filter([
        trim((string) ($client['first_name'] ?? '')),
        trim((string) ($client['middle_name'] ?? '')),
        trim((string) ($client['last_name'] ?? '')),
    ], static fn(string $part): bool => $part !== '');

    return implode(' ', $parts);
}

/**
 * A Client's Credit Balance, Formatted In Their Own Currency
 *
 * Credit is money the client has already paid that no invoice has consumed yet,
 * so it is always shown in the currency they were billed in rather than the
 * operator's default.
 * @param ?array $client Client Row. Defaults to the signed-in client
 * @return string
 */
function client_balance(?array $client = null): string
{
    $client = $client ?? current_client();

    if (!$client) {
        return Money::format('0');
    }

    return Money::format(
        $client['credit_balance'] ?? '0',
        isset($client['currency_relid']) ? (int) $client['currency_relid'] : null
    );
}

/**
 * A Client's Preferred Currency Id
 * @param ?array $client Client Row. Defaults to the signed-in client
 * @return ?int
 */
function client_currency(?array $client = null): ?int
{
    $client = $client ?? current_client();

    return isset($client['currency_relid']) ? (int) $client['currency_relid'] : null;
}
