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

use Throwable;
use LBM\Service\Product;
use LBM\Service\Announcement;

/**
 * The home page, and the fixed informational pages beside it.
 *
 * `/` is a real route rather than a fallback, and it has to stay one. Global
 * pipelines only run once a route has matched, so an unmatched `/` would 404 on
 * a fresh checkout instead of reaching Install and being redirected into the
 * wizard - which is the first thing a new deployment does.
 *
 * The informational pages are Twig views with no database behind them. That was
 * a deliberate choice over a `pages` table: an operator editing their terms of
 * service twice a year does not need a CMS, and a `/{slug}` route to serve one
 * would have to be written carefully enough never to shadow /admin or /panel.
 * A template is one directory - these pages travel with the rest of it.
 */
class HomeController extends FrontController
{
    /**
     * Which Top-Nav Item Is Current
     * @return string
     */
    protected function nav(): string
    {
        return 'home';
    }

    /**
     * The Home Page
     * @return string
     */
    public function index(): string
    {
        return $this->screen('home', local('home'), [
            'meta_description' =>  local('home_meta', app_name()),
            'groups'           =>  $this->safely(static fn() => Product::groups(true), []),
            'announcements'    =>  $this->safely(static fn() => Announcement::latest(3), []),
        ]);
    }

    /**
     * About
     * @return string
     */
    public function about(): string
    {
        return $this->page('about', local('about_us'));
    }

    /**
     * Terms Of Service
     * @return string
     */
    public function terms(): string
    {
        return $this->page('terms', local('terms_of_service'));
    }

    /**
     * Privacy Policy
     * @return string
     */
    public function privacy(): string
    {
        return $this->page('privacy', local('privacy_policy'));
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Render One Fixed Page
     * @param string $view View Name
     * @param string $title Page Title
     * @return string
     */
    private function page(string $view, string $title): string
    {
        return $this->screen($view, $title, ['meta_description' => $title]);
    }

    /**
     * Run a Home-Page Lookup, Or Give Up Quietly
     *
     * The home page is the first thing anybody sees, including the first thing
     * they see after an upgrade that went wrong. A missing product table or a
     * half-migrated database should cost the visitor one empty section, not the
     * whole site - every panel here is decoration around the nav, which is what
     * they actually came for.
     *
     * Deliberately not applied anywhere else in this area. On the announcements
     * or knowledgebase pages the content IS the page, and swallowing an error
     * there would render a convincing "nothing here yet" over a real fault.
     * @param callable $lookup What To Try
     * @param mixed $fallback What To Use If It Throws
     * @return mixed
     */
    private function safely(callable $lookup, mixed $fallback): mixed
    {
        try {
            return $lookup();
        } catch (Throwable) {
            return $fallback;
        }
    }
}
