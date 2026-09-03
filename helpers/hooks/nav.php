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

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

####################################################################################
/*----------------------------------- NAV HOOKS ----------------------------------*/
####################################################################################
//
// A template cannot call a plain function, so the four trees reach Twig the way
// everything else in this application does - as hooks, read with the `hook`
// filter laika-core registers:
//
//     {% for item in 'nav_admin'|hook(nav) %}
//
// The argument is the section the controller assigned to `nav`, and it is what
// marks the current entry active. Passing it through the filter rather than
// reading it inside the builder keeps the tree a pure function of the request's
// permissions plus one string, which is what makes it testable without a
// browser.
//
// These are apply_hook filters returning Item[]. The do_hook actions the trees
// fire while building carry the 'nav_build_' prefix instead, and the two must
// not be allowed to converge: registering the builder under the same name it
// fires would make nav_finish() call nav_admin(), which calls nav_finish().
// That recursion is not hypothetical - the first draft of this file had it, and
// it is why nav_extend() exists rather than asking a module to spell the hook
// name out. The separation also means a module can only ADD to a menu; it can
// never replace one by answering the filter first.

/** The admin sidebar tree, active entry marked */
add_hook('nav_admin', 'nav_admin', 1000);

/** The client panel sidebar tree, active entry marked */
add_hook('nav_panel', 'nav_panel', 1000);

/** The public site bar, active entry marked */
add_hook('nav_front', 'nav_front', 1000);

/** The admin settings tab strip, active tab marked */
add_hook('nav_settings', 'nav_settings', 1000);
