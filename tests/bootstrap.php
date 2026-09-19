<?php
/**
 * Bootstrap for the pure-unit suite.
 *
 * Validation, unit conversion, the field registry and the seeder's
 * idempotence decide whether a maker's record is accepted and what it says,
 * and all of them can be exercised without WordPress: the stubs fake the
 * handful of core functions they touch. Everything that needs a real install
 * is in tests/integration-checks.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/stubs.php';
require_once __DIR__ . '/../includes/class-validate.php';
require_once __DIR__ . '/../includes/class-units.php';
require_once __DIR__ . '/../includes/class-batch.php';
require_once __DIR__ . '/../includes/class-seeder.php';
require_once __DIR__ . '/../includes/class-feature-registry.php';
require_once __DIR__ . '/../includes/class-log.php';
require_once __DIR__ . '/../includes/class-task.php';
require_once __DIR__ . '/../includes/class-stage.php';
require_once __DIR__ . '/../includes/class-abv.php';
require_once __DIR__ . '/../includes/class-chart.php';
require_once __DIR__ . '/../includes/class-csv.php';
require_once __DIR__ . '/../includes/class-batch-cascade.php';
// The admin screen class is loaded for its remembered-input store; its
// handlers and renderers are exercised by the E2E suite.
require_once __DIR__ . '/../admin/class-batch-admin.php';
