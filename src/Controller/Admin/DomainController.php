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
        return $this->screen('admin/domains', 'Domains', [
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

        return $this->screen('admin/domain', $row['domain'], [
            'domain'      =>  $row,
            'client'      =>  Client::find((int) $row['client_relid']),
            'nameservers' =>  Domain::nameservers((int) $row['domain_id']),
            'expired'     =>  Domain::isExpired($row),
            'days'        =>  Domain::daysToExpiry($row),
        ]);
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

            return $this->done('staff.domain', 'Domain updated.', true, ['domain' => $row['uid']]);
        }

        return $this->screen('admin/domain-form', 'Edit ' . $row['domain'], [
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

            $choices[$id] = (string) ($row['registrar_name'] ?? $row['name'] ?? ('Registrar ' . $id));
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
