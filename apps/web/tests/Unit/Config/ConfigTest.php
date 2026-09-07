<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use App\Config\Config;
use Tests\TestCase;

final class ConfigTest extends TestCase
{
    private string $tmpFile;

    protected function setUp(): void
    {
        $this->tmpFile = tempnam(sys_get_temp_dir(), 'env_');
    }

    protected function tearDown(): void
    {
        if (is_file($this->tmpFile)) {
            unlink($this->tmpFile);
        }
    }

    public function testParsesSimpleKeyValueLines(): void
    {
        file_put_contents($this->tmpFile, "APP_ENV=development\nAPP_DEBUG=true\n");

        $config = Config::fromEnvFile($this->tmpFile);

        $this->assertSame('development', $config->get('APP_ENV'));
        $this->assertSame('true', $config->get('APP_DEBUG'));
    }

    public function testIgnoresCommentsAndBlankLines(): void
    {
        file_put_contents($this->tmpFile, "# comment\n\nAPP_ENV=development\n   \n# another\n");

        $config = Config::fromEnvFile($this->tmpFile);

        $this->assertSame('development', $config->get('APP_ENV'));
        $this->assertCount(1, $config->all());
    }

    public function testIgnoresLinesWithoutEquals(): void
    {
        file_put_contents($this->tmpFile, "not-a-kv-line\nAPP_ENV=development\n");

        $config = Config::fromEnvFile($this->tmpFile);

        $this->assertSame('development', $config->get('APP_ENV'));
        $this->assertCount(1, $config->all());
    }

    public function testIgnoresLinesWithEmptyKey(): void
    {
        file_put_contents($this->tmpFile, "=novalue\nAPP_ENV=development\n");

        $config = Config::fromEnvFile($this->tmpFile);

        $this->assertSame('development', $config->get('APP_ENV'));
        $this->assertCount(1, $config->all());
    }

    public function testStripsSurroundingDoubleQuotes(): void
    {
        file_put_contents($this->tmpFile, 'APP_URL="http://localhost:8000"' . "\n");

        $config = Config::fromEnvFile($this->tmpFile);

        $this->assertSame('http://localhost:8000', $config->get('APP_URL'));
    }

    public function testStripsSurroundingSingleQuotes(): void
    {
        file_put_contents($this->tmpFile, "APP_NAME='Homebrewing'\n");

        $config = Config::fromEnvFile($this->tmpFile);

        $this->assertSame('Homebrewing', $config->get('APP_NAME'));
    }

    public function testDoesNotStripMismatchedQuotes(): void
    {
        file_put_contents($this->tmpFile, "VALUE=\"mismatched'\n");

        $config = Config::fromEnvFile($this->tmpFile);

        $this->assertSame("\"mismatched'", $config->get('VALUE'));
    }

    public function testMissingFileYieldsEmptyConfig(): void
    {
        $config = Config::fromEnvFile('/nonexistent/path/.env');

        $this->assertSame([], $config->all());
        $this->assertNull($config->get('ANYTHING'));
    }

    public function testGetReturnsDefaultWhenMissing(): void
    {
        $config = Config::fromArray([]);

        $this->assertSame('fallback', $config->get('MISSING', 'fallback'));
        $this->assertNull($config->get('MISSING'));
    }

    public function testHasReflectsPresence(): void
    {
        $config = Config::fromArray(['FOO' => 'bar']);

        $this->assertTrue($config->has('FOO'));
        $this->assertFalse($config->has('BAZ'));
    }

    public function testRealEnvironmentVariableOverridesFileValue(): void
    {
        file_put_contents($this->tmpFile, "OVERRIDE_ME=from_file\n");
        putenv('OVERRIDE_ME=from_env');

        try {
            $config = Config::fromEnvFile($this->tmpFile);
            $this->assertSame('from_env', $config->get('OVERRIDE_ME'));
        } finally {
            putenv('OVERRIDE_ME');
        }
    }

    public function testFromArrayReturnsGivenData(): void
    {
        $config = Config::fromArray(['A' => '1', 'B' => '2']);

        $this->assertSame(['A' => '1', 'B' => '2'], $config->all());
    }
}
