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

    private ?Request $savedRequest = null;

    protected function setUp(): void
    {
        $this->savedRequest = CurrentRequestStore::get();
        foreach (['APP_ENV', WorkbenchGate::ENV_FLAG] as $key) {
            $this->saved[$key] = getenv($key);
        }
        self::resetCaches();
    }

    protected function tearDown(): void
    {
        if ($this->savedRequest === null) {
            CurrentRequestStore::clear();
        } else {
            CurrentRequestStore::set($this->savedRequest);
        }
        foreach ($this->saved as $key => $value) {
            putenv($value === false ? $key : "{$key}={$value}");
        }
        self::resetCaches();
    }

    #[Test]
    public function dev_opens_it_to_this_machine_only(): void
    {
        putenv('APP_ENV=dev');
        // 0, not unset: unset, a PLATFORM_UI_WORKBENCH=1 in .env would answer.
        putenv(WorkbenchGate::ENV_FLAG . '=0');
        $gate = new WorkbenchGate();

        self::request('127.0.0.1', 'localhost:9507');
        self::assertTrue($gate->allows(), 'a browser on this machine');

        self::request('192.168.100.7', '192.168.100.2:9507');
        self::assertFalse($gate->allows(), 'another machine on the network');

        // The peer alone decides here: a LAN client sending a local Host.
        self::request('192.168.100.7', 'localhost:9507');
        self::assertFalse($gate->allows(), 'another machine claiming localhost');

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

    #[Test]
    public function with_the_showcase_flag_in_dev_another_machine_sees_components_but_no_source(): void
    {
        putenv('APP_ENV=dev');
        putenv(WorkbenchGate::ENV_FLAG . '=1');
        $gate = new WorkbenchGate();

        self::request('192.168.100.7', '192.168.100.2:9507');
        self::assertTrue($gate->allows(), 'the flag opens it to anyone');
        self::assertFalse($gate->showsSource(), 'but class names and files stay on this machine');

        self::request('127.0.0.1', 'localhost:9507');
        self::assertTrue($gate->showsSource());
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
