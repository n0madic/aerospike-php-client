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

    // Regression test for the documented early-stop pattern: close() the recordset
    // mid-page and then DRAIN it (next() until null). Draining consumes the records the
    // server already delivered and writes the cursor back into the PartitionFilter, so
    // the next scan resumes exactly after the consumed records — nothing is lost and
    // nothing repeats. (Syncing the cursor inside close() itself would be wrong: the
    // upstream tracker records delivered records, not consumed ones, so it would skip
    // whatever was still buffered — that variant lost ~half the records when tried.)
    /**
     * Regression: closing a scan whose queue is full used to deadlock the worker forever.
     *
     * The reader tasks hold the recordset's tracker lock across `push().await`, so a queue
     * nobody drains parks them with that lock held — and reading the pagination cursor at
     * end-of-stream waits on the very same lock. `setRecordQueueSize(1)` makes the queue
     * overflow immediately, which turns a timing-dependent hang into a deterministic one:
     * before the fix this method never returned.
     */
    public function testScanCloseWithFullQueueDoesNotDeadlock()
    {
        $pf = PartitionFilter::all();
        $sp = new ScanPolicy();
        // One slot: the readers block on the second record they produce.
        $sp->setRecordQueueSize(1);

        $started = microtime(true);
        $rs = self::$client->scan($sp, $pf, self::$namespace, self::$set);
        $this->assertNotNull($rs);

        $seen = 0;
        while ($rec = $rs->next()) {
            $seen++;
            if ($seen === 10) {
                // Cancel mid-stream, then keep draining — this is the documented
                // early-stop pattern, and the path that used to hang.
                $rs->close();
            }
        }

        $this->assertGreaterThanOrEqual(10, $seen, "scan must deliver the records read before close()");
        $this->assertLessThan(
            60.0,
            microtime(true) - $started,
            "close() on a full queue must not block the worker"
        );
    }

    public function testScanPaginateWithEarlyCloseAndDrain()
    {
        $pf = PartitionFilter::all();
        $sp = new ScanPolicy();
        $sp->setMaxRecords(25);

        $closeAfter = 5;
        $seen = [];
        // Each non-final page consumes at least one new record, bounding the loop.
        $maxPages = self::$keyCount + 5;
        for ($page = 0; $page < $maxPages; $page++) {
            $rs = self::$client->scan($sp, $pf, self::$namespace, self::$set);
            $this->assertNotNull($rs);

            $thisPage = 0;
            while ($rec = $rs->next()) {
                $digest = $rec->getKey()->getDigest();
                $this->assertArrayNotHasKey(
                    $digest,
                    $seen,
                    "record $digest returned twice — cursor was not preserved across close()+drain"
                );
                $seen[$digest] = true;
                $thisPage++;
                if ($thisPage === $closeAfter) {
                    // Stop fetching more records, but keep iterating: the while loop
                    // drains what is already buffered, then the cursor is synced.
                    $rs->close();
                }
            }
            if ($thisPage === 0) {
                break;
            }
        }

        $this->assertCount(
            self::$keyCount,
            $seen,
            "expected " . self::$keyCount . " unique records after close()+drain pagination, got " . count($seen)
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
            // A page may never exceed the configured max_records cap.
            $this->assertLessThanOrEqual($sp->getMaxRecords(), $recs);
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

    // Regression test: this used to combine max_records=20 with a cancel point of
    // keyCount/2 = 50, so a page never reached the cancel point, close() was never called
    // and the cancellation path was not exercised at all. The scan is now uncapped (every
    // record is streamed by one call), so the cancel point is always reached mid-stream.
    //
    // What is asserted is the observable contract of close(): the recordset flips to
    // inactive and iteration terminates. How *many* records still arrive afterwards is
    // deliberately not asserted — the client buffers up to record_queue_size (1024 by
    // default) records ahead of the consumer, so a 100-record scan may legitimately be
    // fully buffered by the time close() lands.
    public function testScanMustCancel()
    {
        $pf = PartitionFilter::range(0, 4096);
        $sp = new ScanPolicy();

        $cancelAfter = 10;
        $this->assertLessThan(self::$keyCount, $cancelAfter);

        $recordset = self::$client->scan($sp, $pf, self::$namespace, self::$set);
        $this->assertNotNull($recordset);
        $this->assertTrue($recordset->getActive());

        $counter = 0;
        $cancelled = false;
        while ($rec = $recordset->next()) {
            $this->assertEquals(23, $rec->getBins()['AerospikeBin1']);
            $counter++;
            if ($counter === $cancelAfter) {
                $recordset->close();
                $cancelled = true;
                $this->assertFalse($recordset->getActive());
            }
        }

        $this->assertTrue($cancelled, "scan ended before the cancel point — cancellation was never exercised");
        $this->assertFalse($recordset->getActive(), "close() must leave the recordset inactive");
        $this->assertGreaterThanOrEqual($cancelAfter, $counter);
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
