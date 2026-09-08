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

use Laika\Queue\Abstracts\Job;
use Laika\Core\App\Resource;
use Laika\Cli\Contracts\CommandInterface;
use Laika\Model\Contract\SchemaAbstract;
use Laika\Route\Contracts\FilterInterface;
use Laika\Route\Contracts\PipelineInterface;
use LBM\Contract\MigrationAbstract;
use LBM\Module\ModuleManager;

####################################################################################
/*----------------------------------- AREAS --------------------------------------*/
####################################################################################
//
// ADMIN and PANEL come from lf-inc/const.php and are both an area name AND a URL
// prefix. FRONT is deliberately not defined there beside them, for two reasons:
//
//   1. The app root stays a thin host - LBM owns its own vocabulary.
//   2. FRONT is ONLY an area name. It names a template directory and a language
//      catalogue; there is no /front URL and there must never be one. Defining it
//      next to two constants that are route prefixes would imply otherwise.
//
// The front area is whatever is not /admin and not /panel, the root included -
// see area() in helpers/functions/app.php.

defined('FRONT') || define('FRONT', 'front');

####################################################################################
/*------------------------------ THE FATAL HOOK ----------------------------------*/
####################################################################################
//
// Here rather than in GlobalPipeline with the rest of the error log, and it is a
// matter of ORDER rather than of importance.
//
// Shutdown functions run in the order they were registered, and laika-core
// registers one in ITS loader - which composer runs AFTER this file. That one
// renders the error page, and on a DEBUG install it renders through Whoops,
// which calls exit() from inside the shutdown sequence. Everything registered
// after it is never reached.
//
// So this cannot wait for the pipeline. A shutdown function registered there is
// registered too late to see a fatal at all, and Phase 32's first draft did
// exactly that: it looked correct, and the one class of failure that leaves a
// request unfinished - a real E_ERROR or E_COMPILE_ERROR, which no exception
// handler can ever see - was also the one class that left no record.
//
// Registering here costs nothing. The function does nothing unless
// error_get_last() reports a fatal, and it carries its own try/catch because at
// this point in the boot there is no database, no option() and possibly no
// schema at all.

register_shutdown_function([LBM\Support\ErrorLog::class, 'shutdown']);

####################################################################################
/*--------------------------------- LBM RESOURCES --------------------------------*/
####################################################################################
//
// Every resource except `relays` is registered here. Relay providers are the one
// resource read during composer's autoload pass, before this file could run, so
// they stay declared in composer.json under extra.laika.resources.
//
// Registration only records where to look - nothing is scanned until a resource is
// actually used, and registering the same directory twice is a no-op.

$src = dirname(__DIR__) . '/src';

// Class Resources
Resource::register('models',      "{$src}/Model",      'LBM\\Model');
Resource::register('schemas',     "{$src}/Schema",     'LBM\\Schema',     SchemaAbstract::class);
// The other half of the same job: up() creates a table that is missing, a
// migration changes one that is already there. Normally an empty directory, and
// that is fine - Resource::scan() short-circuits on a missing or empty path and
// returns no classes rather than throwing.
Resource::register('migrations',  "{$src}/Migration",  'LBM\\Migration',  MigrationAbstract::class);
Resource::register('controllers', "{$src}/Controller", 'LBM\\Controller');
Resource::register('pipelines',   "{$src}/Pipeline",   'LBM\\Pipeline',   PipelineInterface::class);
Resource::register('filters',     "{$src}/Filter",     'LBM\\Filter',     FilterInterface::class);
Resource::register('jobs',        "{$src}/Job",        'LBM\\Job',        Job::class);
Resource::register('commands',    "{$src}/Command",    'LBM\\Command',    CommandInterface::class);

// File Resources
Resource::register('functions',   __DIR__ . '/functions');
Resource::register('hooks',       __DIR__ . '/hooks');
Resource::register('routes',      __DIR__ . '/routes');

####################################################################################
/*----------------------------------- MODULES ------------------------------------*/
####################################################################################
//
// The operator's own code, in APP_PATH/modules. It has to be registered here and
// not later: Dispatcher::dispatch() requires every route file before it matches a
// route, which is before any pipeline runs - so a module's routes have to exist
// by then, and this is the last point that is still early enough.
//
// Which modules are on comes from a generated file rather than the `options`
// table, because at this point in the boot there is no database and no option()
// helper. LBM\Module\ModuleManager explains the whole arrangement. A disabled
// module costs one glob() and nothing else.

ModuleManager::discover();
