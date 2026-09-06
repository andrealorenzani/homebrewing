<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Placeholder test proving the composer/PHPUnit/autoload wiring from T1
 * actually works end to end. Later tasks add real coverage for App\ code;
 * this class can be deleted once that exists.
 */
final class ScaffoldTest extends TestCase
{
    public function testTestCaseIsUsable(): void
    {
        $this->assertInstanceOf(TestCase::class, $this);
    }

    public function testPsr4AutoloadingIsWiredForAppNamespace(): void
    {
        // No App\ classes exist yet (that's later tasks), but the autoloader
        // itself must be present and configured for the App\ prefix.
        $autoloadFile = __DIR__ . '/../../vendor/autoload.php';
        $this->assertFileExists($autoloadFile);

        $loader = require $autoloadFile;
        $prefixes = $loader->getPrefixesPsr4();

        $this->assertArrayHasKey('App\\', $prefixes);
        $this->assertSame(
            realpath(__DIR__ . '/../../src'),
            realpath($prefixes['App\\'][0])
        );
    }
}
