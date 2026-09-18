<?php
/**
 * Laika Bill Manager
 * Author: Showket Ahmed
 * Email: riyadhtayf@gmail.com
 * License: Proprietary - see LICENSE
 * This file is part of Laika Bill Manager.
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace LBM\Action;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Laika\Model\Model;
use Laika\Service\Vault;
use LBM\Model\PaymentMethodModel;
use LBM\Model\PaymentMethodTypeModel;
use RuntimeException;
use Throwable;

/**
 * A client's saved cards - Phase 48.
 *
 * `payment_methods` has existed since Phase 0 with a token column, the last four
 * digits, the brand, the expiry and a default flag, and nothing ever wrote to it.
 * A gateway that takes the card on the site - a tokenizer - is what fills it.
 *
 * ---------------------------------------------------------------------------
 * THE TOKEN IS SEALED, AND NEVER SHOWN
 * ---------------------------------------------------------------------------
 * It is not a card number - no card number reaches this application - but a
 * token and the gateway's secret key together can charge the customer. So it is
 * sealed with Vault as a server's root password is, never handed to a template
 * (forClient() takes it off), and kept out of the activity log.
 *
 * ---------------------------------------------------------------------------
 * THE YEAR IS TWO DIGITS
 * ---------------------------------------------------------------------------
 * `expiry_year` is a signed TINYINT: -128 to 127. MySQL in non-strict mode would
 * store 2031 as 127 without a word - Phase 41's port trap again - so the year is
 * kept as 31 and shown as 12/31, which is how a card prints it anyway.
 *
 * ---------------------------------------------------------------------------
 * REMOVING A CARD
 * ---------------------------------------------------------------------------
 * The provider is asked to forget it, and the row is deleted whatever it says:
 * this installation is the only thing that would ever charge the token, and the
 * customer asked for it to stop. Whether the provider confirmed is reported.
 */
class PayMethod extends Action
{
    /** @var string The Payment Method Type a Saved Card Is */
    public const TYPE = 'card';

    /** @var int The Longest Token Kept - Sealed, It Must Fit a varchar(255) */
    private const MAX_TOKEN = 150;

    public function model(): Model
    {
        return new PaymentMethodModel();
    }

    protected function createdColumn(): ?string
    {
        return 'method_created_at';
    }

    protected function updatedColumn(): ?string
    {
        return null;
    }

    ####################################################################################
    /*================================= EXTERNAL API =================================*/
    ####################################################################################

    /**
     * A Client's Cards, Without Their Tokens
     *
     * The ONLY view of a card a template is given. `usable` says whether its
     * gateway can still charge it - switched on, and a tokenizer that builds.
     * A card whose gateway was switched off is hidden, not deleted: switching it
     * back on brings the card back.
     * @param int $clientId
     * @param bool $usableOnly Only cards that can be charged now
     * @return array
     */
    public function forClient(int $clientId, bool $usableOnly = true): array
    {
        if ($clientId <= 0) {
            return [];
        }

        $model = $this->model();
        $rows = $model->where(['client_relid' => $clientId])->order($model->id, self::ASC)->get();
        $gateways = new Gateway();
        $out = [];

        foreach ($rows as $row) {
            $gateway = $this->usableGateway($row, $gateways);

            if ($usableOnly && $gateway === null) {
                continue;
            }

            $named = $gateway ?? $gateways->find((int) ($row['gateway_relid'] ?? 0));

            $out[] = $this->shown($row) + [
                'usable'       => $gateway !== null,
                'gateway_name' => (string) ($named['display_name'] ?? ''),
                'gateway_slug' => (string) ($named['gateway_slug'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * One Of a Client's Cards, Token And All - Or Null
     *
     * By the client's id as well as the card's uid, so another account's card
     * is not found rather than found and refused.
     * @param int $clientId
     * @param string $uid
     * @return ?array
     */
    public function forClientKey(int $clientId, string $uid): ?array
    {
        $uid = trim($uid);

        if ($clientId <= 0 || $uid === '') {
            return null;
        }

        $row = $this->model()->where(['uid' => $uid, 'client_relid' => $clientId])->first();

        return is_array($row) && $row !== [] ? $row : null;
    }

    /**
     * The Gateway a Card Can Be Charged Through Now - Or Null
     * @param array $row The card
     * @return ?array
     */
    public function gatewayOf(array $row): ?array
    {
        return $this->usableGateway($row);
    }

    /**
     * The Client's Default Card, When It Can Be Charged
     * @param int $clientId
     * @return ?array Token and all
     */
    public function defaultFor(int $clientId): ?array
    {
        if ($clientId <= 0) {
            return null;
        }

        $row = $this->model()->where(['client_relid' => $clientId, 'is_default' => 'yes'])->first();

        if (!is_array($row) || $row === []) {
            return null;
        }

        return $this->usableGateway($row) === null ? null : $row;
    }

    /**
     * Keep a Card a Gateway Returned
     *
     * The client's first card becomes the default; a later one does not, so
     * saving a second card never quietly changes which one renewals are charged to.
     * @param int $clientId
     * @param array $gateway The gateway row it was saved through
     * @param array $saved {token, brand, last_four, exp_month, exp_year}
     * @return int The new card's id
     * @throws RuntimeException
     */
    public function store(int $clientId, array $gateway, array $saved): int
    {
        $gatewayId = (int) ($gateway['gateway_id'] ?? 0);
        $token = trim((string) ($saved['token'] ?? ''));

        if ($clientId <= 0 || $gatewayId <= 0) {
            throw new RuntimeException('A saved card needs a client and a gateway.');
        }

        if ($token === '' || strlen($token) > self::MAX_TOKEN) {
            throw new RuntimeException('The gateway returned no card to keep.');
        }

        $last4 = (string) preg_replace('/\D/', '', (string) ($saved['last_four'] ?? ''));
        $month = (int) ($saved['exp_month'] ?? 0);
        $year = (int) ($saved['exp_year'] ?? 0);
        $brand = substr((string) preg_replace('/[^a-z0-9 _\-]/i', '', (string) ($saved['brand'] ?? '')), 0, 20);

        $first = !$this->model()->where(['client_relid' => $clientId])->exists();

        return $this->create([
            'client_relid'  =>  $clientId,
            'gateway_relid' =>  $gatewayId,
            'type_relid'    =>  $this->typeId(),
            'token'         =>  Vault::encrypt($token),
            'last_four'     =>  strlen($last4) === 4 ? $last4 : null,
            'card_brand'    =>  $brand !== '' ? strtolower($brand) : null,
            'expiry_month'  =>  $month >= 1 && $month <= 12 ? $month : null,
            // Two digits - the column is a signed TINYINT. See the class docblock.
            'expiry_year'   =>  $year > 0 ? $year % 100 : null,
            'is_default'    =>  $first ? 'yes' : 'no',
        ]);
    }

    /**
     * Make One Card The Client's Default - There Is Only Ever One
     * @param int $clientId
     * @param string $uid
     * @return void
     * @throws RuntimeException When it is not the client's card
     */
    public function setDefault(int $clientId, string $uid): void
    {
        $row = $this->forClientKey($clientId, $uid);

        if ($row === null) {
            throw new RuntimeException('That card is not on this account.');
        }

        $this->model()->where(['client_relid' => $clientId])->update(['is_default' => 'no']);

        $model = $this->model();
        $model->where([$model->id => (int) $row['pm_id']])->update(['is_default' => 'yes']);
    }

    /**
     * Remove a Card - The Provider Is Asked To Forget It First
     * @param int $clientId
     * @param string $uid
     * @return array{forgotten: bool, message: string}
     * @throws RuntimeException When it is not the client's card
     */
    public function remove(int $clientId, string $uid): array
    {
        $row = $this->forClientKey($clientId, $uid);

        if ($row === null) {
            throw new RuntimeException('That card is not on this account.');
        }

        $gateways = new Gateway();
        $gateway = $gateways->find((int) ($row['gateway_relid'] ?? 0));
        $driver = $gateway === null ? null : $gateways->tokenizerFor($gateway);

        $forgotten = false;
        $message = '';

        if ($driver === null) {
            $message = 'Its gateway is not available, so it could not be asked to forget the card.';
        } else {
            try {
                $answer = $driver->forget($this->token($row));
                $forgotten = ($answer['success'] ?? false) === true;
                $message = trim((string) ($answer['message'] ?? ''));
            } catch (Throwable $e) {
                $message = $e->getMessage();
            }
        }

        $model = $this->model();
        $model->where([$model->id => (int) $row['pm_id']])->delete();

        // The default went with it: the newest remaining card takes over, so a
        // client with cards on file always has one renewals can be charged to.
        if (($row['is_default'] ?? 'no') === 'yes') {
            $model = $this->model();
            $next = $model->where(['client_relid' => $clientId])->order($model->id, self::DESC)->limit(1)->get();

            if ($next !== []) {
                $model = $this->model();
                $model->where([$model->id => (int) $next[0]['pm_id']])->update(['is_default' => 'yes']);
            }
        }

        return ['forgotten' => $forgotten, 'message' => $message];
    }

    /**
     * A Card's Token, Opened - For Handing Back To Its Gateway And Nothing Else
     * @param array $row
     * @return string '' When it cannot be opened
     */
    public function token(array $row): string
    {
        try {
            return (string) Vault::decrypt((string) ($row['token'] ?? ''));
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * A Card In Words: "Visa •••• 4242 (12/31)"
     * @param array $row
     * @return string
     */
    public function describe(array $row): string
    {
        $brand = trim((string) ($row['card_brand'] ?? ''));
        $text = ($brand !== '' ? ucfirst($brand) : 'Card') . ' •••• ' . (string) ($row['last_four'] ?? '????');

        $month = (int) ($row['expiry_month'] ?? 0);
        $year = (int) ($row['expiry_year'] ?? 0);

        return $month > 0 ? $text . sprintf(' (%02d/%02d)', $month, $year) : $text;
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * A Card's Gateway, When It Is Switched On And a Tokenizer That Builds
     * @param array $row
     * @param ?Gateway $gateways
     * @return ?array
     */
    private function usableGateway(array $row, ?Gateway $gateways = null): ?array
    {
        $gateways ??= new Gateway();
        $gateway = $gateways->find((int) ($row['gateway_relid'] ?? 0));

        if ($gateway === null || ($gateway['is_active'] ?? 'no') !== 'yes') {
            return null;
        }

        return $gateways->tokenizerFor($gateway) === null ? null : $gateway;
    }

    /**
     * A Card With Its Token Taken Off, And a Label
     * @param array $row
     * @return array
     */
    private function shown(array $row): array
    {
        unset($row['token']);

        $row['label'] = $this->describe($row);

        return $row;
    }

    /**
     * The `card` Payment Method Type
     * @return int
     */
    private function typeId(): int
    {
        try {
            $row = (new PaymentMethodTypeModel())->where(['type_name' => self::TYPE])->first();
        } catch (Throwable) {
            $row = null;
        }

        return (int) ($row['pm_type_id'] ?? 1);
    }
}
