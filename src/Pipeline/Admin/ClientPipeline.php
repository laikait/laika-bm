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
namespace LBM\Pipeline\Admin;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Laika\Route\Interfaces\PipelineInterface;
use Laika\Service\Redirect;
use LBM\Service\ClientNote;
use Laika\Service\Request;
use LBM\Service\Client;

class ClientPipeline implements PipelineInterface
{
    /**
     * @param callable $next
     * @param array $params
     */
    public function handle(callable $next, array &$params): ?string
    {
        // Handle Request
        $res = match (Request::input('client')) {
            'note'                  =>  ClientNote::addNote(Request::input('cid')),
            'add'                   =>  Client::addClient(),
            'edit'                  =>  Client::modifyClient((int) $params['client']),
            'reset_password'        =>  Client::resetPasswordByStaff((int) $params['client']),
            'reset_security_code'   =>  Client::resetSecurityCodeByStaff((int) $params['client']),
            default                 =>  null
        };

        // Set Alert If Has Message
        if (is_array($res)) alert_set($res['message'], $res['status']);

        return $next();
    }
}
