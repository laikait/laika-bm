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

namespace LBM\Mail;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Laika\Mailman\Mailer;

/**
 * Builds a configured Laika\Mailman\Mailer from the options table.
 *
 * Every setting is read on demand with option() / option_int() / option_bool(),
 * never from config('mail') - that file exists only so the installer has
 * something to seed `options` from.
 *
 * Resolved lazily by the container: option() needs a database, and during
 * install there is not one yet.
 */
class MailerFactory
{
    /** @var ?Mailer Built Mailer */
    private ?Mailer $mailer = null;

    /**
     * Get The Configured Mailer
     * @return Mailer
     */
    public function mailer(): Mailer
    {
        return $this->mailer ??= new Mailer($this->config());
    }

    /**
     * Read Mail Settings From Options
     *
     * Keys map 1:1 onto Mailer::__construct(), so there is no translation layer.
     * @return array
     */
    public function config(): array
    {
        return [
            'driver'        =>  option('mail_driver', 'smtp'),
            'host'          =>  option('mail_host', 'localhost'),
            'port'          =>  option_int('mail_port', 587),
            'username'      =>  option('mail_username', ''),
            'password'      =>  option('mail_password', ''),
            'encryption'    =>  option('mail_encryption', 'tls'),
            'from'          =>  option('mail_from', ''),
            'from_name'     =>  option('mail_from_name', ''),
            'charset'       =>  option('mail_charset', 'UTF-8'),
            'timeout'       =>  option_int('mail_timeout', 30),
            'debug'         =>  option_int('mail_debug', 0),
            'keepalive'     =>  option_bool('mail_keepalive'),
            'auto_tls'      =>  option_bool('mail_auto_tls'),
            'validate_cert' =>  option_bool('mail_validate_cert'),
        ];
    }

    /**
     * Drop The Built Mailer
     *
     * Call after saving mail settings so the next send picks up new values.
     * @return void
     */
    public function flush(): void
    {
        $this->mailer = null;
    }

    /**
     * Forward Fluent Calls To The Mailer
     *
     * Lets the LBM\Service\Mail facade read as
     * Mail::to($a)->subject($s)->html($b)->send().
     * @param string $method
     * @param array $args
     * @return mixed
     */
    public function __call(string $method, array $args): mixed
    {
        return $this->mailer()->{$method}(...$args);
    }
}
