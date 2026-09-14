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

use RuntimeException;
use Throwable;
use Laika\Core\Exceptions\HttpException;
use Laika\Service\Redirect;
use Laika\Service\Request;
use Laika\Service\Response;
use LBM\Service\Gateway;
use LBM\Service\Money;
use LBM\Service\PayMethod;

/**
 * A client's saved cards - Phase 48.
 *
 * Listing, adding (through a gateway that takes the card on the site),
 * choosing the default and removing. Everything is scoped by ownership through
 * PayMethod::forClientKey(), so another account's card is not found rather than
 * found and refused.
 *
 * Behind the `invoice` permission - read to see, update to change - rather than
 * a group of its own: a card is how invoices get paid, and a new group would be
 * invisible on every sub-login that already exists (20.5).
 *
 * This controller also serves a tokenizer module's browser adapter: modules/ is
 * closed to the web, and the adapter has to come from somewhere.
 */
class PayMethodController extends ClientController
{
    protected function nav(): string
    {
        return 'payment_methods';
    }

    /**
     * The Client's Cards, And a Field To Add One
     * @return string
     */
    public function index(): string
    {
        $this->allow('invoice');

        return $this->screen('payment-methods', local('payment_methods'), [
            'cards'  =>  PayMethod::forClient($this->owner(), false),
            'fields' =>  $this->fields(),
        ]);
    }

    /**
     * Save a Card Without Paying
     * @return ?string
     */
    public function add(): ?string
    {
        $this->allow('invoice', self::UPDATE);

        $gateway = Gateway::payableBySlug((string) Request::input('gateway', ''));
        $driver = $gateway === null ? null : Gateway::tokenizerFor($gateway);

        if ($driver === null) {
            return $this->done('client.payment.methods', local('payment_method_unavailable'), false);
        }

        $token = trim((string) Request::input('token', ''));

        if ($token === '') {
            return $this->done('client.payment.methods', local('card_details_missing'), false);
        }

        try {
            $result = $driver->store($this->client() ?? [], $token, [
                'return_url' => named('client.payment.method.return', ['gateway' => (string) $gateway['gateway_slug']]),
            ]);
        } catch (Throwable $e) {
            return $this->done('client.payment.methods', local('card_not_saved', $e->getMessage()), false);
        }

        return $this->kept($gateway, $result);
    }

    /**
     * Back From The Bank After Saving a Card
     *
     * A GET, because it is the bank's browser redirect; it keeps only a card the
     * gateway confirms, for this account.
     * @param string $gateway Gateway Slug
     * @return ?string
     */
    public function back(string $gateway): ?string
    {
        $this->allow('invoice', self::UPDATE);

        $row = Gateway::payableBySlug($gateway);
        $driver = $row === null ? null : Gateway::tokenizerFor($row);

        if ($driver === null) {
            return $this->done('client.payment.methods', local('payment_method_unavailable'), false);
        }

        try {
            $result = $driver->complete(Request::inputs(), ['purpose' => 'setup', 'client_id' => $this->owner()]);
        } catch (Throwable $e) {
            return $this->done('client.payment.methods', local('card_not_saved', $e->getMessage()), false);
        }

        // Back from the bank, a second request to go there is a no.
        $result['redirect'] = null;

        return $this->kept($row, $result);
    }

    /**
     * Make a Card The Default
     * @param string $method Card Uid
     * @return ?string
     */
    public function makeDefault(string $method): ?string
    {
        $this->allow('invoice', self::UPDATE);

        return $this->attempt(
            function () use ($method): void {
                PayMethod::setDefault($this->owner(), $method);
            },
            'client.payment.methods',
            local('card_made_default')
        );
    }

    /**
     * Remove a Card - The Gateway Is Asked To Forget It
     * @param string $method Card Uid
     * @return ?string
     */
    public function remove(string $method): ?string
    {
        $this->allow('invoice', self::UPDATE);

        try {
            $result = PayMethod::remove($this->owner(), $method);
        } catch (RuntimeException $e) {
            return $this->done('client.payment.methods', $e->getMessage(), false);
        }

        $this->log('payment.method.removed', 'Removed a saved card.');

        return $this->done(
            'client.payment.methods',
            $result['forgotten'] ? local('card_removed') : local('card_removed_locally', (string) $result['message']),
            true
        );
    }

    /**
     * A Tokenizer's Browser Adapter
     *
     * Only for a gateway on offer that takes the card on the site, and only a
     * file inside its own module - Gateway::scriptFile() says why.
     * @param string $gateway Gateway Slug
     * @return string
     * @throws HttpException
     */
    public function script(string $gateway): string
    {
        $row = Gateway::payableBySlug($gateway);
        $file = $row === null ? null : Gateway::scriptFile($row);

        if ($file === null) {
            throw new HttpException(404, local('record_not_found', 'script'));
        }

        Response::setContentType('application/javascript');

        return (string) file_get_contents($file);
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Keep What a Gateway Saved - Or Say Why Not
     * @param array $gateway Gateway Row
     * @param array $result store() or complete()'s answer
     * @return ?string
     */
    private function kept(array $gateway, array $result): ?string
    {
        if (($result['pending'] ?? false) === true && trim((string) ($result['redirect'] ?? '')) !== '') {
            Redirect::to((string) $result['redirect']);

            return null;
        }

        if (($result['success'] ?? false) !== true || !is_array($result['saved'] ?? null)) {
            $why = trim((string) ($result['message'] ?? ''));

            return $this->done('client.payment.methods', local('card_not_saved', $why !== '' ? $why : local('payment_failed')), false);
        }

        try {
            PayMethod::store($this->owner(), $gateway, $result['saved']);
        } catch (RuntimeException $e) {
            return $this->done('client.payment.methods', local('card_not_saved', $e->getMessage()), false);
        }

        $this->log('payment.method.added', 'Saved a card through ' . $gateway['display_name'] . '.');

        return $this->done('client.payment.methods', local('card_saved'), true);
    }

    /**
     * A Card Field For Every Gateway On Offer That Takes The Card On The Site
     * @return array
     */
    private function fields(): array
    {
        $client = $this->client() ?? [];
        $currencyId = (int) ($client['currency_relid'] ?? 0);
        $currency = $currencyId > 0 ? Money::get($currencyId) : null;
        $out = [];

        foreach (Gateway::payable() as $gateway) {
            if (!Gateway::isTokenizer($gateway)) {
                continue;
            }

            $field = Gateway::cardField($gateway, [
                'purpose'  => 'setup',
                'currency' => (string) ($currency['currency_code'] ?? ''),
                'client'   => $client,
            ]);

            if ($field !== null) {
                $field['script_url'] = named('client.gateway.script', ['gateway' => (string) $gateway['gateway_slug']]);
                $out[] = $field;
            }
        }

        return $out;
    }
}
