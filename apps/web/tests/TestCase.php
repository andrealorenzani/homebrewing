<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Base test case for the application test suite.
 *
 * Kept deliberately minimal and DB-independent: it makes no assumptions
 * about a database connection or app configuration being available.
 * Later tasks (e.g. DB-backed modules) are expected to add their own
 * dedicated base classes (or traits) that extend this one rather than
 * bake DB setup in here.
 */
abstract class TestCase extends BaseTestCase
{
}
