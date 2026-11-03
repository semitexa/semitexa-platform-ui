<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Workbench;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Lifecycle\CurrentRequestStore;
use Semitexa\Core\Request;
use Semitexa\PlatformUi\Application\Service\Workbench\WorkbenchGate;

/**
 * The Workbench names the class and file behind every component. In dev it
 * opens to this machine only: the dev stack publishes its port on every
 * interface, and until 2026-11-03 "APP_ENV=dev" opened it to the whole Wi-Fi.
 */
final class WorkbenchGateTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['APP_ENV', WorkbenchGate::ENV_FLAG] as $key) {
            $this->saved[$key] = getenv($key);
        }
        self::resetCaches();
    }

    protected function tearDown(): void
    {
        CurrentRequestStore::clear();
        foreach ($this->saved as $key => $value) {
            putenv($value === false ? $key : "{$key}={$value}");
        }
        self::resetCaches();
    }

    #[Test]
    public function dev_opens_it_to_this_machine_only(): void
    {
        putenv('APP_ENV=dev');
        putenv(WorkbenchGate::ENV_FLAG);
        $gate = new WorkbenchGate();

        self::request('127.0.0.1', 'localhost:9507');
        self::assertTrue($gate->allows(), 'a browser on this machine');

        self::request('192.168.100.7', '192.168.100.2:9507');
        self::assertFalse($gate->allows(), 'another machine on the network');

        self::request('127.0.0.1', 'rebind.evil.example:9507');
        self::assertFalse($gate->allows(), 'a public name rebound to loopback');
    }

    #[Test]
    public function a_showcase_deployment_opens_it_to_anyone(): void
    {
        putenv('APP_ENV=prod');
        putenv(WorkbenchGate::ENV_FLAG . '=1');

        self::request('203.0.113.9', 'showcase.example');
        self::assertTrue((new WorkbenchGate())->allows());
    }

    private static function request(string $peer, string $host): void
    {
        CurrentRequestStore::set(new Request('GET', '/__ui/workbench', ['Host' => $host], [], [], ['remote_addr' => $peer], []));
    }

    private static function resetCaches(): void
    {
        foreach (['dev', 'showcase'] as $property) {
            (new \ReflectionProperty(WorkbenchGate::class, $property))->setValue(null, null);
        }
    }
}
