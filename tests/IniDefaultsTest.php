<?php

namespace Aerospike;

use PHPUnit\Framework\TestCase;

/**
 * Exercises the INI directives registered by the extension:
 *
 *   aerospike.tend_interval       -> ClientPolicy::tend_interval
 *   aerospike.connect_timeout     -> ClientPolicy::timeout
 *   aerospike.read_timeout        -> ReadPolicy::total_timeout
 *   aerospike.write_timeout       -> WritePolicy::total_timeout
 *   aerospike.max_cached_clients  -> soft cap on the per-process client cache
 *   aerospike.worker_threads      -> Tokio runtime worker threads
 *
 * Each test brackets its assertions with ini_set/ini_restore so global state
 * does not leak into the other suites.
 */
final class IniDefaultsTest extends TestCase
{
    /** @var array<string, string> */
    private array $iniBackup = [];

    private const KEYS = [
        'aerospike.tend_interval',
        'aerospike.connect_timeout',
        'aerospike.read_timeout',
        'aerospike.write_timeout',
        'aerospike.max_cached_clients',
        'aerospike.worker_threads',
    ];

    protected function setUp(): void
    {
        foreach (self::KEYS as $key) {
            $this->iniBackup[$key] = ini_get($key);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->iniBackup as $key => $value) {
            ini_set($key, $value);
        }
    }

    public function testIniDirectivesAreRegistered(): void
    {
        foreach (self::KEYS as $key) {
            $this->assertNotFalse(
                ini_get($key),
                "INI directive {$key} should be registered by the extension"
            );
        }
    }

    public function testDefaultZeroMeansNoOverride(): void
    {
        // With INI at the registered default ("0"), policy fields should match the
        // upstream aerospike-client-rust defaults — i.e. nothing was overridden.
        // Pin the current upstream values (aerospike-client-rust v2.1) so an upstream
        // change is flagged loudly rather than silently shifting our defaults.
        ini_set('aerospike.tend_interval', '0');
        ini_set('aerospike.connect_timeout', '0');
        ini_set('aerospike.read_timeout', '0');
        ini_set('aerospike.write_timeout', '0');

        $this->assertSame(1000, (new ClientPolicy())->getTendInterval());
        $this->assertSame(30000, (new ClientPolicy())->getTimeout());
        $this->assertSame(1000, (new ReadPolicy())->getTotalTimeout());
        $this->assertSame(1000, (new WritePolicy())->getTotalTimeout());
    }

    public function testTendIntervalIniOverride(): void
    {
        ini_set('aerospike.tend_interval', '7500');
        $this->assertSame(7500, (new ClientPolicy())->getTendInterval());
    }

    public function testConnectTimeoutIniOverride(): void
    {
        ini_set('aerospike.connect_timeout', '2500');
        $this->assertSame(2500, (new ClientPolicy())->getTimeout());
    }

    public function testReadTimeoutIniOverride(): void
    {
        ini_set('aerospike.read_timeout', '4000');
        $this->assertSame(4000, (new ReadPolicy())->getTotalTimeout());
    }

    public function testWriteTimeoutIniOverride(): void
    {
        ini_set('aerospike.write_timeout', '6000');
        $this->assertSame(6000, (new WritePolicy())->getTotalTimeout());
    }

    public function testExplicitSetterOverridesIni(): void
    {
        ini_set('aerospike.tend_interval', '7500');
        $cp = new ClientPolicy();
        $cp->setTendInterval(2000);
        $this->assertSame(2000, $cp->getTendInterval());
    }

    public function testNegativeIniValueFallsBackToDefault(): void
    {
        // ini_set with a negative value parses fine; ini_long_positive() rejects
        // values <= 0, so the upstream default still applies.
        ini_set('aerospike.tend_interval', '-1');
        $this->assertSame(1000, (new ClientPolicy())->getTendInterval());
    }

    public function testIniReadOnlyAtConstruction(): void
    {
        // Setting INI after construction must not retroactively change the policy.
        $cp = new ClientPolicy();
        $before = $cp->getTendInterval();
        ini_set('aerospike.tend_interval', '9999');
        $this->assertSame($before, $cp->getTendInterval());
    }
}
