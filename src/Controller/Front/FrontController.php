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

use Laika\Model\Model;
use Laika\Service\Request;
use Laika\Service\Redirect;
use Laika\Service\Response;
use LBM\Controller\Controller;

/**
 * Base for every public-website screen.
 *
 * The front area is the one part of this application with no Auth pipeline on
 * it, and that is the whole point: these pages answer to strangers. Nothing
 * here may assume a signed-in user, and nothing here may reach a record that
 * has not been deliberately published.
 *
 * Which makes the two rules below the entire safety story for this area, in
 * place of the role checks the admin panel gets and the ownership scoping the
 * client area gets:
 *
 *   1. Every listing goes through `live()`, which filters on the row's own
 *      active flag. A draft or a switched-off record is not "visible but
 *      styled differently" - it is not in the query.
 *   2. A record fetched by slug and then found to be inactive is the 404 page
 *      through `found()` + `notFound()`, not a rendered record. Returning "this
 *      exists but is unpublished" would leak the existence of unreleased work.
 *
 * There is no admin-style Permission pipeline here because there is no subject
 * to have permissions. There is no client-style `mine()` because there is no
 * owner. Publication state is the only gate, so it lives in the base class
 * rather than being re-typed into a dozen controllers - one of which would
 * eventually forget it.
 */
abstract class FrontController extends Controller
{
    /**
     * The Public Site Template, From The Operator's Settings
     *
     * Pinned to FRONT rather than left to the current request. current_template()
     * reads the URL, and while area() would resolve correctly for a front URL
     * anyway, being explicit means a controller cannot render the wrong area's
     * template if it is ever reached from somewhere unexpected.
     * @return string Example: 'front/bootstrap'
     */
    protected function theme(): string
    {
        return template_dir(FRONT);
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Which Top-Nav Item Is Current
     * @return string
     */
    abstract protected function nav(): string;

    /**
     * Render a Public Screen
     *
     * `meta_description` is passed through to the head. These pages are indexed,
     * unlike every other area in this application, so a view that has a sensible
     * summary should say so; one that does not simply omits the tag rather than
     * repeating the page title back at a search engine.
     * @param string $view View Name Below The Template. Example: 'home'
     * @param string $title Page Title
     * @param array<string,mixed> $vars Variables
     * @return string
     */
    protected function screen(string $view, string $title, array $vars = []): string
    {
        return $this->render($view, array_merge([
            'nav'              =>  $this->nav(),
            'page_title'       =>  $title,
            'meta_description' =>  null,
        ], $vars));
    }

    /**
     * Finish a Write And Send The Visitor Somewhere With a Message
     *
     * The front area has only two writes - the contact form and the article
     * helpfulness vote - but both are POSTs, so both redirect rather than
     * rendering. Re-rendering a POST leaves the browser holding a resubmittable
     * page, and on a public form that means a stranger's back button files the
     * same enquiry twice.
     *
     * Redirect::to() calls exit(), so the return only exists to keep the
     * declared signature honest.
     * @param string $route Route Name
     * @param string $message Flash Message
     * @param bool $ok Whether It Went Well
     * @param array $params Route Parameters
     * @return null
     */
    protected function done(string $route, string $message, bool $ok = true, array $params = []): null
    {
        Redirect::with($message, $ok)->to($route, $params);

        return null;
    }

    ####################################################################################
    /*=================================== VALIDATION =================================*/
    ####################################################################################

    /**
     * Require Some Fields To Be Present
     *
     * The same shape the admin and client areas use, repeated here rather than
     * pulled up into Controller: the three bases are siblings, not a chain, and
     * the day one of them needs to validate differently is the day a shared
     * copy becomes a knot. Three short identical methods are cheaper than that.
     * @param array<string,string> $fields Field => Message
     * @param array $input Submitted Data
     * @return bool True when everything required is there
     */
    protected function require(array $fields, array $input): bool
    {
        foreach ($fields as $field => $message) {
            if (trim((string) ($input[$field] ?? '')) === '') {
                Request::addError($field, $message);
            }
        }

        return Request::errors() === [];
    }

    /**
     * Check An Email Address Looks Like One
     * @param string $field Field Name
     * @param array $input Submitted Data
     * @return void
     */
    protected function requireEmail(string $field, array $input): void
    {
        $value = trim((string) ($input[$field] ?? ''));

        if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            Request::addError($field, local('not_an_email_address'));
        }
    }

    ####################################################################################
    /*================================== PUBLICATION =================================*/
    ####################################################################################

    /**
     * Restrict a Query To Rows The Public May See
     *
     * Every table this area reads carries an enum('yes','no') active flag, and
     * they are not consistently named - `is_active` on announcements and
     * articles, `dep_is_active` on support departments - so the column is a
     * parameter rather than a guess.
     * @param Model $model Model To Constrain
     * @param string $column Active Flag Column
     * @return Model
     */
    protected function live(Model $model, string $column = 'is_active'): Model
    {
        return $model->where([$column => 'yes']);
    }

    /**
     * Render The 404 Page, With a 404 Status
     *
     * ------------------------------------------------------------------------
     * Why this returns a page instead of throwing
     * ------------------------------------------------------------------------
     * `throw new HttpException(404, ...)` is what the admin and client areas do,
     * and in this framework it produces an HTTP **500**. Verified rather than
     * assumed: /admin/client/{bogus} and /admin/invoice/{bogus} both answer 500
     * today, on code written long before this area existed. The error handler
     * does not read HttpException::getStatusCode().
     *
     * Behind a login that is untidy. On a public site it is a real fault - a
     * stale link to a retired article would tell every visitor and every crawler
     * that the server is broken, and a 500 is the one status a search engine
     * treats as "come back later" rather than "this is gone".
     *
     * So the front area does not throw for a missing record. It renders, and
     * sets the status through the Response service - which is the same path the
     * unmatched-URL fallback in helpers/routes/front.php uses, and which is
     * proven to work. http_response_code() would be overwritten, because the
     * renderer writes the service's status last.
     *
     * Fixing the handler is a framework change and is not attempted here.
     * @return string
     */
    protected function notFound(): string
    {
        Response::setStatus(404);

        return $this->screen('404', local('page_not_found'), [
            'meta_description' =>  null,
        ]);
    }

    /**
     * Whether a Lookup Found Something a Visitor May See
     *
     * Deliberately does not distinguish "no such record" from "that record is
     * not published". A visitor who can tell the two apart can enumerate
     * unreleased articles and products by trying slugs, so both answers are the
     * same page.
     * @param ?array $row Row Or Null
     * @return bool
     */
    protected function found(?array $row): bool
    {
        return $row !== null && $row !== [];
    }
}
