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

namespace LBM\Controller\Install;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Throwable;
use Laika\Service\Url;
use Laika\Service\Request;
use Laika\Service\Redirect;
use LBM\Controller\Controller;
use LBM\Install\Installer;
use LBM\Install\Requirements;
use LBM\Model\CurrencyModel;

/**
 * The install wizard.
 *
 * Every step is GET to render and POST to apply (instructions 16, 17), and
 * GlobalPipeline CSRF-checks each POST (instruction 15).
 *
 * The theme is hardcoded rather than read from `admin_template`, because on the
 * first run there is no database to read an option out of - which is precisely
 * the state this controller exists to resolve.
 */
class InstallController extends Controller
{
    /** @var array<string,string> Step Key => Label */
    public const STEPS = [
        'requirements' =>  'Requirements',
        'database'     =>  'Database',
        'migrate'      =>  'Tables',
        'settings'     =>  'Settings',
        'admin'        =>  'Administrator',
        'finish'       =>  'Finish',
    ];

    /** @var Installer */
    private Installer $installer;

    public function __construct()
    {
        $this->installer = new Installer();

        // GlobalPipeline stays in its uninstalled mode for the whole wizard -
        // the lock is not written until the last step - so it never opens the
        // database. Every step after the credentials are saved needs one.
        // Returns false while there is nothing to connect to, which is the
        // normal state on the first two steps.
        $this->installer->connect();
    }

    /**
     * The Installer Ships With The Package, Not With The Operator's Settings
     *
     * template/install/ has no template-name level, unlike the two areas: there
     * is no database to read an option out of while the wizard runs, which is
     * precisely the state this controller exists to resolve.
     * @return string
     */
    protected function theme(): string
    {
        return 'install';
    }

    ####################################################################################
    /*==================================== STEPS =====================================*/
    ####################################################################################

    /**
     * Step 1 - Requirements
     * @return string
     */
    public function requirements(): string
    {
        return $this->view('requirements', 'requirements', [
            'requirements' =>  (new Requirements())->all(),
        ], 'Check this server can run the app');
    }

    /**
     * Step 2 - Database Credentials
     * @return ?string
     */
    public function database(): ?string
    {
        $requirements = new Requirements();

        if (Request::isPost()) {
            $config = $this->installer->databaseConfig(Request::inputs());

            if ($config['database'] === '') {
                Request::addError('db_name', 'A database name is required.');
            } else {
                $error = $this->installer->testConnection($config);

                if ($error === null) {
                    // Only written once the credentials actually connect, so a
                    // typo never leaves the app pointed at a database that is
                    // not there.
                    $this->installer->writeDatabaseConfig($config);

                    // to() calls exit(), so nothing after it runs. The return
                    // keeps the declared ?string signature honest.
                    Redirect::with('Database connected.', true)->to('install.migrate');
                    return null;
                }

                return $this->view('database', 'database', [
                    'drivers'          =>  $requirements->drivers(),
                    'connection_error' =>  $error,
                ], 'Connect to your database');
            }
        }

        return $this->view('database', 'database', [
            'drivers'          =>  $requirements->drivers(),
            'connection_error' =>  null,
        ], 'Connect to your database');
    }

    /**
     * Step 3 - Create The Tables
     * @return ?string
     */
    public function migrate(): ?string
    {
        $results = null;
        $failed = 0;

        if (Request::isPost()) {
            $results = $this->installer->migrate();
            $failed = count(array_filter($results, static fn(array $r): bool => !$r['ok']));

            if ($failed === 0) {
                $this->installer->seedOptions();
            }
        }

        return $this->view('migrate', 'migrate', [
            'schema_count' =>  $this->installer->schemaCount(),
            'results'      =>  $results,
            'failed'       =>  $failed,
        ], 'Create the database tables');
    }

    /**
     * Step 4 - Company and Locale Settings
     * @return ?string
     */
    public function settings(): ?string
    {
        if (Request::isPost()) {
            $input = Request::inputs();

            foreach (['app_name' => 'A company name is required.',
                      'app_host' => 'An application URL is required.',
                      'app_email' => 'A billing email address is required.'] as $field => $message) {
                if (trim((string) ($input[$field] ?? '')) === '') {
                    Request::addError($field, $message);
                }
            }

            if (filter_var($input['app_email'] ?? '', FILTER_VALIDATE_EMAIL) === false) {
                Request::addError('app_email', 'That does not look like an email address.');
            }

            if (Request::errors() === []) {
                $this->installer->saveSettings($input);

                Redirect::with('Settings saved.', true)->to('install.admin');
                return null;
            }
        }

        return $this->view('settings', 'settings', [
            'defaults'         =>  $this->settingDefaults(),
            'timezones'        =>  $this->timezones(),
            'currencies'       =>  $this->currencies(),
            'date_formats'     =>  $this->dateFormats(),
            'datetime_formats' =>  $this->dateTimeFormats(),
        ], 'Tell us about your business');
    }

    /**
     * Step 5 - The First Administrator
     * @return ?string
     */
    public function admin(): ?string
    {
        if (Request::isPost()) {
            $input = Request::inputs();

            foreach (['first_name' => 'A first name is required.',
                      'last_name'  => 'A last name is required.',
                      'username'   => 'A username is required.',
                      'email'      => 'An email address is required.'] as $field => $message) {
                if (trim((string) ($input[$field] ?? '')) === '') {
                    Request::addError($field, $message);
                }
            }

            if (filter_var($input['email'] ?? '', FILTER_VALIDATE_EMAIL) === false) {
                Request::addError('email', 'That does not look like an email address.');
            }

            if (Request::errors() === []) {
                try {
                    $this->installer->createAdmin($input);

                    Redirect::with('Administrator created.', true)->to('install.finish');
                    return null;
                } catch (Throwable $e) {
                    Request::addError('password', $e->getMessage());
                }
            }
        }

        return $this->view('admin', 'admin', [
            'password_min' =>  max(6, (int) (option_int('password_min_length', 8))),
        ], 'Create your administrator account');
    }

    /**
     * Step 6 - Finish
     * @return ?string
     */
    public function finish(): ?string
    {
        $locked = $this->installer->isInstalled();

        if (Request::isPost() && !$locked) {
            // Installer::finish() refuses without an administrator, because the
            // lock is what closes the wizard - locking with no account to sign
            // in with would strand the operator.
            if (!$this->installer->hasAdmin()) {
                Redirect::with('Create an administrator account first.', false)->to('install.admin');
                return null;
            }

            $this->installer->finish();

            // Redirect rather than render: the lock is what LBM\Pipeline\Install
            // reads, and a straight render would still be inside the request
            // that wrote it.
            Redirect::with('Installation complete.', true)->to('install.finish');
            return null;
        }

        return $this->view('finish', 'finish', [
            'locked' =>  $locked,
        ], $locked ? 'All done' : 'Finish the installation');
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Render a Step
     * @param string $step Step Key
     * @param string $view View Path
     * @param array $vars Variables
     * @param string $caption Sub-heading
     * @return string
     */
    private function view(string $step, string $view, array $vars, string $caption): string
    {
        return $this->render($view, array_merge([
            'steps'        =>  self::STEPS,
            'current_step' =>  $step,
            'done_steps'   =>  $this->installer->completed(),
            'step_caption' =>  $caption,
            'page_title'   =>  self::STEPS[$step] ?? 'Install',
        ], $vars));
    }

    /**
     * Sensible Starting Values For The Settings Form
     * @return array<string,string>
     */
    private function settingDefaults(): array
    {
        return [
            'app_name'  =>  option('app_name', app_name()),
            'app_host'  =>  rtrim(Url::base(), '/'),
            'app_email' =>  option('app_email', ''),
        ];
    }

    /**
     * Timezones, Grouped Sensibly Enough To Scan
     * @return array<string,string>
     */
    private function timezones(): array
    {
        $zones = ['UTC' => 'UTC'];

        foreach (\DateTimeZone::listIdentifiers() as $zone) {
            $zones[$zone] = $zone;
        }

        return $zones;
    }

    /**
     * Seeded Currencies
     * @return array<string,string>
     */
    private function currencies(): array
    {
        $choices = [];

        try {
            foreach ((new CurrencyModel())->order('currency_code', 'ASC')->get() as $row) {
                $code = (string) $row['currency_code'];
                $choices[$code] = trim($code . ' ' . ($row['prefix_symbol'] ?? ''));
            }
        } catch (Throwable) {
            // The migrate step has not run yet - offer the one the seed creates.
        }

        if ($choices !== []) return $choices;

        return [
            'USD' => 'USD $',
            'EUR' => 'EUR €',
            'GBP' => 'GBP £',
            'JPY' => 'JPY ¥',
            'CNY' => 'CNY ¥',
            'INR' => 'INR ₹',
            'BDT' => 'BDT ৳',
            'AUD' => 'AUD $',
            'CAD' => 'CAD $',
            'NZD' => 'NZD $',
            'SGD' => 'SGD $',
            'HKD' => 'HKD $',
            'CHF' => 'CHF Fr',
            'SEK' => 'SEK kr',
            'NOK' => 'NOK kr',
            'DKK' => 'DKK kr',
            'PLN' => 'PLN zł',
            'RUB' => 'RUB ₽',
            'UAH' => 'UAH ₴',
            'TRY' => 'TRY ₺',
            'KRW' => 'KRW ₩',
            'THB' => 'THB ฿',
            'MYR' => 'MYR RM',
            'IDR' => 'IDR Rp',
            'PHP' => 'PHP ₱',
            'VND' => 'VND ₫',
            'PKR' => 'PKR ₨',
            'LKR' => 'LKR Rs',
            'NPR' => 'NPR Rs',
            'AED' => 'AED د.إ',
            'SAR' => 'SAR ﷼',
            'QAR' => 'QAR ﷼',
            'KWD' => 'KWD د.ك',
            'BHD' => 'BHD .د.ب',
            'OMR' => 'OMR ﷼',
            'JOD' => 'JOD د.ا',
            'ILS' => 'ILS ₪',
            'EGP' => 'EGP £',
            'ZAR' => 'ZAR R',
            'NGN' => 'NGN ₦',
            'GHS' => 'GHS ₵',
            'KES' => 'KES KSh',
            'TZS' => 'TZS TSh',
            'UGX' => 'UGX USh',
            'MAD' => 'MAD د.م.',
            'BRL' => 'BRL R$',
            'MXN' => 'MXN $',
            'ARS' => 'ARS $',
            'CLP' => 'CLP $',
            'COP' => 'COP $',
            'PEN' => 'PEN S/',
        ];
    }

    /**
     * Date Format Choices, Labelled With Today's Date
     * @return array<string,string>
     */
    private function dateFormats(): array
    {
        $formats = ['Y-m-d', 'd/m/Y', 'm/d/Y', 'd M Y', 'M d, Y', 'd-m-Y'];

        return $this->labelled($formats);
    }

    /**
     * Date and Time Format Choices
     * @return array<string,string>
     */
    private function dateTimeFormats(): array
    {
        $formats = ['Y-m-d H:i', 'Y-m-d h:i A', 'd/m/Y H:i', 'm/d/Y h:i A', 'd M Y H:i', 'd M Y h:i A'];

        return $this->labelled($formats);
    }

    /**
     * Label Each Format With What It Produces
     *
     * "Y-m-d" means nothing to most people; "2026-08-27" does.
     * @param string[] $formats Formats
     * @return array<string,string>
     */
    private function labelled(array $formats): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $choices = [];

        foreach ($formats as $format) {
            $choices[$format] = $now->format($format);
        }

        return $choices;
    }
}
