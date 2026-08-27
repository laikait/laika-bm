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
 * Second, the framework's `|asset` filter maps to asset(), which also echoes and
 * returns void - so it renders nothing where it is used. LBM registers its own
 * `asset` filter over the top, returning an absolute URL.
 */
abstract class Controller
{
    /** @var ?Template Built On First Use */
    private ?Template $template = null;

    /** @var array<string,mixed> Variables Assigned So Far */
    private array $vars = [];

    ####################################################################################
    /*================================= EXTERNAL API =================================*/
    ####################################################################################

    /**
     * Render a View
     * @param string $view View Path Below The Theme. Example: 'admin/dashboard'
     * @param array<string,mixed> $vars Variables
     * @return string
     */
    public function render(string $view, array $vars = []): string
    {
        $template = $this->template();

        $template->assign($this->shared());
        $template->assign($this->vars);
        $template->assign($vars);

        return $template->view($view);
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

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * The Theme This Controller Renders Through
     *
     * Overridden by the installer, which has no database and therefore cannot
     * read the option that names the theme.
     * @return string
     */
    protected function theme(): string
    {
        return current_template();
    }

    /**
     * Build The Template, Once
     * @return Template
     */
    protected function template(): Template
    {
        if ($this->template !== null) {
            return $this->template;
        }

        // No cache subdirectory. Template::ensureCachePath() resolves its
        // argument as `is_dir($subdir) ? $subdir : APP_PATH/lf-cache/$subdir`,
        // so passing "template/{$theme}" - a directory that exists - makes the
        // theme its own cache and writes compiled PHP in among the .twig files.
        //
        // Per-theme caching is unnecessary anyway: Twig keys the cache off the
        // resolved template path, so two themes never collide on a view of the
        // same name.
        $template = new Template($this->theme());

        $this->filters($template);

        return $this->template = $template;
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
         * {{ 'assets/css/x.css'|asset_url }}
         *
         * Not named `asset`: the framework already registers that name, Twig
         * refuses to redefine a filter, and its version echoes and returns void
         * so it renders nothing where it is used. Views want this one.
         */
        $template->addFilter('asset_url', static fn(string $path): string => lbm_asset($path));
    }

    /**
     * Variables Every Screen Gets
     * @return array<string,mixed>
     */
    protected function shared(): array
    {
        return [
            'app_name'  =>  apply_hook('app_name'),
            'app_host'  =>  apply_hook('app_host'),
            'app_logo'  =>  app_logo(),
            'app_icon'  =>  app_icon(),
            'head'      =>  $this->capture('lf_header'),
            'foot'      =>  $this->capture('lf_footer'),
            'alert'     =>  $this->alert(),
            'area'      =>  Url::segment(1) === PANEL ? PANEL : ADMIN,
        ];
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
