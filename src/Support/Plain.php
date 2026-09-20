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

namespace LBM\Support;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

/**
 * A request value as the person typed it - Phase 51.
 *
 * laika-core's default InputSanitizer runs every string input through
 * htmlspecialchars() (docs/02_routing/03_requests.md, "Input Is HTML-Encoded by
 * Default"). That is right for anything shown back on a page, and wrong for a
 * CREDENTIAL: an SMTP password `p&ss` was stored as `p&amp;ss` and the server
 * refused it, and a registrar API URL with `&` in its query string was stored
 * broken - with nothing on the screen to say why.
 *
 * Used only where a value is handed to something other than a template: the
 * mail password and module settings. Everywhere else the encoded value stays,
 * and Twig's auto-escaping keeps output safe either way.
 *
 * The flags are the sanitizer's own (Laika\Core\Sanitizer\InputSanitizer's
 * default), so this is exactly its inverse.
 */
final class Plain
{
    /**
     * Undo The Request Sanitizer's HTML Encoding
     * @param string $value A Value From Request::input()/inputs()
     * @return string
     */
    public static function text(string $value): string
    {
        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
    }
}
