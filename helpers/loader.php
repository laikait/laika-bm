<?php

/**
 * Laika Bill Manager
 * Author: Showket Ahmed
 * Email: riyadhtayf@gmail.com
 */

##########################################################################
/*----------------------------- LBM LOADER -----------------------------*/
##########################################################################

declare(strict_types=1);

use Laika\Service\Resource;

// Register All Route Files
Resource::register('routes', __DIR__ . '/Routes');
// Register All Function Files
Resource::register('functions', __DIR__ . '/Functions');
// Register All Hook Files
Resource::register('hooks', __DIR__ . '/Hooks');
// Register All Pipeline Files
Resource::register('pipelines', __DIR__ . '/../src/Pipeline', 'LBM\\Pipeline');
// Register All Model Files
Resource::register('models', __DIR__ . '/../src/Model', 'LBM\\Model');
// Register All Schema Files
Resource::register('schemas', __DIR__ . '/../src/Schema', 'LBM\\Schema');