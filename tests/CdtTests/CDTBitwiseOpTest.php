<?php

namespace Aerospike;

use PHPUnit\Framework\TestCase;

class CDTBitwiseOpTest extends TestCase
{

    protected static $client;
    protected static $namespace = "test";
    protected static $hosts;
    protected static $set;
    protected static $key;
    protected static $cdtBinName;

    public static function setUpBeforeClass(): void
    {
        try {
            self::$hosts = getenv('AEROSPIKE_HOSTS') ?: '127.0.0.1:3000';
            self::$client = Client::connect(self::$hosts);
        } catch (AerospikeException $e) {
            throw $e;
        }
    }

    protected function randomString($length)
    {
        $randomBytes = random_bytes($length);

        $randomString = base64_encode($randomBytes);

        $randomString = preg_replace('/[^a-zA-Z0-9]/', '', $randomString);
        $randomString = substr($randomString, 0, $length);

        return $randomString;
    }

    protected function setUp(): void
    {
        self::$set = self::randomString(random_int(5, 10));
        $ip = new InfoPolicy();
        self::$client->truncate($ip, self::$namespace, self::$set);

        self::$key = new Key(self::$namespace, self::$set, self::randomString(random_int(5, 10)));
        self::$cdtBinName = self::randomString(random_int(5, 10));
    }

    /** Expands a byte array into its bit string, MSB first ("11111111" for [0xFF]). */
    private static function bitString(array $bytes): string
    {
        $bits = '';
        foreach ($bytes as $b) {
            $bits .= str_pad(decbin($b & 0xFF), 8, '0', STR_PAD_LEFT);
        }
        return $bits;
    }

    /** Index of the first bit equal to $value inside [$offset, $offset + $size), or -1. */
    private static function expectedLscan(array $bytes, int $offset, int $size, bool $value): int
    {
        $bits = self::bitString($bytes);
        $needle = $value ? '1' : '0';
        for ($i = 0; $i < $size; $i++) {
            if ($bits[$offset + $i] === $needle) {
                return $i;
            }
        }
        return -1;
    }

    /** Index of the last bit equal to $value inside [$offset, $offset + $size), or -1. */
    private static function expectedRscan(array $bytes, int $offset, int $size, bool $value): int
    {
        $bits = self::bitString($bytes);
        $needle = $value ? '1' : '0';
        for ($i = $size - 1; $i >= 0; $i--) {
            if ($bits[$offset + $i] === $needle) {
                return $i;
            }
        }
        return -1;
    }

    /** Number of set bits inside [$offset, $offset + $size). */
    private static function expectedCount(array $bytes, int $offset, int $size): int
    {
        return substr_count(substr(self::bitString($bytes), $offset, $size), '1');
    }

    /** Unsigned integer value of the bits in [$offset, $offset + $size). */
    private static function expectedInt(array $bytes, int $offset, int $size): int
    {
        return bindec(substr(self::bitString($bytes), $offset, $size));
    }

    /** The bits in [$offset, $offset + $size) repacked left-aligned into whole bytes. */
    private static function expectedRegionBytes(array $bytes, int $offset, int $size): array
    {
        $region = str_pad(
            substr(self::bitString($bytes), $offset, $size),
            (int) (ceil($size / 8) * 8),
            '0',
            STR_PAD_RIGHT
        );
        return array_map('bindec', str_split($region, 8));
    }

    /**
     * Applies bitwise modify ops to a bin pre-filled with $bin_sz 0xFF bytes and checks the
     * result twice over: the stored bin must equal $expected, and the read-back ops
     * (lscan/rscan/getInt/count/get) issued in the same `operate()` round trip must agree
     * with what $expected implies.
     *
     * This used to be a `protected function testBitModifyRegion(...)`: PHPUnit only runs
     * public methods, so it never executed — and it had no assertions at all (it built
     * a BatchWrite and dropped it on the floor, never using $expected).
     */
    private function assertBitModifyRegion(int $bin_sz, int $offset, int $set_sz, array $expected, bool $isInsert, ...$ops): void
    {
        $wp = new WritePolicy();
        self::$client->delete($wp, self::$key);
        $initial = array_fill(0, $bin_sz, 0xFF);
        self::$client->put($wp, self::$key, [new Bin(self::$cdtBinName, Value::blob($initial))]);

        $int_sz = min(64, $set_sz);
        $bin_bit_sz = $bin_sz * 8;
        if ($isInsert) {
            $bin_bit_sz += $set_sz;
        }
        $this->assertSame(
            $bin_bit_sz,
            count($expected) * 8,
            "test case is inconsistent: \$expected does not match \$bin_sz/\$isInsert"
        );

        $full_ops = $ops;
        $full_ops[] = BitwiseOp::lscan(self::$cdtBinName, $offset, $set_sz, true);
        $full_ops[] = BitwiseOp::rscan(self::$cdtBinName, $offset, $set_sz, true);
        $full_ops[] = BitwiseOp::getInt(self::$cdtBinName, $offset, $int_sz, false);
        $full_ops[] = BitwiseOp::count(self::$cdtBinName, $offset, $set_sz);
        $full_ops[] = BitwiseOp::lscan(self::$cdtBinName, 0, $bin_bit_sz, false);
        $full_ops[] = BitwiseOp::rscan(self::$cdtBinName, 0, $bin_bit_sz, false);
        $full_ops[] = BitwiseOp::get(self::$cdtBinName, $offset, $set_sz);

        $record = self::$client->operate($wp, self::$key, $full_ops);
        $this->assertNotNull($record);

        // The seven read ops all target the same bin, so their results arrive as a list
        // in operation order; the modify ops return nothing and are not represented.
        $results = $record->getBins()[self::$cdtBinName];
        $this->assertIsArray($results);
        $this->assertCount(7, $results);

        $this->assertSame(self::expectedLscan($expected, $offset, $set_sz, true), $results[0], 'lscan(region, 1)');
        $this->assertSame(self::expectedRscan($expected, $offset, $set_sz, true), $results[1], 'rscan(region, 1)');
        $this->assertSame(self::expectedInt($expected, $offset, $int_sz), $results[2], 'getInt(region)');
        $this->assertSame(self::expectedCount($expected, $offset, $set_sz), $results[3], 'count(region)');
        $this->assertSame(self::expectedLscan($expected, 0, $bin_bit_sz, false), $results[4], 'lscan(bin, 0)');
        $this->assertSame(self::expectedRscan($expected, 0, $bin_bit_sz, false), $results[5], 'rscan(bin, 0)');
        $this->assertInstanceOf(BLOB::class, $results[6]);
        $this->assertSame(self::expectedRegionBytes($expected, $offset, $set_sz), $results[6]->getValue(), 'get(region)');

        // And the bin itself must hold exactly the expected bytes.
        $rp = new ReadPolicy();
        $stored = self::$client->get($rp, self::$key);
        $storedBin = $stored->getBins()[self::$cdtBinName];
        $this->assertInstanceOf(BLOB::class, $storedBin);
        $this->assertSame($expected, $storedBin->getValue());
    }

    public function testBitModifyRegionSet()
    {
        $policy = new BitwisePolicy(BitwiseWriteFlags::Default());
        // [FF FF FF FF] with the second byte overwritten by 0x55.
        self::assertBitModifyRegion(
            4,
            8,
            8,
            [0xFF, 0x55, 0xFF, 0xFF],
            false,
            BitwiseOp::set($policy, self::$cdtBinName, 8, 8, [0x55])
        );
    }

    public function testBitModifyRegionInsert()
    {
        $policy = new BitwisePolicy(BitwiseWriteFlags::Default());
        // A whole byte is inserted, so the bin grows by 8 bits.
        self::assertBitModifyRegion(
            2,
            8,
            8,
            [0xFF, 0x0F, 0xFF],
            true,
            BitwiseOp::insert($policy, self::$cdtBinName, 1, [0x0F])
        );
    }

    public function testBitModifyRegionNot()
    {
        $policy = new BitwisePolicy(BitwiseWriteFlags::Default());
        // Bits 4..11 of [FF FF FF] flipped to 0.
        self::assertBitModifyRegion(
            3,
            0,
            16,
            [0xF0, 0x0F, 0xFF],
            false,
            BitwiseOp::not($policy, self::$cdtBinName, 4, 8)
        );
    }

    protected function assertBitModifyOperations($initial, $expected, ...$ops)
    {
        $wp = new WritePolicy();
        self::$client->delete($wp, self::$key);

        if ($initial !== null) {
            $bins = [new Bin(self::$cdtBinName, Value::blob($initial))];
            self::$client->put($wp, self::$key, $bins);
        }

        $rp = new ReadPolicy();
        $record = self::$client->get($rp, self::$key);
        $bwp = new BatchWritePolicy();
        $bp = new BatchPolicy();

        foreach ($ops as $op) {
            $full_ops[] = $op;
        }
        $batchWrite = new BatchWrite($bwp, self::$key, $full_ops);
        self::$client->batch($bp, [$batchWrite]);
        $record = self::$client->get($rp, self::$key);

        $this->assertEquals($record->getBins()[self::$cdtBinName], Value::blob($expected));
    }

    public function testShouldSetBin()
    {
        $bit0 = [0x80];
        $defaultBitPolicy = new BitwisePolicy(BitwiseWriteFlags::Default());
        $updateBitPolicy = new BitwisePolicy(BitwiseWriteFlags::UpdateOnly());

        self::assertBitModifyOperations(
            [0x01, 0x02, 0x03, 0x04, 0x05, 0x06, 0x07, 0x08],
            [0x51, 0x02, 0x03, 0x04, 0x05, 0x06],
            BitwiseOp::set($defaultBitPolicy, self::$cdtBinName, 1, 1, $bit0),
            BitwiseOp::set($updateBitPolicy, self::$cdtBinName, 3, 1, $bit0),
            BitwiseOp::remove($updateBitPolicy, self::$cdtBinName, 6, 2)
        );
    }

    public function testShouldSetBinsBits()
    {
        $bit0 = [0x80];
        $bits1 = [0x11, 0x22, 0x33];
        $defaultBitPolicy = new BitwisePolicy(BitwiseWriteFlags::Default());

        self::assertBitModifyOperations(
            [
                0x01,
                0x12,
                0x02,
                0x03,
                0x04,
                0x05,
                0x06,
                0x07,
                0x08,
                0x09,
                0x0A,
                0x0B,
                0x0C,
                0x0D,
                0x0E,
                0x0F,
                0x10,
                0x11,
                0x41
            ],
            [
                0x41,
                0x13,
                0x11,
                0x22,
                0x33,
                0x11,
                0x22,
                0x33,
                0x08,
                0x08,
                0x91,
                0x1B,
                0x01,
                0x12,
                0x23,
                0x11,
                0x22,
                0x11,
                0xc1
            ],
            BitwiseOp::set($defaultBitPolicy, self::$cdtBinName, 1, 1, $bit0),
            BitwiseOp::set($defaultBitPolicy, self::$cdtBinName, 15, 1, $bit0),
            BitwiseOp::set($defaultBitPolicy, self::$cdtBinName, 16, 24, $bits1),
            BitwiseOp::set($defaultBitPolicy, self::$cdtBinName, 40, 22, $bits1),
            BitwiseOp::set($defaultBitPolicy, self::$cdtBinName, 73, 21, $bits1),
            BitwiseOp::set($defaultBitPolicy, self::$cdtBinName, 100, 20, $bits1),
            BitwiseOp::set($defaultBitPolicy, self::$cdtBinName, 120, 17, $bits1),
            BitwiseOp::set($defaultBitPolicy, self::$cdtBinName, 144, 1, $bit0)
        );
    }

    public function testShouldLShiftBits()
    {
        $bit0 = [0x80];
        $bits1 = [0x11, 0x22, 0x33];
        $defaultBitPolicy = new BitwisePolicy(BitwiseWriteFlags::Default());

        self::assertBitModifyOperations(
            [
                0x01,
                0x01,
                0x00,
                0x80,
                0xFF,
                0x01,
                0x01,
                0x18,
                0x01
            ],
            [
                0x02,
                0x40,
                0x01,
                0x00,
                0xF8,
                0x08,
                0x01,
                0x28,
                0x01
            ],
            BitwiseOp::lshift($defaultBitPolicy, self::$cdtBinName, 0, 8, 1),
            BitwiseOp::lshift($defaultBitPolicy, self::$cdtBinName, 9, 7, 6),
            BitwiseOp::lshift($defaultBitPolicy, self::$cdtBinName, 23, 2, 1),
            BitwiseOp::lshift($defaultBitPolicy, self::$cdtBinName, 37, 18, 3),
            BitwiseOp::lshift($defaultBitPolicy, self::$cdtBinName, 58, 2, 1),
            BitwiseOp::lshift($defaultBitPolicy, self::$cdtBinName, 64, 4, 7)
        );
    }

    public function testShouldRShiftBits()
    {
        $putMode = new BitwisePolicy(BitwiseWriteFlags::Default());

        self::assertBitModifyOperations(
            [
                0x80,
                0x40,
                0x01,
                0x00,
                0xFF,
                0x01,
                0x01,
                0x18,
                0x80
            ],
            [
                0x40,
                0x01,
                0x00,
                0x80,
                0xF8,
                0xE0,
                0x21,
                0x14,
                0x80
            ],
            BitwiseOp::rshift($putMode, self::$cdtBinName, 0, 8, 1),
            BitwiseOp::rshift($putMode, self::$cdtBinName, 9, 7, 6),
            BitwiseOp::rshift($putMode, self::$cdtBinName, 23, 2, 1),
            BitwiseOp::rshift($putMode, self::$cdtBinName, 37, 18, 3),
            BitwiseOp::rshift($putMode, self::$cdtBinName, 60, 2, 1),
            BitwiseOp::rshift($putMode, self::$cdtBinName, 68, 4, 7)
        );
    }

    public function testShouldORBits()
    {
        $bits1 = [0x11, 0x22, 0x33];
        $putMode = new BitwisePolicy(BitwiseWriteFlags::Default());

        self::assertBitModifyOperations(
            [
                0x80,
                0x40,
                0x01,
                0x00,
                0x00,
                0x01,
                0x02,
                0x03
            ],
            [
                0x90,
                0x48,
                0x01,
                0x20,
                0x11,
                0x11,
                0x22,
                0x33
            ],
            BitwiseOp::or($putMode, self::$cdtBinName, 0, 5, $bits1),
            BitwiseOp::or($putMode, self::$cdtBinName, 9, 7, $bits1),
            BitwiseOp::or($putMode, self::$cdtBinName, 23, 6, $bits1),
            BitwiseOp::or($putMode, self::$cdtBinName, 32, 8, $bits1),
            BitwiseOp::or($putMode, self::$cdtBinName, 40, 24, $bits1)
        );
    }

    public function testShouldXORBits()
    {
        $bits1 = [0x11, 0x22, 0x33];
        $putMode = new BitwisePolicy(BitwiseWriteFlags::Default());

        self::assertBitModifyOperations(
            [
                0x80,
                0x40,
                0x01,
                0x00,
                0x00,
                0x01,
                0x02,
                0x03
            ],
            [
                0x90,
                0x48,
                0x01,
                0x20,
                0x11,
                0x10,
                0x20,
                0x30
            ],
            BitwiseOp::xor($putMode, self::$cdtBinName, 0, 5, $bits1),
            BitwiseOp::xor($putMode, self::$cdtBinName, 9, 7, $bits1),
            BitwiseOp::xor($putMode, self::$cdtBinName, 23, 6, $bits1),
            BitwiseOp::xor($putMode, self::$cdtBinName, 32, 8, $bits1),
            BitwiseOp::xor($putMode, self::$cdtBinName, 40, 24, $bits1)
        );
    }

    public function testShouldANDBits()
    {
        $bits1 = [0x11, 0x22, 0x33];
        $putMode = new BitwisePolicy(BitwiseWriteFlags::Default());

        self::assertBitModifyOperations(
            [
                0x80,
                0x40,
                0x01,
                0x00,
                0x00,
                0x01,
                0x02,
                0x03
            ],
            [0x00, 0x00, 0x00, 0x00, 0x00, 0x01, 0x02, 0x03],
            BitwiseOp::and($putMode, self::$cdtBinName, 0, 5, $bits1),
            BitwiseOp::and($putMode, self::$cdtBinName, 9, 7, $bits1),
            BitwiseOp::and($putMode, self::$cdtBinName, 23, 6, $bits1),
            BitwiseOp::and($putMode, self::$cdtBinName, 32, 8, $bits1),
            BitwiseOp::and($putMode, self::$cdtBinName, 40, 24, $bits1)
        );
    }

    public function testShouldNOTBits()
    {
        $putMode = new BitwisePolicy(BitwiseWriteFlags::Default());

        self::assertBitModifyOperations(
            [0x80, 0x40, 0x01, 0x00, 0x00, 0x01, 0x02, 0x03],
            [0x78, 0x3F, 0x00, 0xF8, 0xFF, 0xFE, 0xFD, 0xFC],
            BitwiseOp::not($putMode, self::$cdtBinName, 0, 5),
            BitwiseOp::not($putMode, self::$cdtBinName, 9, 7),
            BitwiseOp::not($putMode, self::$cdtBinName, 23, 6),
            BitwiseOp::not($putMode, self::$cdtBinName, 32, 8),
            BitwiseOp::not($putMode, self::$cdtBinName, 40, 24)
        );
    }
}
