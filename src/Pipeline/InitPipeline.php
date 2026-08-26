<?php

/**
 * Laika PHP MVC Framework
 * Author: Showket Ahmed
 * Email: riyadhtayf@gmail.com
 * License: MIT
 * This file is part of the Laika PHP MVC Framework.
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

// Namespace
namespace LBM\Pipeline;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Laika\Service\Url;
use Laika\Service\Date;
use Laika\Service\CSRF;
use Laika\Service\CORS;
use Laika\Service\Init;
use Laika\Service\Local;
use Laika\Service\Request;
use Laika\Model\Connection;
use Laika\Session\SessionManager;
use Laika\Route\Interfaces\PipelineInterface;
use LANG;

class InitPipeline implements PipelineInterface
{
    /**
     * @param callable $next
     * @param array $params
     * @return ?string
     */
    public function handle(callable $next, array &$params): ?string
    {
        // Initiate Database
        Init::db();

        // Default Timezone & Format
        Date::setFormat(option('datetime_format', 'Y-m-d H:i:s'))->setAppTimezone(option('time_zone', 'UTC'));

        // Apply Database Session Timezone
        Connection::applyTimezone(Date::getOffset());

        // Set Session in DB
        SessionManager::dbSessionConfig();

        // Initiate Local
        switch (strtolower((string) Url::segment(1))) {
            case strtolower(ADMIN):
                Local::set(option('admin_local', 'en'));
                Local::setPath('admin');
                Local::load();
                break;

            case strtolower(PANEL):
                Local::set(option('panel_local', 'en'));
                Local::setPath('panel');
                Local::load();
                break;
            
            default:
                Local::load();
                break;
        }

        // Validate CSRF
        if (Request::isPost()) {
            try {
                CSRF::validate(CSRF::fromRequest());
            } catch (\Throwable $th) {
                alert_set(LANG::$invalidCsrf, false);
                return $next(false);
            }
        }

        return $next();
    }
}
