<?php

namespace Aerospike;

use PHPUnit\Framework\TestCase;

final class ServerInfoTest extends TestCase
{
    protected static $client;
    protected static $hosts;

    // Server requires the .lua suffix on register/drop. listUdf strips the suffix when
    // parsing the udf-list info command response, so the package name we expect in
    // results is the bare base name.
    private static string $testUdfFile = 'server_info_test_hello.lua';
    private static string $testUdfName = 'server_info_test_hello';
    private static string $testUdfBody = 'function hello(rec) return "hi" end';

    public static function setUpBeforeClass(): void
    {
        try {
            self::$hosts = getenv('AEROSPIKE_HOSTS') ?: '127.0.0.1:3000';
            self::$client = Client::connect(self::$hosts);
        } catch (AerospikeException $e) {
            throw $e;
        }
    }

    public static function tearDownAfterClass(): void
    {
        // Best-effort cleanup in case testListUdfRoundTrip did not finish
        try {
            $wp = new WritePolicy();
            self::$client->dropUdf($wp, self::$testUdfFile);
        } catch (\Throwable $e) {
            // Ignore — UDF may already have been removed
        }
    }

    public function testServerVersion(): void
    {
        $result = self::$client->serverVersion();

        $this->assertIsArray($result, 'serverVersion() must return an array');
        $this->assertNotEmpty($result, 'serverVersion() must return a non-empty array');

        foreach ($result as $node => $version) {
            $this->assertIsString($node, 'Node name must be a string');
            $this->assertIsString($version, 'Version value must be a string');
            $this->assertMatchesRegularExpression(
                '/^\d+\.\d+\.\d+/',
                $version,
                "Version for node \"$node\" does not match the expected semver-like pattern"
            );
        }
    }

    public function testListUdfRoundTrip(): void
    {
        $rp = new ReadPolicy();
        $wp = new WritePolicy();

        // Capture baseline list before registering our UDF
        $baseline = self::$client->listUdf($rp);
        $baselineNames = array_map(
            fn(UdfMeta $m) => $m->getPackageName(),
            $baseline
        );

        // Register the test UDF
        self::$client->registerUdf($wp, self::$testUdfBody, self::$testUdfFile, UdfLanguage::lua());

        // Give the cluster a moment to propagate the registration
        usleep(300000);

        // Fetch updated list
        $updated = self::$client->listUdf($rp);

        $updatedNames = array_map(
            fn(UdfMeta $m) => $m->getPackageName(),
            $updated
        );

        $this->assertContains(
            self::$testUdfName,
            $updatedNames,
            'Registered UDF must appear in listUdf() result'
        );

        // Validate every UdfMeta entry has the required non-empty fields
        foreach ($updated as $meta) {
            $this->assertInstanceOf(UdfMeta::class, $meta);
            $this->assertNotEmpty($meta->getPackageName(), 'UdfMeta::getPackageName() must not be empty');
            $this->assertNotEmpty($meta->getHash(), 'UdfMeta::getHash() must not be empty');
            $this->assertNotNull($meta->getLanguage(), 'UdfMeta::getLanguage() must not be null');
        }

        // Clean up the test UDF
        self::$client->dropUdf($wp, self::$testUdfFile);

        // Verify it has been removed
        usleep(300000);
        $afterDrop = self::$client->listUdf($rp);
        $afterDropNames = array_map(
            fn(UdfMeta $m) => $m->getPackageName(),
            $afterDrop
        );

        $this->assertNotContains(
            self::$testUdfName,
            $afterDropNames,
            'Dropped UDF must no longer appear in listUdf() result'
        );
    }
}
