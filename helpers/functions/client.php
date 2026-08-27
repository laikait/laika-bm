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
 * @return ?array
 */
function current_client(): ?array
{
    return Auth::user(PANEL);
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
