<?php
/**
 * Laika Bill Manager
 * Author: Showket Ahmed
 * Email: riyadhtayf@gmail.com
 * License: Proprietary - see LICENSE
 * This file is part of Laika Bill Manager.
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace LBM\Pipeline;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Throwable;
use Laika\Service\Config;
use Laika\Service\Url;
use Laika\Shield\Shield;
use Laika\Shield\ShieldConfig;
use Laika\Shield\Pipeline\ShieldPipeline;
use Laika\Shield\Exceptions\FirewallException;
use LBM\Support\LoginThrottle;

/**
 * laika-shield in front of every route - Phase 52.
 *
 * First in Url::globalPipeline(), ahead of Install: a firewall is only a
 * firewall if nothing has run yet. Settings come from lf-config/shield.php,
 * applied over laika-shield's defaults.
 *
 * Why not ShieldPipeline as it comes: it applies every rule to every request,
 * and two of them are wrong for parts of LBM.
 *
 *   SQL injection and XSS detection look for markup and SQL in the input. Staff
 *   write markup ON PURPOSE - email templates, announcements and knowledge-base
 *   articles are HTML, and a template with <meta> in it would be refused with a
 *   JSON error and the edit lost. So /admin gets no content rules. It is
 *   behind a sign-in, a permission check and CSRF, and every query LBM runs is
 *   parameterised; the detectors are a second net for the public, not a
 *   substitute for those.
 *
 *   Gateway webhooks get no content rules either - a payment processor's JSON
 *   is not ours to second-guess, its signature is what is checked - and no rate
 *   limit, because a gateway retrying a backlog must never be told to go away.
 *   IP rules and request filtering still apply to them.
 *
 * Content rules run with scan.body OFF. With it on, laika-shield also scans the
 * RAW body of a normal form post as one value, `__raw_body__`, and skip.keys
 * cannot exempt anything inside that - so a password containing `'--` would be
 * refused however it was listed. Off, it scans the query string and the parsed
 * form fields, each by name, which is what skip.keys works on.
 *
 * Everything else - the block response, its status and Retry-After, and ending
 * the request there so no later pipeline runs - is ShieldPipeline's own.
 */
class Firewall extends ShieldPipeline
{
    /**
     * @var string[] Fields Never Scanned For SQL Or Markup
     *
     * Passwords are anything by design. The rest are free text a customer is
     * entitled to type - a hosting customer's ticket often quotes the very code
     * that broke. lf-config/shield.php can add to this; it cannot remove from it.
     */
    public const SKIP = [
        'password', 'password_confirm', 'current_password',
        'message', 'subject', 'comment', 'description', 'reason', 'notes', 'note', 'body',
    ];

    /** @var int Requests Per Window Per IP When lf-config/shield.php Sets None */
    public const MAX_HITS = 300;

    public function __construct()
    {
        parent::__construct($this->settings());
    }

    public function handle(callable $next, array &$params): ?string
    {
        try {
            $this->shield()->run();
        } catch (FirewallException $e) {
            $this->respond($e);
        }

        return $next();
    }

    /**
     * The Firewall For This Request
     *
     * Built with the same builder calls, in the same order, as
     * Shield::fromConfig() - which has no way to leave a rule out.
     * @return Shield
     */
    public function shield(): Shield
    {
        $config = ShieldConfig::instance();
        $segment = Url::segment(1);
        $webhook = $segment === GlobalPipeline::WEBHOOK;
        $admin = $segment === ADMIN;

        $shield = (new Shield())->trustProxy($config->trustProxy(), $config->trustedProxies());

        if ($config->country->isConfigured()) {
            $shield->blockCountries($config->country->db(), $config->country->blocklist(), $config->country->allowlist());
        }

        $shield->blockIps($config->ip->blocklist(), $config->ip->allowlist());

        if ($config->ipVersion() !== null) {
            $shield->requireIpVersion($config->ipVersion());
        }

        if (!$webhook) {
            $shield->rateLimit($config->rateLimit->maxHits(), $config->rateLimit->window(), $config->rateLimit->storageDir());
        }

        if (!$webhook && !$admin) {
            $shield->detectSqlInjection(
                skipKeys: array_merge(self::SKIP, $config->sqlInjection->skipKeys()),
                scanBody: false,
                strict:   $config->sqlInjection->strict(),
            );

            $shield->detectXss(
                skipKeys:    array_merge(self::SKIP, $config->xss->skipKeys()),
                scanBody:    false,
                scanHeaders: $config->xss->scanHeaders(),
            );
        }

        $shield->filterRequests(
            blockedMethods: $config->requestFilter->blockedMethods(),
            blockedUriPatterns: $config->requestFilter->blockedUriPatterns(),
            blockedUserAgentPatterns: $config->requestFilter->blockedUserAgents(),
            requiredHeaders: $config->requestFilter->requiredHeaders(),
            blockedHeaderValues: $config->requestFilter->blockedHeaderValues(),
            maxContentLength: $config->requestFilter->contentLengthMax(),
            minContentLength: $config->requestFilter->contentLengthMin(),
        );

        return $shield;
    }

    /**
     * lf-config/shield.php, With LBM's Defaults Underneath
     *
     * The counters go under lf-storage rather than the system temp directory,
     * which php-fpm's private /tmp empties on every restart - a restart would
     * otherwise forgive every client mid-window.
     * @return array<string,mixed>
     */
    private function settings(): array
    {
        try {
            $configured = Config::get('shield');
        } catch (Throwable) {
            $configured = null;
        }

        $configured = is_array($configured) ? $configured : [];

        // Not a laika-shield section - LoginThrottle reads it. fill() would
        // ignore it anyway; dropped so that stays true if it ever stops.
        unset($configured['login']);

        $configured['rate.limit'] = ($configured['rate.limit'] ?? []) + [
            'max.hits'    =>  self::MAX_HITS,
            'storage.dir' =>  APP_PATH . DIRECTORY_SEPARATOR . LoginThrottle::STORAGE,
        ];

        return $configured;
    }
}
