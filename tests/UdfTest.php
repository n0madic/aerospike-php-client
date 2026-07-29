<?php

namespace Aerospike;

use PHPUnit\Framework\TestCase;

class UdfTest extends TestCase{
    protected static $client;
    protected static $namespace = "test";
    protected static $hosts;
    protected static $set;
    protected static $key;
    protected static $udfBody = 'function testFunc1(rec, div)
    local ret = map();                     -- Initialize the return value (a map)

    local x = rec["bin1"];                 -- Get the value from record bin named "bin1"

    rec["bin2"] = math.floor(x / div);     -- Set the value in record bin named "bin2"

    aerospike:update(rec);                 -- Update the main record

    ret["status"] = "OK";                   -- Populate the return status
    return ret;                             -- Return the Return value and/or status
end';

    public static function setUpBeforeClass(): void
    {
        try {
            self::$hosts = getenv('AEROSPIKE_HOSTS') ?: '127.0.0.1:3000';
            self::$client = Client::connect(self::$hosts);
        } catch (AerospikeException $e) {
            throw $e;
        }
    }

    protected function randomString($length) {
        $randomBytes = random_bytes($length);

        $randomString = base64_encode($randomBytes);

        $randomString = preg_replace('/[^a-zA-Z0-9]/', '', $randomString);
        $randomString = substr($randomString, 0, $length);

        return $randomString;
    }

    /**
     * Every test owns a freshly registered UDF with a unique package name and drops it
     * again, so no test depends on another test's leftovers and nothing is left behind
     * on the server. Previously `testRunUdfOnASingleRecord` registered `udf1.lua` and
     * never dropped it, and `testListAllUdf` silently relied on that leftover.
     */
    private function withRegisteredUdf(callable $body): void
    {
        $package = "phpunit_udf_" . self::randomString(8);
        $wp = new WritePolicy();
        self::$client->registerUdf($wp, self::$udfBody, "$package.lua", UdfLanguage::lua());
        try {
            $body($package);
        } finally {
            self::$client->dropUdf($wp, "$package.lua");
        }
    }

    /**
     * Server-side file names of every registered UDF, e.g. "udf1.lua". Note this is
     * `getFilename()`, not `getPackageName()` — the latter is the extension-less module
     * name and is not what registerUdf()/dropUdf() operate on.
     */
    private function listUdfFilenames(): array
    {
        $rp = new ReadPolicy();
        return array_map(
            fn (UdfMeta $u) => $u->getFilename(),
            self::$client->listUdf($rp)
        );
    }

    public function testRunUdfOnASingleRecord(){
        self::withRegisteredUdf(function (string $package) {
            $wp = new WritePolicy();
            self::$key = new Key(self::$namespace, self::randomString(random_int(5, 10)), self::randomString(random_int(5, 10)));

            $bin1 = new Bin("bin1", 20);
            $bin2 = new Bin("bin2", 1);
            self::$client->put($wp, self::$key, [$bin1, $bin2]);

            $res = self::$client->udfExecute($wp, self::$key, $package, "testFunc1", [2]);
            $this->assertEquals($res["status"], "OK");
            usleep(300000);

            $rp = new ReadPolicy();
            $rec = self::$client->get($rp, self::$key);
            $this->assertEquals($rec->getBins()["bin2"], 10);
            $this->assertEquals($rec->getBins()["bin1"], 20);
        });
    }

    public function testListAllUdf(){
        self::withRegisteredUdf(function (string $package) {
            $rp = new ReadPolicy();
            $udfs = self::$client->listUdf($rp);
            $this->assertGreaterThan(0, count($udfs));

            // The UDF registered by this test — and only by this test — must be listed.
            $filenames = array_map(fn (UdfMeta $u) => $u->getFilename(), $udfs);
            $this->assertContains("$package.lua", $filenames);

            // getPackageName() is the module name without the extension; getFilename()
            // carries it. Both views must be present for the same entry.
            $names = array_map(fn (UdfMeta $u) => $u->getPackageName(), $udfs);
            $this->assertContains($package, $names);

            $mine = array_values(array_filter($udfs, fn (UdfMeta $u) => $u->getFilename() === "$package.lua"));
            $this->assertCount(1, $mine);
            $this->assertNotEmpty($mine[0]->getHash());
            $this->assertInstanceOf(UdfLanguage::class, $mine[0]->getLanguage());
        });
    }

    public function testDropUdf(){
        $package = "phpunit_udf_" . self::randomString(8);
        $wp = new WritePolicy();
        self::$client->registerUdf($wp, self::$udfBody, "$package.lua", UdfLanguage::lua());
        $this->assertContains("$package.lua", self::listUdfFilenames());

        self::$client->dropUdf($wp, "$package.lua");
        $this->assertNotContains("$package.lua", self::listUdfFilenames());
    }
}
