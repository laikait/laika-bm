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

namespace LBM\Controller;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Throwable;
use Twig\Markup;
use Laika\Service\Url;
use Laika\Core\App\Template;
use Laika\Session\Session;
use Laika\Session\SessionManager;
use LBM\Support\Icon;
use LBM\Service\Money;

/**
 * Base controller: builds the template, registers LBM's Twig filters and
 * assigns the variables every screen needs.
 *
 * Two things here exist to work around how laika-core renders.
 *
 * First, `lf_header()` and `lf_footer()` print metas, styles and scripts by
 * echoing them. Twig's Environment::render() returns a string rather than
 * writing to the output buffer, so a bare echo inside a view escapes the render
 * entirely and lands ahead of the whole page. capture() buffers that output and
 * hands it to the template as a string instead.
 *
 * Second, a template owns its own assets and links them from its own
 * partials/header.twig, using the `template_path` variable assigned below so a
 * template copied to a new name keeps pointing at its own files.
 */
abstract class Controller
{
    /** @var ?Template Built On First Use */
    private ?Template $template = null;

    /** @var array<string,mixed> Variables Assigned So Far */
    private array $vars = [];

    ############################################################################
    /*============================= EXTERNAL API =============================*/
    ############################################################################

    /**
     * Render a View
     *
     * The view name is relative to the template, and the template directory is
     * prefixed here - the one place it happens. laika-core carries the
     * directory in the view name, so this composes 'admin/bootstrap/dashboard'
     * and Template::view() re-points its loader and its cache at that directory
     * before rendering.
     * @param string $view View Name Below The Template. Example: 'dashboard'
     * @param array<string,mixed> $vars Variables
     * @return string
     */
    public function render(string $view, array $vars = []): string
    {
        $template = $this->template();

        $this->enqueue();

        $template->assign($this->shared());
        $template->assign($this->vars);
        $template->assign($vars);

        return $template->view($this->theme() . '/' . $view);
    }

    /**
     * Assign a Variable Before Rendering
     * @param string|array $key Key, or an array of key/value pairs
     * @param mixed $value Value
     * @return static
     */
    public function assign(string|array $key, mixed $value = null): static
    {
        $this->vars = array_merge($this->vars, is_array($key) ? $key : [$key => $value]);

        return $this;
    }

    ############################################################################
    /*============================= INTERNAL API =============================*/
    ############################################################################

    /**
     * The Template Directory This Controller Renders Through
     *
     * A directory below template/, not a bare name: the admin and client areas
     * are themed separately, so the area is part of the path. Overridden by the
     * installer, which has no database and therefore cannot read the option
     * that names a template.
     * @return string Example: 'admin/bootstrap'
     */
    protected function theme(): string
    {
        return current_template();
    }

    /**
     * Build The Template, Once
     *
     * Memoising is safe even though a template is per area: Template::view()
     * re-points its loader and cache on every call, so the instance is not
     * bound to a directory.
     * @return Template
     */
    protected function template(): Template
    {
        if ($this->template !== null) {
            return $this->template;
        }

        $template = new Template();

        $this->filters($template);

        return $this->template = $template;
    }

    /**
     * Load The Template's Own loader.php, Before The Header Is Captured
     *
     * Template::view() loads it too, but only once it begins rendering - and by
     * then shared() has already captured what lf_header() echoes, so every
     * style and script the template enqueued would be registered too late to
     * reach the page head. The symptom is a page that renders correctly with no
     * stylesheet at all.
     *
     * require_once, so view() loading it again a moment later is a no-op.
     * @return void
     */
    private function enqueue(): void
    {
        $loader = APP_PATH . DS . 'template' . DS
            . str_replace('/', DS, $this->theme()) . DS . 'loader.php';

        if (is_file($loader)) {
            require_once $loader;
        }
    }

    /**
     * Register LBM's Twig Filters
     *
     * Filters, not macros: a macro imported in a layout is not visible inside a
     * child template's block, so every view would have to re-import it.
     * @param Template $template Template
     * @return void
     */
    protected function filters(Template $template): void
    {
        /**
         * {{ 'clients'|icon }} or {{ 'trash'|icon(18) }}
         *
         * Returns Twig\Markup, not a string. Twig autoescapes filter output, so
         * a plain string would render the SVG source as visible text. The usual
         * fix is `is_safe` on the filter, but Template::addFilter() takes no
         * options array - and Markup is what the escaper checks for anyway.
         */
        $template->addFilter('icon', static fn(string $name, int $size = 16): Markup => new Markup(Icon::svg($name, $size), 'UTF-8'));

        /** {{ invoice.total|money(invoice.currency_relid) }} */
        $template->addFilter('money', static fn($amount, int|string|null $currency = null): string => Money::format($amount ?? '0', $currency));

        /** {{ invoice.status_relid|status('invoice_statuses') }} */
        $template->addFilter('status', static fn($relid, string $table): array => status_badge($table, $relid));

        /** {{ row.created_at|date_app }} - the operator's format and timezone */
        $template->addFilter('date_app', static fn(?string $time): string => format_date($time));

        /** {{ 1234.5|number }} - grouped, but no currency symbol */
        $template->addFilter('number', static fn($amount): string => decimal($amount ?? 0));

        /**
         * {{ 'add_client'|local }} or {{ 'showing_n_of_m'|local(shown, total) }}
         *
         * Bound to the function by name, the way laika-core binds its own `hook`
         * and `asset` filters. local() is sprintf() over a static property on the
         * global LANG class, so a value's placeholders are %s / %d / %1$s and a
         * literal percent has to be written %%.
         *
         * It throws on a key that is not in LANG rather than returning anything,
         * and that is deliberate: a missing key is a bug to be found now, not an
         * empty region to be shipped. GlobalPipeline::language() loads the
         * catalogue for whichever area is rendering.
         */
        $template->addFilter('local', 'local');

        // No `asset` filter here. laika-core registers one, and as of v4.5.10 its
        // asset() returns the URL rather than echoing it, so `|asset` works in a
        // view as written. LBM's own `asset_url` was a workaround for the old
        // behaviour and is gone.
    }

    /**
     * Variables Every Screen Gets
     * @return array<string,mixed>
     */
    protected function shared(): array
    {
        return [
            // Every one of these reads the `options` table, and the installer
            // renders in a window where the database is connected but has no
            // tables yet - between the database step and the migrate step that
            // creates them. Unguarded, the screen whose job is to create the
            // options table would fatal because it could not read it.
            'app_name'  =>  $this->safe(static fn() => apply_hook('app_name'), 'Laika Bill Manager'),
            'app_host'  =>  $this->safe(static fn() => apply_hook('app_host'), rtrim(Url::base(), '/')),
            'app_logo'  =>  $this->safe(static fn() => app_logo(), ''),
            'app_icon'  =>  $this->safe(static fn() => app_icon(), ''),
            // This template's own directory, for the asset links in its header
            // and footer. A literal path would survive until somebody copied the
            // template to a new name, and then quietly load the original's CSS.
            'template_path' =>  'template/' . $this->theme(),
            'head'      =>  $this->capture('lf_header'),
            'foot'      =>  $this->capture('lf_footer'),
            'alert'     =>  $this->alert(),
            'area'      =>  Url::segment(1) === PANEL ? PANEL : ADMIN,
        ];
    }

    /**
     * Read a Value That Needs The Database, Falling Back If It Is Not There
     *
     * Only the shared chrome uses this. A controller that genuinely needs data
     * should fail loudly rather than render half a page.
     * @param callable $read Reader
     * @param mixed $default Fallback
     * @return mixed
     */
    protected function safe(callable $read, mixed $default = null): mixed
    {
        try {
            $value = $read();
        } catch (Throwable) {
            return $default;
        }

        return ($value === null || $value === '') ? $default : $value;
    }

    /**
     * Read and Clear The Flash Message
     *
     * Popped rather than read. A flash that survived into a second render would
     * keep announcing a save that already happened.
     * @return ?array{message:string,status:bool}
     */
    protected function alert(): ?array
    {
        if (!SessionManager::isConfigured()) {
            return null;
        }

        $alert = Session::get('alert');

        if (empty($alert) || !is_array($alert)) {
            return null;
        }

        Session::pop('alert');

        return [
            'message' =>  (string) ($alert['message'] ?? ''),
            'status'  =>  (bool) ($alert['status'] ?? false),
        ];
    }

    /**
     * Capture What a Hook Echoes
     *
     * laika-core's lf_header()/lf_footer() print directly. Twig renders to a
     * returned string, so their output would otherwise appear before the page
     * rather than inside it.
     * @param string $hook Hook Name
     * @return string
     */
    protected function capture(string $hook): string
    {
        ob_start();

        try {
            apply_hook($hook);
        } finally {
            $output = ob_get_clean();
        }

        return $output === false ? '' : $output;
    }
}
