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

use RuntimeException;
use LBM\Model\EmailTemplateModel;

/**
 * Renders an `email_templates` row into a subject and body.
 *
 * Templates are edited by the operator in the admin area, so the substitution
 * language is deliberately tiny: {{placeholder}} and nothing else. No
 * conditionals, no loops, no expressions - a template language powerful enough
 * to be useful in a database field is powerful enough to be a remote code
 * execution bug, and Twig is not pointed at this content for exactly that
 * reason.
 *
 * Placeholder values are HTML-escaped on the way in, so a client whose company
 * name contains a tag cannot inject markup into an email that goes to somebody
 * else. A value that is genuinely meant to be markup - a rendered invoice
 * table - is passed through raw() instead, which is the explicit opt-out.
 */
class Templater
{
    /** @var string What a Placeholder Looks Like */
    public const PATTERN = '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/';

    /** @var array<string,array>|null Templates, Keyed By Slug */
    private ?array $templates = null;

    /** @var array<string,string> Values That Must Not Be Escaped */
    private array $raw = [];

    ####################################################################################
    /*================================= EXTERNAL API =================================*/
    ####################################################################################

    /**
     * Render a Template By Slug
     * @param string $slug Template Slug. Example: 'invoice-created'
     * @param array<string,mixed> $variables Placeholder Values
     * @return array{subject:string,html:string,plain:string,template:array}
     * @throws RuntimeException
     */
    public function render(string $slug, array $variables = []): array
    {
        $template = $this->find($slug);

        if ($template === null) {
            throw new RuntimeException("No email template named [{$slug}].");
        }

        if (($template['is_active'] ?? 'yes') !== 'yes') {
            throw new RuntimeException("The email template [{$slug}] is switched off.");
        }

        $variables = $this->withDefaults($variables);

        $html = $this->substitute((string) ($template['body'] ?? ''), $variables);

        return [
            'subject'  =>  $this->substitute((string) ($template['subject'] ?? ''), $variables),
            'html'     =>  $html,
            'plain'    =>  $this->plain($html),
            'template' =>  $template,
        ];
    }

    /**
     * Render Arbitrary Text Rather Than a Stored Template
     *
     * For the test message on the mail settings screen, which has no template
     * row behind it.
     * @param string $subject Subject
     * @param string $body Body
     * @param array<string,mixed> $variables Placeholder Values
     * @return array{subject:string,html:string,plain:string}
     */
    public function renderText(string $subject, string $body, array $variables = []): array
    {
        $variables = $this->withDefaults($variables);
        $html = $this->substitute($body, $variables);

        return [
            'subject' =>  $this->substitute($subject, $variables),
            'html'    =>  $html,
            'plain'   =>  $this->plain($html),
        ];
    }

    /**
     * Mark Values As Already-Safe Markup
     *
     * The opt-out from escaping, and deliberately explicit: a caller has to name
     * each placeholder it wants passed through raw, so nothing becomes unescaped
     * by accident.
     * @param array<string,string> $values Placeholder => Markup
     * @return static
     */
    public function raw(array $values): static
    {
        $this->raw = array_merge($this->raw, $values);

        return $this;
    }

    /**
     * Find a Template By Slug
     * @param string $slug Template Slug
     * @return ?array
     */
    public function find(string $slug): ?array
    {
        return $this->all()[strtolower(trim($slug))] ?? null;
    }

    /**
     * Every Template, Keyed By Slug
     * @return array<string,array>
     */
    public function all(): array
    {
        if ($this->templates !== null) {
            return $this->templates;
        }

        $templates = [];

        foreach ((new EmailTemplateModel())->order('name', 'ASC')->get() as $row) {
            $templates[strtolower((string) $row['slug'])] = $row;
        }

        return $this->templates = $templates;
    }

    /**
     * The Placeholders a Template Actually Uses
     *
     * Read out of the body rather than out of the `variables` column: the column
     * is documentation the operator can edit and get wrong, the body is the
     * thing that will really be substituted.
     * @param string $slug Template Slug
     * @return string[]
     */
    public function placeholders(string $slug): array
    {
        $template = $this->find($slug);

        if ($template === null) {
            return [];
        }

        $found = [];

        foreach ([(string) ($template['subject'] ?? ''), (string) ($template['body'] ?? '')] as $text) {
            if (preg_match_all(self::PATTERN, $text, $matches)) {
                $found = array_merge($found, $matches[1]);
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * Forget The Cached Templates
     *
     * Call after editing one, or the rest of the request keeps rendering the
     * version that was just replaced.
     * @return void
     */
    public function flush(): void
    {
        $this->templates = null;
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Replace Every Placeholder In Some Text
     *
     * A placeholder with no matching value is removed rather than left in place.
     * "Hello {{first_name}}" reaching a customer with the braces still showing
     * is worse than "Hello ".
     * @param string $text Text
     * @param array<string,mixed> $variables Placeholder Values
     * @return string
     */
    private function substitute(string $text, array $variables): string
    {
        return (string) preg_replace_callback(
            self::PATTERN,
            function (array $match) use ($variables): string {
                $key = $match[1];

                if (array_key_exists($key, $this->raw)) {
                    return $this->raw[$key];
                }

                if (!array_key_exists($key, $variables)) {
                    return '';
                }

                $value = $variables[$key];

                if (is_array($value) || is_object($value)) {
                    return '';
                }

                return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            },
            $text
        );
    }

    /**
     * The Placeholders Every Template Can Rely On
     *
     * Merged under the caller's, so a caller can override any of them.
     * @param array<string,mixed> $variables Placeholder Values
     * @return array<string,mixed>
     */
    private function withDefaults(array $variables): array
    {
        return array_merge([
            'app_name'    =>  option('app_name', 'Laika Bill Manager'),
            'app_host'    =>  option('app_host', ''),
            'app_email'   =>  option('app_email', ''),
            'client_area' =>  rtrim((string) option('app_host', ''), '/') . '/' . PANEL,
            'year'        =>  date('Y'),
            'date'        =>  format_date(date('Y-m-d H:i:s')),
        ], $variables);
    }

    /**
     * Derive a Plain-Text Body From The HTML One
     *
     * Every queued message carries both, because a mail client that cannot
     * render HTML should show something readable rather than raw markup.
     * @param string $html HTML Body
     * @return string
     */
    private function plain(string $html): string
    {
        // Line breaks first: strip_tags() would otherwise run every paragraph
        // together into one unbroken block of text.
        $text = preg_replace('/<(br|\/p|\/div|\/tr|\/h[1-6])[^>]*>/i', "\n", $html) ?? $html;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Collapse the runs of blank lines the stripping leaves behind.
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
        $text = preg_replace('/[ \t]{2,}/', ' ', $text) ?? $text;

        return trim($text);
    }
}
