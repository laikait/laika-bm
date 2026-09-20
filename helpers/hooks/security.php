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

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Laika\Service\CORS;
use Laika\Service\Url;

####################################################################################
/*------------------------------ RESPONSE HEADERS --------------------------------*/
####################################################################################
//
// Phase 52. laika-route calls CORS::handle() on every request, and hook files
// load before it - so this is where its configuration goes.
//
// ORIGINS: NONE. The framework default is `*`, which answers every other
// website's script with Access-Control-Allow-Origin. LBM has no browser API
// for another origin to call: gateway webhooks are server to server and send no
// Origin at all. A billing system should not be readable cross-origin by
// default, so nothing is allowed until the REST API (Phase 65) names its
// origins.
//
// SECURITY HEADERS replace the framework's set wholesale - that is how
// securityHeaders() works - so the defaults worth keeping are repeated:
//
//   - X-Powered-By is dropped - the framework's, and PHP's own, which carries
//     the exact PHP version wherever expose_php is on and which the framework's
//     header used to overwrite. Naming either helps nobody but somebody
//     matching a known bug to a target.
//   - Permissions-Policy turns off the device APIs nothing in LBM uses, so
//     injected script could not reach them either.
//   - HSTS only on a request that arrived over HTTPS. Sent over plain HTTP it is
//     ignored at best, and a site still being set up on HTTP must not be pinned
//     to HTTPS before it has a certificate. No includeSubDomains or preload:
//     the operator's other subdomains are not LBM's to decide for.
//
// No full Content-Security-Policy yet: the themes carry inline script, and a
// CSP that forbids it would break every screen. frame-ancestors is kept -
// clickjacking a "pay now" button is the attack a billing system most needs it
// for.

CORS::origins([]);

$headers = array_filter([
    'X-Content-Type-Options'    =>  'nosniff',
    'Referrer-Policy'           =>  'strict-origin-when-cross-origin',
    'X-Frame-Options'           =>  'SAMEORIGIN',
    'Content-Security-Policy'   =>  "frame-ancestors 'self'",
    'Permissions-Policy'        =>  'camera=(), microphone=(), geolocation=(), usb=()',
    'Strict-Transport-Security' =>  PHP_SAPI !== 'cli' && Url::isHttps() ? 'max-age=31536000' : null,
]);

CORS::securityHeaders($headers);

// TOO LATE, TODAY - so the headers are also corrected directly (plan U15).
//
// lf-boot/app.php calls Dispatcher::dispatchAsset() before it loads any hook
// file, and dispatchAsset() runs CORS::handle() - which Dispatcher then refuses
// to run a second time. So by the time this file runs, the framework defaults,
// `Access-Control-Allow-Origin: *` included, are already queued, and the two
// calls above change nothing for this request. They stay because they are the
// documented way and become the whole job once U15 is fixed upstream.
//
// Until then: take back what the early call granted and send the set above.
// header() replaces a header of the same name, so each one lands once. Nothing
// has been output yet - hook files run before any route - so this is in time.
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header_remove('X-Powered-By');
    header_remove('Access-Control-Allow-Origin');
    header_remove('Access-Control-Allow-Credentials');

    foreach ($headers as $name => $value) {
        header("{$name}: {$value}");
    }
}
