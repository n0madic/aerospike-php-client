<?php

use Aerospike\Client;
use Aerospike\WritePolicy;
use Aerospike\PartitionFilter;
use Aerospike\ScanPolicy;
use Aerospike\Bin;
use Aerospike\InfoPolicy;
use Aerospike\Key;

use PHPUnit\Framework\TestCase;

class ScanTest extends TestCase
{
    protected static $client;
    protected static $namespace = "test";
    protected static $hosts;
    protected static $keyCount = 100;
    protected static $bins;
    protected static $set;
    protected static $keys = [];

    public static function setUpBeforeClass(): void
    {
        try {
            self::$hosts = getenv('AEROSPIKE_HOSTS') ?: '127.0.0.1:3000';
            self::$client = Client::connect(self::$hosts);
        } catch (AerospikeException $e) {
            throw $e;
        }
        self::$bins = [
            new Bin("AerospikeBin1", 23),
            new Bin("AerospikeBin2", "randomString")
        ];
    }

    protected function setUp(): void
    {
        self::$keys = [];
        $wp = new WritePolicy();
        self::$set = self::randomString(random_int(5, 50));

        $ip = new InfoPolicy();
        self::$client->truncate($ip, self::$namespace, self::$set);

        for ($i = 0; $i < self::$keyCount; $i++) {
            $key = new Key(self::$namespace, self::$set, self::randomString(random_int(1, 50) + $i));
            $keyString = $key->getDigest();
            self::$keys[$keyString] = $key;
            self::$client->put($wp, $key, self::$bins);
        }
    }

    private function checkResults($recordset, $cancelCount): int
    {
        $counter = 0;
        $this->assertNotNull($recordset);
        while ($rec = $recordset->next()) {
            $keyString = $rec->getKey()->getDigest();

            $this->assertEquals($rec->getBins()['AerospikeBin1'], 23);
            $this->assertEquals($rec->getBins()['AerospikeBin2'], "randomString");
            unset(self::$keys[$keyString]);

            $counter++;

            //cancel scan stream abruptly
            if ($cancelCount != 0 && $counter == $cancelCount) {
                $recordset->close();
            }
        }
        $this->assertGreaterThan(0, $counter);
        return $counter;
    }

    public function testScanAndPaginateAllPartitionsConcurrently()
    {
        // Paginate across all partitions in fixed-size pages. Each Client::scan() call must
        // resume where the previous one ended; the underlying PartitionFilter doubles as a
        // cursor and is written back when the Recordset is exhausted.
        $pf = PartitionFilter::all();
        $sp = new ScanPolicy();
        $pageSize = 25;
        $sp->setMaxRecords($pageSize);

        $seen = [];
        $pages = 0;
        // Hard cap so a misbehaving cursor cannot loop forever.
        $maxPages = (int) ceil(self::$keyCount / max(1, $pageSize)) + 5;
        while ($pages < $maxPages) {
            $pages++;
            $rs = self::$client->scan($sp, $pf, self::$namespace, self::$set);
            $this->assertNotNull($rs);

            $thisPage = 0;
            while ($rec = $rs->next()) {
                $digest = $rec->getKey()->getDigest();
                // Every record must be returned exactly once across the whole pagination.
                $this->assertArrayNotHasKey(
                    $digest,
                    $seen,
                    "record $digest returned twice — pagination cursor was not advanced"
                );
                $seen[$digest] = true;
                $this->assertSame(23, $rec->getBins()['AerospikeBin1']);
                $this->assertSame('randomString', $rec->getBins()['AerospikeBin2']);
                $thisPage++;
            }
            // The page must not exceed the configured cap and the loop must terminate
            // when the cursor is fully drained (i.e. an empty page after we've consumed
            // every record).
            $this->assertLessThanOrEqual($pageSize, $thisPage);
            if ($thisPage === 0) {
                break;
            }
        }

        $this->assertCount(
            self::$keyCount,
            $seen,
            "expected " . self::$keyCount . " unique records, paginated " . count($seen)
        );
    }

    public function testScanAllPartitionsOneByOne()
    {
        $pf = PartitionFilter::all();
        $sp = new ScanPolicy();
        $sp->setMaxRecords(1);

        $times = 0;
        $received = 0;
        while ($received < self::$keyCount) {
            $times++;
            $recordset = self::$client->scan($sp, $pf, self::$namespace, self::$set);
            $this->assertNotNull($recordset);

            $recs = self::checkResults($recordset, 0);
            $this->assertLessThanOrEqual($recs, $sp->getMaxRecords());
            $received += $recs;
        }
    }

    public function testScanAllPartitions()
    {
        $pf = PartitionFilter::range(0, 4096);
        $sp = new ScanPolicy();
        $sp->setMaxRecords(20);

        $times = 0;
        $received = 0;
        while ($received < self::$keyCount) {
            $times++;
            $recordset = self::$client->scan($sp, $pf, self::$namespace, self::$set);
            $this->assertNotNull($recordset);

            $recs = self::checkResults($recordset, 0);
            $received += $recs;
        }
    }

    public function testScanMustCancel()
    {
        $pf = PartitionFilter::range(0, 4096);
        $sp = new ScanPolicy();
        $sp->setMaxRecords(20);

        $times = 0;
        $received = 0;
        while ($received < self::$keyCount) {
            $times++;
            $recordset = self::$client->scan($sp, $pf, self::$namespace, self::$set);
            $this->assertNotNull($recordset);

            $recs = self::checkResults($recordset, self::$keyCount / 2);
            $received += $recs;
        }
    }

    function randomString($length)
    {
        $randomBytes = random_bytes($length);

        $randomString = base64_encode($randomBytes);

        $randomString = preg_replace('/[^a-zA-Z0-9]/', '', $randomString);
        $randomString = substr($randomString, 0, $length);

        return $randomString;
    }
}
