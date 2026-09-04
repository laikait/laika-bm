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

use Laika\Service\Nav;
use Laika\Service\Icon;
use Laika\Core\Nav\Helper\Item;

####################################################################################
/*----------------------------------- NAVIGATION ---------------------------------*/
####################################################################################
//
// Every menu in the application is built here and nowhere else. Before this the
// four navs were hand-written Twig, which meant a link existed once per template
// directory - and a template is copied to be re-themed, so adding a screen meant
// remembering every copy. It also meant a module could not add a menu entry at
// all: there is no way for PHP to reach into a Twig literal.
//
// The trees are read back with items() rather than rendered with Nav::render().
// That is not a preference. Renderer always emits <nav><ul><li><a>, and not one
// of LBM's four navs is a list: the two sidebars are flat anchors interleaved
// with non-linking <div> headings, the front bar is flat anchors, and the
// settings strip is a row of buttons. Rendering would have changed the markup of
// every page in the app and required three stylesheets to be reworked, to arrive
// at the same pixels. items() gives the tree, the permission gating, the icons
// and extend() while leaving the markup exactly where it was.
//
// A GROUP is an item that has children. Its own URL is '#' and is never emitted:
// the templates print its title as a heading and then its children as links. The
// heading therefore appears if and only if the group has a visible child, which
// is a behaviour change and a deliberate one - see nav_admin() below.
//
// THE SINGLETON. `Nav` is a relay onto one Builder that lives for the whole
// request, so every function here flushes before it builds. A settings screen
// renders the sidebar AND the tab strip, and without the flush the second would
// inherit the first's items.
//
// The flush is also why a module cannot call Nav::extend() from a pipeline:
// flush() drops queued injections along with the items, and every pipeline runs
// before any of this. So each tree fires its own hook once it is built, and a
// module extends from there - see nav_extend() at the foot of this file.

/**
 * The Admin Sidebar Tree
 *
 * Each entry is gated with staff_has_access(), which reads the same
 * staff_roles.permissions JSON that LBM\Pipeline\Permission enforces on the
 * route. Hiding a link is a courtesy, not a control: a staff member who types
 * the URL is still refused server-side.
 *
 * The group headings used to be gated by hand, and they were gated on the wrong
 * thing - `billing` appeared for client.read or order.read, but the group also
 * holds Products, Invoices and Transactions, so a role holding only invoice.read
 * saw Invoices sitting under no heading at all. `administration` had the same
 * hole for a reports-only role. A heading is now the group itself, so it appears
 * exactly when something is under it and the question cannot be got wrong again.
 *
 * @param ?string $current The section the controller assigned to `nav`
 * @return Item[]
 */
function nav_admin(?string $current = null): array
{
    Nav::flush();

    nav_item('dashboard', 'staff.dashboard', 'dashboard', true);

    // Billing ---------------------------------------------------------------
    $billing = nav_group('billing');
    nav_item('clients',      'staff.clients',      'clients',      staff_has_access('client.read'),      $billing);
    nav_item('products',     'staff.products',     'products',     staff_has_access('product.read'),     $billing);
    nav_item('orders',       'staff.orders',       'orders',       staff_has_access('order.read'),       $billing);
    nav_item('invoices',     'staff.invoices',     'invoices',     staff_has_access('invoice.read'),     $billing);
    nav_item('transactions', 'staff.transactions', 'transactions', staff_has_access('transaction.read'), $billing);

    // Operations ------------------------------------------------------------
    $operations = nav_group('operations');
    nav_item('tickets', 'staff.tickets', 'tickets', staff_has_access('ticket.read'), $operations, 'support');
    nav_item('domains', 'staff.domains', 'domains', staff_has_access('domain.read'), $operations);
    nav_item('servers', 'staff.servers', 'servers', staff_has_access('server.read'), $operations);

    // Site content ----------------------------------------------------------
    //
    // Its own section rather than an entry under Operations: writing an
    // announcement is a different job from answering a ticket, and it is the one
    // place in the admin panel whose output is read by people who are not
    // customers yet.
    $content = nav_group('site_content');
    nav_item('announcements', 'staff.announcements', 'megaphone', staff_has_access('content.read'), $content);
    nav_item('knowledgebase', 'staff.kb',            'book',      staff_has_access('content.read'), $content);

    // Administration --------------------------------------------------------
    $admin = nav_group('administration');
    nav_item('reports',    'staff.reports',    'reports',    staff_has_access('report.read'),   $admin);
    nav_item('staffs',     'staff.staffs',     'staff',      staff_has_access('staff.read'),    $admin, 'staff');
    nav_item('roles',      'staff.roles',      'roles',      staff_has_access('role.read'),     $admin);
    nav_item('currencies', 'staff.currencies', 'currency',   staff_has_access('currency.read'), $admin);
    nav_item('modules',    'staff.modules',    'modules',    staff_has_access('module.read'),   $admin);
    nav_item('activities', 'staff.activities', 'activity',   staff_has_access('activity.read'), $admin, 'activity');
    nav_item('settings',   'staff.settings',   'settings',   staff_has_access('settings.read'), $admin);

    // Utilities share the settings permission rather than carrying one of their
    // own. A new group in Permission::GROUPS is only ever granted when a role is
    // created, so a `utils` group would leave these screens unreachable on every
    // install that already exists - and unreachable in a way whose fix is a
    // checkbox nobody knows to tick. UtilController carries the full reasoning.
    nav_item('utils',      'staff.utils',      'database',   staff_has_access('settings.read'), $admin, 'utilities');
    // Gateways sit on the same permission, for the same reason.
    nav_item('gateways',   'staff.gateways',   'currency',   staff_has_access('settings.read'), $admin, 'payment_gateways');

    return nav_finish('admin', $current);
}

/**
 * The Client Panel Sidebar Tree
 *
 * Gated with client_can(), which is true for the account holder - they own the
 * records - and checks the permission JSON for a sub-login.
 *
 * Far shorter than the admin sidebar, and that is the point. A client has five
 * things to do here, and burying them among sections that exist for the
 * operator's benefit would make all five harder to find.
 *
 * @param ?string $current The section the controller assigned to `nav`
 * @return Item[]
 */
function nav_panel(?string $current = null): array
{
    Nav::flush();

    nav_item('dashboard', 'client.dashboard', 'dashboard', true);

    $account = nav_group('my_account');
    nav_item('services', 'client.services', 'products', client_can('service.read'), $account);
    nav_item('domains',  'client.domains',  'domains',  client_can('domain.read'),  $account);
    nav_item('invoices', 'client.invoices', 'invoices', client_can('invoice.read'), $account);

    $help = nav_group('help');
    nav_item('tickets', 'client.tickets', 'tickets', client_can('ticket.read'),  $help, 'support');
    nav_item('profile', 'client.profile', 'user',    client_can('profile.read'), $help, 'my_details');

    return nav_finish('panel', $current);
}

/**
 * The Public Site Bar
 *
 * Auth-aware but never auth-gated: the front area runs with no Auth pipeline, so
 * nothing here may assume a user. Every entry is public, which is why none of
 * them carries a display test.
 *
 * The right-hand call to action is NOT in this tree. It is one of three mutually
 * exclusive buttons chosen by who is reading - a stranger, a client, a staff
 * member - which is auth logic wearing a link's clothes, and it is styled as a
 * button rather than a nav entry. It stays in the template, where the branch is
 * legible. A module that wants to be on the public bar wants to be in the tree.
 *
 * @param ?string $current The section the controller assigned to `nav`
 * @return Item[]
 */
function nav_front(?string $current = null): array
{
    Nav::flush();

    nav_item('services',      'front.services',      null, true);
    nav_item('knowledgebase', 'front.knowledgebase', null, true);
    nav_item('announcements', 'front.announcements', null, true);
    nav_item('support',       'front.support',       null, true);
    nav_item('contact',       'front.contact',       null, true);

    return nav_finish('front', $current);
}

/**
 * The Settings Tab Strip
 *
 * Every tab is its own route rather than a query parameter, so each one is a URL
 * somebody can bookmark - and so a save can redirect back to exactly the tab it
 * came from. That redirect is not decoration: option() memoises per key for the
 * whole request, so re-rendering after a save would show the value it replaced.
 *
 * No per-tab permission test. Reaching any settings screen at all needs
 * settings.read, which LBM\Pipeline\Permission has already enforced on the route
 * by the time this renders - a second check here could only ever agree.
 *
 * @param ?string $current The tab key the controller passed to the macro
 * @return Item[]
 */
function nav_settings(?string $current = null): array
{
    Nav::flush();

    nav_item('general',      'staff.settings',              'settings', true, null, null, 14);
    nav_item('localisation', 'staff.settings.localisation', 'domains',  true, null, null, 14);
    nav_item('billing',      'staff.settings.billing',      'invoices', true, null, null, 14);
    nav_item('security',     'staff.settings.security',     'key',      true, null, null, 14);
    nav_item('mail',         'staff.settings.mail',         'mail',     true, null, null, 14);
    nav_item('templates',    'staff.settings.templates',    'edit',     true, null, null, 14);
    nav_item('statuses',     'staff.settings.statuses',     'activity', true, null, null, 14);

    return nav_finish('settings', $current);
}

####################################################################################
/*-------------------------------- TREE BUILDING ---------------------------------*/
####################################################################################

/**
 * Add One Entry To The Tree
 *
 * The name is the key the controllers already return from nav(), so the active
 * state is the same string the templates used to compare by hand rather than a
 * second vocabulary that could drift from it.
 *
 * A hidden entry is still constructed and returned - Node::createItem() simply
 * never registers it - so the caller's chain keeps working and the whole subtree
 * falls away with it.
 *
 * @param string $name Section Key, Matching Controller::nav()
 * @param string $route Named Route
 * @param ?string $icon Icon Name For Laika\Service\Icon, Or Null For No Icon
 * @param bool $display False Hides It, Typically A Permission Test
 * @param ?Item $parent Group To Hang It Under, Or Null For Top Level
 * @param ?string $label Language Key For The Title. Defaults To The Name.
 * @param int $size Icon Edge In Pixels
 * @return Item
 */
function nav_item(
    string $name,
    string $route,
    ?string $icon = null,
    bool $display = true,
    ?Item $parent = null,
    ?string $label = null,
    int $size = 16
): Item {
    $title = local($label ?? $name);

    $item = $parent === null
        ? Nav::add($title, $route, [], $display)
        : $parent->child($title, $route, [], $display);

    $item->name($name);

    // svg() rather than icon(): icon() takes a CSS class, and no icon font ships
    // with this application. The markup is emitted verbatim by the renderer and
    // by the templates here, which is safe because it is ours - Icon::svg()
    // returns path data from a fixed table, never anything a user supplied.
    if ($icon !== null) {
        $item->svg(Icon::svg($icon, $size));
    }

    return $item;
}

/**
 * Open a Group
 *
 * The URL is '#' and is never emitted anywhere: Node::resolveUrl() passes a
 * fragment through untouched, which is what lets a heading exist in a tree whose
 * add() otherwise insists on a registered route name.
 *
 * @param string $name Section Key And Language Key
 * @return Item
 */
function nav_group(string $name): Item
{
    return Nav::add(local($name), '#')->name($name);
}

/**
 * Finish a Tree: Let Modules In, Drop Empty Groups, Mark The Current Entry
 *
 * The hook fires before items() so a module's extend() is still in the queue
 * when the queue is drained. Firing it after would leave every injection sitting
 * in $pending until the next tree flushed it away.
 *
 * Empty groups are then removed, and that is what makes the returned array
 * unambiguous: after this, an item with children is a heading and an item
 * without is a link. Leave them in and a group whose every entry was hidden by
 * permissions is indistinguishable from a leaf, so a template walking the tree
 * would render the heading as a link to '#'.
 *
 * The order matters. Dropping them before the hook would delete a group a
 * module was about to put its own first entry into - which is a real case: the
 * core entries under a heading can all be permission-hidden while the module's
 * is allowed.
 *
 * @param string $area Tree Name - admin, panel, front or settings
 * @param ?string $current Section Key To Mark Active
 * @return Item[]
 */
function nav_finish(string $area, ?string $current): array
{
    do_hook('nav_build_' . $area, $area);

    $items = array_values(array_filter(
        Nav::items(),
        static fn(Item $item): bool => $item->getUrl() !== '#' || $item->hasChildren()
    ));

    if ($current !== null && $current !== '') {
        nav_mark($items, $current);
    }

    return $items;
}

/**
 * Mark The Item Whose Name Matches, Depth First
 *
 * Builder::find() would do this in one call, but it searches the live builder -
 * and by this point a module may have added entries through extend(), which are
 * only present in the drained array. Walking what is being returned means the
 * active state is decided on exactly the tree that gets rendered.
 *
 * @param Item[] $items Items To Walk
 * @param string $current Section Key
 * @return bool Whether Something Matched
 */
function nav_mark(array $items, string $current): bool
{
    foreach ($items as $item) {
        if ($item->getName() === $current) {
            $item->active(true);

            return true;
        }

        if (nav_mark($item->getChildren(), $current)) {
            return true;
        }
    }

    return false;
}

/**
 * Add To a Nav From a Module
 *
 * The one supported way for a module to reach a menu. Call it from the module's
 * manifest or a hook file; the callback runs while the named tree is being
 * built, which is the only window in which Nav's queue survives.
 *
 *     nav_extend('admin', function () {
 *         Nav::extend('operations', fn($group) => $group->child('Backups', 'backups.index'));
 *     });
 *
 * Targeting a group that is not there - because the reader lacks the permission
 * that would have shown it - is dropped silently, which is the correct outcome:
 * a module must not be able to reveal a section the role does not hold.
 *
 * @param string $area admin, panel, front or settings
 * @param callable $callback Runs With The Tree Half-Built
 * @param int $priority Hook Priority
 * @return void
 */
function nav_extend(string $area, callable $callback, int $priority = 10): void
{
    add_hook('nav_build_' . $area, $callback, $priority);
}
