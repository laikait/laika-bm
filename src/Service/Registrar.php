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

namespace LBM\Service;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Laika\Relay\Relay;

/**
 * The registrars domains are registered through.
 *
 * A relay forwards method calls, not constants: `Registrar::FIELDS` fatals
 * here. Reach it through `fields()` beside it, or through the action.
 *
 * @see \LBM\Action\Registrar
 * @method static array choices()
 * @method static string[] credentialNames(array $registrar)
 * @method static array credentials(array $registrar)
 * @method static ?int defaultId()
 * @method static string[] fields()
 * @method static ?array find(int|string|null $key)
 * @method static ?string installedModule(string $module)
 * @method static array listing()
 * @method static Model model()
 * @method static int modify(int|string $key, array $input)
 * @method static array modules()
 * @method static int remove(int|string $key)
 * @method static array settingsFor(array $registrar)
 * @method static string state(array $registrar)
 * @method static int store(array $input)
 * @method static array usage(array $registrar)
 */
class Registrar extends Relay
{
    protected static function getRelayAccessor(): string
    {
        return 'action.registrar';
    }
}
