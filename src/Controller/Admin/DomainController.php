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

use Laika\Model\Model;
use Laika\Service\Request;
use LBM\Service\Client;
use LBM\Service\Currency;
use LBM\Service\Domain;
use LBM\Service\Transfer;

/**
 * Domains.
 *
 * A record of what a client owns and when it expires, not a registrar client.
 * Registering and transferring for real belongs to a registrar module in the
 * provisioning phase; what an operator needs here is to answer "when does this
 * expire and where does it point" without logging into anybody's control panel.
 */
class DomainController extends AdminController
{
    protected function nav(): string
    {
        return 'domains';
    }

    /**
     * The Domain List
     * @return string
     */
    public function index(): string
    {
        return $this->screen('domains', 'Domains', [
            'pager'     =>  Domain::browseWithClients(
                $this->conditions(['status' => 'status_relid', 'registrar' => 'registrar_relid']),
                $this->search()
            ),
            'statuses'  =>  Domain::statuses(),
            'registrars' =>  $this->registrarChoices(),
            'expiring'  =>  count(Domain::expiringWithin(30)),
        ]);
    }

    /**
     * One Domain
     * @param string $domain Domain Uid
     * @return string
     */
    public function show(string $domain): string
    {
        $row = $this->record(Domain::find($domain), 'domain');

        return $this->screen('domain', $row['domain'], [
            'domain'      =>  $row,
            'client'      =>  Client::find((int) $row['client_relid']),
            'nameservers' =>  Domain::nameservers((int) $row['domain_id']),
            'expired'     =>  Domain::isExpired($row),
            'days'        =>  Domain::daysToExpiry($row),

            // What the registrar sweeps have and have not managed. A renewal
            // that keeps failing is quiet in a dangerous direction - nobody
            // complains about a domain that has not been renewed until it is
            // gone - so it is put on the screen rather than left to three lines
            // in the activity log. Phase 23 learned this about suspensions.
            'registrar'   =>  $this->registrarState($row),
        ]);
    }

    /**
     * What The Registrar Sweeps Have Recorded On One Domain
     *
     * `registrar_data` is a serialize column, so this reads it through the
     * model and never through a bare Model - casts run on READ only, and a raw
     * query hands back the serialized string.
     * @param array $domain Domain Row
     * @return array<string,mixed>
     */
    private function registrarState(array $domain): array
    {
        $data = $domain['registrar_data'] ?? null;
        $data = is_array($data) ? $data : [];

        return [
            'reference'       =>  (string) ($data['reference'] ?? ''),
            'renewed_through' =>  (string) ($data['renewed_through'] ?? ''),
            'last_error'      =>  (string) ($data['last_error'] ?? ''),
            'attempts'        =>  (int) ($data['register_attempts'] ?? 0)
                + (int) ($data['renew_attempts'] ?? 0)
                + (int) ($data['transfer_attempts'] ?? 0),
            'expiry_unknown'  =>  !empty($data['expiry_unknown']),

            // A transfer submitted and never finished is the quietest failure
            // in the product: the customer has paid, the registry has been
            // told, and nothing on any screen changes again until somebody
            // asks. The DATE is what makes it actionable - a transfer sent
            // this morning is fine and one sent three weeks ago is not.
            'transfer'        =>  (string) ($domain['type'] ?? '') === 'transfer',
            'submitted'       =>  !empty($data['transfer_submitted']),
            'sent_at'         =>  (string) ($data['transfer_sent_at'] ?? ''),
        ];
    }

    /**
     * Finish a Transfer The Registry Has Approved
     *
     * Staff-driven, and that is a limitation rather than a preference.
     * RegistrarInterface has five verbs and none of them answers "how is that
     * transfer going", so once a module has reported `pending` there is no way
     * to ask again - see Action\Transfer. Somebody has to say when it landed,
     * and this is where they say it.
     *
     * The expiry is OPTIONAL and is whatever the registry told them. Left
     * blank, the domain is billed a year out rather than never billed at all -
     * 27.2's split between a registry fact and our own schedule.
     * @param string $domain Domain Uid
     * @return ?string
     */
    public function completeTransfer(string $domain): ?string
    {
        $row = $this->record(Domain::find($domain), 'domain');
        $back = ['domain' => $row['uid']];

        if ((string) ($row['type'] ?? '') !== 'transfer') {
            return $this->done('staff.domain', local('domain_not_a_transfer'), false, $back);
        }

        $expiry = trim((string) Request::input('expiry_date', ''));
        $when = $expiry === '' ? null : strtotime($expiry);

        // A date the database would refuse becomes NO date rather than an
        // error: the transfer really has completed, and refusing to record
        // that over a mistyped date leaves the customer's name in limbo.
        $parsed = $when === false || $when === null ? null : date('Y-m-d H:i:s', $when);

        return $this->attempt(
            function () use ($row, $parsed): void {
                Transfer::complete((int) $row['domain_id'], $parsed);

                // Spent. Whoever holds an auth code can move the name, and the
                // transfer it was for is done - so it is cleared rather than
                // kept encrypted for ever against nothing.
                Domain::setEppCode((int) $row['domain_id'], null);

                $this->log(
                    'domain.transfer.completed',
                    'Marked the transfer of ' . $row['domain'] . ' complete.'
                );
            },
            'staff.domain',
            local('domain_transfer_completed'),
            $back
        );
    }

    /**
     * Edit a Domain
     * @param string $domain Domain Uid
     * @return ?string
     */
    public function edit(string $domain): ?string
    {
        $row = $this->record(Domain::find($domain), 'domain');
        $id = (int) $row['domain_id'];

        if (Request::isPost()) {
            $input = Request::inputs();

            Domain::modify($id, $input);

            // Nameservers arrive as a short ordered list from the form. An
            // empty submission is a deliberate clearing, so it is only acted on
            // when the field was actually on the form.
            if (array_key_exists('nameservers', $input)) {
                // A textarea gives one string, a repeated field gives an array;
                // the form can produce either, so both are accepted.
                $hosts = is_array($input['nameservers'])
                    ? $input['nameservers']
                    : (preg_split('/[\s,]+/', (string) $input['nameservers']) ?: []);

                Domain::setNameservers($id, $hosts);
            }

            $this->log('domain.updated', 'Updated domain ' . $row['domain']);

            return $this->done('staff.domain', local('domain_updated'), true, ['domain' => $row['uid']]);
        }

        return $this->screen('domain-form', local('edit_named', $row['domain']), [
            'domain'      =>  $row,
            'nameservers' =>  Domain::nameservers($id),
            'statuses'    =>  $this->statusChoices(Domain::statuses()),
            'clients'     =>  $this->clientChoices(),
            'currencies'  =>  $this->currencyChoices(),
            'registrars'  =>  $this->registrarChoices(),
            'types'       =>  $this->labels(Domain::types()),
            'cycles'      =>  $this->labels(Domain::cycles()),
        ]);
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Turn a List Of Enum Values Into Select Choices
     * @param string[] $values Values
     * @return array<string,string>
     */
    private function labels(array $values): array
    {
        $choices = [];

        foreach ($values as $value) {
            $choices[$value] = ucwords(str_replace('_', ' ', $value));
        }

        return $choices;
    }

    /**
     * Registrar Choices
     * @return array<int,string>
     */
    private function registrarChoices(): array
    {
        $model = (new Model())->table('domain_registrars');
        $choices = [];

        foreach ($model->get() as $row) {
            $id = (int) ($row['dr_id'] ?? $row['id'] ?? 0);

            if ($id === 0) {
                continue;
            }

            $choices[$id] = (string) ($row['registrar_name'] ?? $row['name'] ?? local('registrar_numbered', $id));
        }

        return $choices;
    }

    /**
     * Client Choices
     * @return array<int,string>
     */
    private function clientChoices(): array
    {
        $choices = [];

        foreach (Client::all([], 'ASC', 'first_name') as $row) {
            $label = trim((string) ($row['company_name'] ?? '')) !== ''
                ? $row['company_name'] . ' (' . $row['first_name'] . ' ' . $row['last_name'] . ')'
                : $row['first_name'] . ' ' . $row['last_name'];

            $choices[(int) $row['cid']] = $label;
        }

        return $choices;
    }

    /**
     * Currency Choices
     * @return array<int,string>
     */
    private function currencyChoices(): array
    {
        $choices = [];

        foreach (Currency::listing(true) as $row) {
            $choices[(int) $row['currency_id']] = (string) $row['currency_code'];
        }

        return $choices;
    }
}
