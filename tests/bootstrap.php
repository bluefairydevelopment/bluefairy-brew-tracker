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
