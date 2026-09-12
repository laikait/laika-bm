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

namespace LBM\Module\Contracts;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

/**
 * A module that declares what an operator fills in - Phase 40.
 *
 * Optional, and implemented beside a kind's own contract: a module with nothing
 * to configure does not implement it, and is not asked.
 *
 * ---------------------------------------------------------------------------
 * settings() IS STATIC, AND THAT IS THE POINT
 * ---------------------------------------------------------------------------
 * A driver is constructed WITH its settings, so the form that collects them has
 * to be drawn before there is a driver to ask. A static declaration can be read
 * off the class. It must be a declaration and nothing else - no network, no
 * database, no file - because it is read every time the form is drawn.
 *
 * Each field, keyed by its name (letters, digits and underscores, starting with
 * a letter; `mode` is taken by the Live/Test switch):
 *
 *     'api_key' => [
 *         'label'    => 'API key',            // what the operator reads
 *         'type'     => 'password',           // text | password | textarea | select | yesno | number
 *         'required' => true,
 *         'default'  => null,                 // used when nothing is saved
 *         'options'  => [],                   // select only: value => label
 *         'secret'   => true,                 // implied by `password`
 *         'help'     => 'From the dashboard.',
 *     ],
 *
 * A secret is sealed before it is stored, never shown again, and a blank box on
 * the form means "keep what is saved". Everything the operator saves reaches
 * the driver's constructor opened, under the declared names, with `mode`
 * beside it - see `LBM\Module\Api` for what `mode` means.
 *
 * ---------------------------------------------------------------------------
 * test() ANSWERS THE "TEST CONNECTION" BUTTON
 * ---------------------------------------------------------------------------
 * Called on a driver built with the saved settings, in the saved mode. Make one
 * cheap, harmless call to the provider - read an account, ping - and say what
 * happened in words an operator can act on: the message is shown to them as it
 * is. It must not throw; a throw is reported as a failure.
 */
interface Configurable
{
    /**
     * The Fields An Operator Fills In
     * @return array<string,array{
     *     label?: string,
     *     type?: string,
     *     required?: bool,
     *     default?: mixed,
     *     options?: array<string,string>,
     *     secret?: bool,
     *     help?: string
     * }>
     */
    public static function settings(): array;

    /**
     * Try The Saved Settings Against The Provider
     * @return array{success: bool, message: string}
     */
    public function test(): array;
}
