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

namespace LBM\Controller\Front;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

/**
 * The public 404.
 *
 * Reached through Url::fallback() in helpers/routes/front.php, which is the
 * only way an unmatched URL can render anything but the framework's bare error
 * page. With the front area answering on `/`, unmatched URLs are now something
 * a visitor hits by mistyping rather than something only a developer sees, so
 * it is worth them landing somewhere with a way out.
 *
 * ------------------------------------------------------------------------
 * The trap this class exists inside
 * ------------------------------------------------------------------------
 * Dispatcher::dispatchFallback() runs ONLY the pipelines the fallback itself
 * declares. It does not merge in the global ones the way dispatch() does. So
 * the fallback registration has to name Install and GlobalPipeline explicitly,
 * or no language catalogue is loaded and the first local() in this view throws
 * `RuntimeException: 'LANG' Class Doesn't Exists!` - turning a 404 into a 500.
 *
 * The status code is set through the Response service rather than
 * http_response_code(), because the renderer writes the service's status last
 * and a bare call would be overwritten by it.
 */
class ErrorController extends FrontController
{
    /**
     * Which Top-Nav Item Is Current
     * @return string
     */
    protected function nav(): string
    {
        return '';
    }

    /**
     * Nothing Is At That Address
     *
     * The rendering lives on FrontController, because a missing RECORD needs
     * exactly the same page as a missing URL and there should be one of it.
     * This class exists so the fallback has a controller to name.
     * @return string
     */
    public function notFound(): string
    {
        return parent::notFound();
    }
}
