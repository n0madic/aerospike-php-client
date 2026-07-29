<?php

// Stubs for aerospike_php

namespace Aerospike {
    /**
     * `AdminPolicy` encapsulates parameters for all admin operations.
     */
    class AdminPolicy {
        public function __construct() {}

        /**
         * User administration command socket timeout (milliseconds). Default: 3000.
         *
         * @return int
         */
        public function getTimeout(): int {}

        /**
         * @param int $timeout_millis
         * @return void
         */
        public function setTimeout(int $timeout_millis): void {}
    }

    /**
     * Represents an exception specific to the Aerospike database operations.
     *
     * `$code` and `$message` are inherited from \Exception and must not be redeclared
     * with types (fatal "must be omitted to match the parent definition" otherwise);
     * the extension exposes them as public properties at runtime.
     *
     * @property int $code Aerospike result code (see ResultCode)
     * @property string $message Error message
     */
    class AerospikeException extends \Exception {
        public bool $inDoubt;

        public function __construct() {}
    }

    /**
     * Implementation of the BLOB data structure for Aerospike.
     */
    class BLOB {
        public function __construct() {}

        /**
         * Returns a string representation of the value.
         *
         * @return string
         */
        public function asString(): string {}

        /**
         * Returns a string representation of the value.
         *
         * @param \Aerospike\BLOB $other
         * @return bool
         */
        public function equals(\Aerospike\BLOB $other): bool {}

        /**
         * @return string
         */
        public function getBinary(): string {}

        /**
         * @return array
         */
        public function getValue(): array {}

        /**
         * @param array $blob
         * @return void
         */
        public function setValue(array $blob): void {}
    }

    /**
     * BatchDelete encapsulates a batch delete operation. Maps to `aero::BatchOperation::Delete`.
     */
    class BatchDelete {
        /**
         * @param \Aerospike\BatchDeletePolicy $policy
         * @param \Aerospike\Key $key
         */
        public function __construct(\Aerospike\BatchDeletePolicy $policy, \Aerospike\Key $key) {}
    }

    /**
     * BatchDeletePolicy attributes used in batch delete commands.
     */
    class BatchDeletePolicy {
        public function __construct() {}

        /**
         * @return \Aerospike\CommitLevel
         */
        public function getCommitLevel(): \Aerospike\CommitLevel {}

        /**
         * @return bool
         */
        public function getDurableDelete(): bool {}

        /**
         * @return \Aerospike\Expression|null
         */
        public function getFilterExpression(): ?\Aerospike\Expression {}

        /**
         * @return int
         */
        public function getGeneration(): int {}

        /**
         * @return \Aerospike\GenerationPolicy
         */
        public function getGenerationPolicy(): \Aerospike\GenerationPolicy {}

        /**
         * @return bool
         */
        public function getSendKey(): bool {}

        /**
         * @param mixed $commit_level
         * @return void
         */
        public function setCommitLevel(mixed $commit_level): void {}

        /**
         * @param bool $durable_delete
         * @return void
         */
        public function setDurableDelete(bool $durable_delete): void {}

        /**
         * @param mixed $filter_expression
         * @return void
         */
        public function setFilterExpression(mixed $filter_expression = null): void {}

        /**
         * @param int $generation
         * @return void
         */
        public function setGeneration(int $generation): void {}

        /**
         * @param mixed $generation_policy
         * @return void
         */
        public function setGenerationPolicy(mixed $generation_policy): void {}

        /**
         * @param bool $send_key
         * @return void
         */
        public function setSendKey(bool $send_key): void {}
    }

    /**
     * BatchPolicy encapsulates parameters for batch operations.
     *
     * v2 BREAKING: legacy fields `sleep_multiplier`, `send_key`, `use_compression`,
     * `exit_fast_on_exhausted_connection_pool`, `read_mode_sc` have been removed.
     * `concurrent_nodes` was renamed to `concurrency` (enum Sequential/Parallel).
     * `allow_partial_results` has been removed (controlled via `respond_all_keys`).
     */
    class BatchPolicy {
        public function __construct() {}

        /**
         * Allow batch to be processed immediately in the server's receiving thread.
         *
         * @return bool
         */
        public function getAllowInline(): bool {}

        /**
         * Allow batch to be processed immediately in the server's receiving thread for SSD namespaces.
         *
         * @return bool
         */
        public function getAllowInlineSsd(): bool {}

        /**
         * @return \Aerospike\Expression|null
         */
        public function getFilterExpression(): ?\Aerospike\Expression {}

        /**
         * @return int
         */
        public function getMaxRetries(): int {}

        /**
         * @return \Aerospike\ReadModeAP
         */
        public function getReadModeAp(): \Aerospike\ReadModeAP {}

        /**
         * Should all batch keys be attempted regardless of errors.
         *
         * @return bool
         */
        public function getRespondAllKeys(): bool {}

        /**
         * Returns the typed concurrency setting (Sequential / Parallel).
         *
         * @return \Aerospike\Concurrency
         */
        public function getConcurrency(): \Aerospike\Concurrency {}

        /**
         * v1 compatibility: returns 1 for Sequential, 0 for Parallel (matching the
         * historical semantics of `concurrent_nodes`: 1 = serial, 0 = unbounded).
         *
         * @return int
         */
        public function getConcurrentNodes(): int {}

        /**
         * @return int
         */
        public function getSocketTimeout(): int {}

        /**
         * @return int
         */
        public function getTotalTimeout(): int {}

        /**
         * @param bool $allow_inline
         * @return void
         */
        public function setAllowInline(bool $allow_inline): void {}

        /**
         * @param bool $allow_inline_ssd
         * @return void
         */
        public function setAllowInlineSsd(bool $allow_inline_ssd): void {}

        /**
         * @param mixed $filter_expression
         * @return void
         */
        public function setFilterExpression(mixed $filter_expression = null): void {}

        /**
         * @param int $max_retries
         * @return void
         */
        public function setMaxRetries(int $max_retries): void {}

        /**
         * @param mixed $read_mode_ap
         * @return void
         */
        public function setReadModeAp(mixed $read_mode_ap): void {}

        /**
         * @param bool $respond_all_keys
         * @return void
         */
        public function setRespondAllKeys(bool $respond_all_keys): void {}

        /**
         * Set concurrency strategy via the typed Concurrency wrapper. Prefer this over
         * setConcurrentNodes() in new code.
         *
         * @param \Aerospike\Concurrency $c
         * @return void
         */
        public function setConcurrency(\Aerospike\Concurrency $c): void {}

        /**
         * v1 compatibility: set concurrency by node count. aerospike-rust 2.x dropped the
         * per-thread limit, so values map to Sequential (n <= 1) or Parallel (n > 1).
         *
         * @param int $n
         * @return void
         */
        public function setConcurrentNodes(int $n): void {}

        /**
         * @param int $timeout_millis
         * @return void
         */
        public function setSocketTimeout(int $timeout_millis): void {}

        /**
         * @param int $timeout_millis
         * @return void
         */
        public function setTotalTimeout(int $timeout_millis): void {}
    }

    /**
     * BatchRead specifies the Key and bin names used in batch read commands
     * where variable bins are needed for each key.
     *
     * Maps to `aero::BatchOperation::Read`. `bins` semantics:
     * - `None` → header only (`Bins::None`)
     * - `Some([])` → read all bins (`Bins::All`)
     * - `Some(names)` → read specified bins (`Bins::Some(names)`)
     */
    class BatchRead {
        /**
         * @param \Aerospike\BatchReadPolicy $policy
         * @param \Aerospike\Key $key
         * @param array|null $bins
         */
        public function __construct(\Aerospike\BatchReadPolicy $policy, \Aerospike\Key $key, ?array $bins = null) {}

        /**
         * Read record header only (no bins).
         *
         * @param \Aerospike\BatchReadPolicy $policy
         * @param \Aerospike\Key $key
         * @return \Aerospike\BatchRead
         */
        public static function header(\Aerospike\BatchReadPolicy $policy, \Aerospike\Key $key): \Aerospike\BatchRead {}

        /**
         * Specifies the read-only operations to perform for the key. Mutually exclusive with `bins`.
         * A bin name can be emulated with `Operation::get(Some("bin"))`. Supported by server v5.6.0+.
         *
         * @param \Aerospike\BatchReadPolicy $policy
         * @param \Aerospike\Key $key
         * @param array $ops
         * @return \Aerospike\BatchRead
         */
        public static function ops(\Aerospike\BatchReadPolicy $policy, \Aerospike\Key $key, array $ops): \Aerospike\BatchRead {}
    }

    /**
     * BatchReadPolicy attributes used in batch read commands.
     */
    class BatchReadPolicy {
        public function __construct() {}

        /**
         * @return \Aerospike\Expression|null
         */
        public function getFilterExpression(): ?\Aerospike\Expression {}

        /**
         * Read-touch-TTL percent (0=server default, -1=don't reset, 1-100=percentage).
         *
         * @return int
         */
        public function getReadTouchTtlPercent(): int {}

        /**
         * @param mixed $filter_expression
         * @return void
         */
        public function setFilterExpression(mixed $filter_expression = null): void {}

        /**
         * 0 = server default, -1 = don't reset, 1..=100 = percentage. Any other value
         * throws an AerospikeException.
         *
         * @param int $percent
         * @return void
         * @throws \Aerospike\AerospikeException
         */
        public function setReadTouchTtlPercent(int $percent): void {}
    }

    /**
     *
     *  BatchRecord
     *
     * Encapsulates a Batch key and the record result populated after a batch command completes.
     *
     * Constructed only by the client when reading batch results back from the server. Field
     * shape mirrors `aero::BatchRecord`.
     */
    class BatchRecord {
        public function __construct() {}

        /**
         * Record's key.
         *
         * @return \Aerospike\Key|null
         */
        public function getKey(): ?\Aerospike\Key {}

        /**
         * Record result. `None` when the record was not found or an error occurred. See ResultCode.
         *
         * @return \Aerospike\Record|null
         */
        public function getRecord(): ?\Aerospike\Record {}
    }

    /**
     * BatchUDF encapsulates a batch user-defined-function operation.
     * Maps to `aero::BatchOperation::UDF`.
     */
    class BatchUdf {
        /**
         * @param \Aerospike\BatchUdfPolicy $policy
         * @param \Aerospike\Key $key
         * @param string $package_name
         * @param string $function_name
         * @param array $function_args
         */
        public function __construct(\Aerospike\BatchUdfPolicy $policy, \Aerospike\Key $key, string $package_name, string $function_name, array $function_args) {}
    }

    /**
     * BatchUdfPolicy attributes used in batch UDF commands.
     */
    class BatchUdfPolicy {
        public function __construct() {}

        /**
         * @return \Aerospike\CommitLevel
         */
        public function getCommitLevel(): \Aerospike\CommitLevel {}

        /**
         * @return bool
         */
        public function getDurableDelete(): bool {}

        /**
         * @return \Aerospike\Expiration
         */
        public function getExpiration(): \Aerospike\Expiration {}

        /**
         * @return \Aerospike\Expression|null
         */
        public function getFilterExpression(): ?\Aerospike\Expression {}

        /**
         * @return bool
         */
        public function getSendKey(): bool {}

        /**
         * @param mixed $commit_level
         * @return void
         */
        public function setCommitLevel(mixed $commit_level): void {}

        /**
         * @param bool $durable_delete
         * @return void
         */
        public function setDurableDelete(bool $durable_delete): void {}

        /**
         * @param mixed $expiration
         * @return void
         */
        public function setExpiration(mixed $expiration): void {}

        /**
         * @param mixed $filter_expression
         * @return void
         */
        public function setFilterExpression(mixed $filter_expression = null): void {}

        /**
         * @param bool $send_key
         * @return void
         */
        public function setSendKey(bool $send_key): void {}
    }

    /**
     * BatchWrite encapsulates a batch key and read/write operations with write policy.
     * Maps to `aero::BatchOperation::Write`.
     */
    class BatchWrite {
        /**
         * @param \Aerospike\BatchWritePolicy $policy
         * @param \Aerospike\Key $key
         * @param array $ops
         */
        public function __construct(\Aerospike\BatchWritePolicy $policy, \Aerospike\Key $key, array $ops) {}
    }

    /**
     * BatchWritePolicy attributes used in batch write commands.
     */
    class BatchWritePolicy {
        public function __construct() {}

        /**
         * @return \Aerospike\CommitLevel
         */
        public function getCommitLevel(): \Aerospike\CommitLevel {}

        /**
         * @return bool
         */
        public function getDurableDelete(): bool {}

        /**
         * @return \Aerospike\Expiration
         */
        public function getExpiration(): \Aerospike\Expiration {}

        /**
         * @return \Aerospike\Expression|null
         */
        public function getFilterExpression(): ?\Aerospike\Expression {}

        /**
         * @return int
         */
        public function getGeneration(): int {}

        /**
         * @return \Aerospike\GenerationPolicy
         */
        public function getGenerationPolicy(): \Aerospike\GenerationPolicy {}

        /**
         * @return \Aerospike\RecordExistsAction
         */
        public function getRecordExistsAction(): \Aerospike\RecordExistsAction {}

        /**
         * @return bool
         */
        public function getSendKey(): bool {}

        /**
         * @param mixed $commit_level
         * @return void
         */
        public function setCommitLevel(mixed $commit_level): void {}

        /**
         * @param bool $durable_delete
         * @return void
         */
        public function setDurableDelete(bool $durable_delete): void {}

        /**
         * @param mixed $expiration
         * @return void
         */
        public function setExpiration(mixed $expiration): void {}

        /**
         * @param mixed $filter_expression
         * @return void
         */
        public function setFilterExpression(mixed $filter_expression = null): void {}

        /**
         * @param int $generation
         * @return void
         */
        public function setGeneration(int $generation): void {}

        /**
         * @param mixed $generation_policy
         * @return void
         */
        public function setGenerationPolicy(mixed $generation_policy): void {}

        /**
         * @param mixed $record_exists_action
         * @return void
         */
        public function setRecordExistsAction(mixed $record_exists_action): void {}

        /**
         * @param bool $send_key
         * @return void
         */
        public function setSendKey(bool $send_key): void {}
    }

    /**
     * Container object for a record bin, comprising a name and a value.
     */
    class Bin {
        /**
         * @param string $name
         * @param mixed $value
         */
        public function __construct(string $name, mixed $value) {}

        /**
         * v1 compatibility shim: forwards `$bin->name`, `->value` to the getters.
         *
         * @param string $name
         * @return mixed
         */
        public function __get(string $name): mixed {}

        /**
         * Bin name.
         *
         * @return string
         */
        public function getName(): string {}

        /**
         * Bin value.
         *
         * @return mixed
         */
        public function getValue(): mixed {}
    }

    /**
     * Bit operations. Create bit operations used by client operate command.
     * Offset orientation is left-to-right.  Negative offsets are supported.
     * If the offset is negative, the offset starts backwards from end of the bitmap.
     * If an offset is out of bounds, a parameter error will be returned.
     *
     *	Nested CDT operations are supported by optional CTX context arguments.  Example:
     *	bin = [[0b00000001, 0b01000010],[0b01011010]]
     *	Resize first bitmap (in a list of bitmaps) to 3 bytes.
     *	BitOperation.resize("bin", 3, BitResizeFlags.DEFAULT, CTX.listIndex(0))
     *	bin result = [[0b00000001, 0b01000010, 0b00000000],[0b01011010]]
     */
    class BitwiseOp {
        public function __construct() {}

        /**
         * BitAddOp creates bit "add" operation. Server adds value to []byte bin starting at
         * bitOffset for bitSize. bitSize must be <= 64.
         *
         * @param \Aerospike\BitwisePolicy $policy
         * @param string $bin_name
         * @param int $bit_offset
         * @param int $bit_size
         * @param int $value
         * @param bool $signed
         * @param mixed $action
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function add(\Aerospike\BitwisePolicy $policy, string $bin_name, int $bit_offset, int $bit_size, int $value, bool $signed, mixed $action, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * BitAndOp creates bit "and" operation.
         *
         * @param \Aerospike\BitwisePolicy $policy
         * @param string $bin_name
         * @param int $bit_offset
         * @param int $bit_size
         * @param array $value
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function and(\Aerospike\BitwisePolicy $policy, string $bin_name, int $bit_offset, int $bit_size, array $value, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * BitCountOp creates bit "count" operation. Server returns count of set bits from []byte
         * bin starting at bitOffset for bitSize.
         *
         * @param string $bin_name
         * @param int $bit_offset
         * @param int $bit_size
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function count(string $bin_name, int $bit_offset, int $bit_size, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * BitGetOp creates bit "get" operation. Server returns bits from []byte bin starting at
         * bitOffset for bitSize.
         *
         * @param string $bin_name
         * @param int $bit_offset
         * @param int $bit_size
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function get(string $bin_name, int $bit_offset, int $bit_size, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * BitGetIntOp creates bit "get integer" operation. Server returns integer from []byte bin
         * starting at bitOffset for bitSize. Signed indicates if bits should be treated as a
         * signed number.
         *
         * @param string $bin_name
         * @param int $bit_offset
         * @param int $bit_size
         * @param bool $signed
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function getInt(string $bin_name, int $bit_offset, int $bit_size, bool $signed, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * BitInsertOp creates byte "insert" operation. Server inserts value bytes into []byte bin
         * at byteOffset. Server does not return a value.
         *
         * @param \Aerospike\BitwisePolicy $policy
         * @param string $bin_name
         * @param int $byte_offset
         * @param array $value
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function insert(\Aerospike\BitwisePolicy $policy, string $bin_name, int $byte_offset, array $value, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * BitLScanOp creates bit "left scan" operation. Server returns offset of the first
         * specified value bit in []byte bin starting at bitOffset for bitSize.
         *
         * @param string $bin_name
         * @param int $bit_offset
         * @param int $bit_size
         * @param bool $value
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function lscan(string $bin_name, int $bit_offset, int $bit_size, bool $value, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * BitLShiftOp creates bit "left shift" operation.
         *
         * @param \Aerospike\BitwisePolicy $policy
         * @param string $bin_name
         * @param int $bit_offset
         * @param int $bit_size
         * @param int $shift
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function lshift(\Aerospike\BitwisePolicy $policy, string $bin_name, int $bit_offset, int $bit_size, int $shift, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * BitNotOp creates bit "not" operation. Server negates []byte bin starting at bitOffset
         * for bitSize.
         *
         * @param \Aerospike\BitwisePolicy $policy
         * @param string $bin_name
         * @param int $bit_offset
         * @param int $bit_size
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function not(\Aerospike\BitwisePolicy $policy, string $bin_name, int $bit_offset, int $bit_size, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * BitOrOp creates bit "or" operation.
         *
         * @param \Aerospike\BitwisePolicy $policy
         * @param string $bin_name
         * @param int $bit_offset
         * @param int $bit_size
         * @param array $value
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function or(\Aerospike\BitwisePolicy $policy, string $bin_name, int $bit_offset, int $bit_size, array $value, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * BitRemoveOp creates byte "remove" operation. Server removes bytes from []byte bin at
         * byteOffset for byteSize. Server does not return a value.
         *
         * @param \Aerospike\BitwisePolicy $policy
         * @param string $bin_name
         * @param int $byte_offset
         * @param int $byte_size
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function remove(\Aerospike\BitwisePolicy $policy, string $bin_name, int $byte_offset, int $byte_size, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * BitResizeOp creates byte "resize" operation. Server resizes []byte to byteSize
         * according to resizeFlags. Server does not return a value.
         *
         * @param \Aerospike\BitwisePolicy $policy
         * @param string $bin_name
         * @param int $byte_size
         * @param mixed $resize_flags
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function resize(\Aerospike\BitwisePolicy $policy, string $bin_name, int $byte_size, mixed $resize_flags = null, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * BitRScanOp creates bit "right scan" operation. Server returns offset of the last
         * specified value bit in []byte bin starting at bitOffset for bitSize.
         *
         * @param string $bin_name
         * @param int $bit_offset
         * @param int $bit_size
         * @param bool $value
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function rscan(string $bin_name, int $bit_offset, int $bit_size, bool $value, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * BitRShiftOp creates bit "right shift" operation.
         *
         * @param \Aerospike\BitwisePolicy $policy
         * @param string $bin_name
         * @param int $bit_offset
         * @param int $bit_size
         * @param int $shift
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function rshift(\Aerospike\BitwisePolicy $policy, string $bin_name, int $bit_offset, int $bit_size, int $shift, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * BitSetOp creates bit "set" operation. Server sets value on []byte bin at bitOffset for
         * bitSize. Server does not return a value.
         *
         * @param \Aerospike\BitwisePolicy $policy
         * @param string $bin_name
         * @param int $bit_offset
         * @param int $bit_size
         * @param array $value
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function set(\Aerospike\BitwisePolicy $policy, string $bin_name, int $bit_offset, int $bit_size, array $value, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * BitSetIntOp creates bit "setInt" operation. Server sets value to []byte bin starting at
         * bitOffset for bitSize. Size must be <= 64.
         *
         * @param \Aerospike\BitwisePolicy $policy
         * @param string $bin_name
         * @param int $bit_offset
         * @param int $bit_size
         * @param int $value
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function setInt(\Aerospike\BitwisePolicy $policy, string $bin_name, int $bit_offset, int $bit_size, int $value, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * BitSubtractOp creates bit "subtract" operation.
         *
         * @param \Aerospike\BitwisePolicy $policy
         * @param string $bin_name
         * @param int $bit_offset
         * @param int $bit_size
         * @param int $value
         * @param bool $signed
         * @param mixed $action
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function subtract(\Aerospike\BitwisePolicy $policy, string $bin_name, int $bit_offset, int $bit_size, int $value, bool $signed, mixed $action, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * BitXorOp creates bit "exclusive or" operation.
         *
         * @param \Aerospike\BitwisePolicy $policy
         * @param string $bin_name
         * @param int $bit_offset
         * @param int $bit_size
         * @param array $value
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function xor(\Aerospike\BitwisePolicy $policy, string $bin_name, int $bit_offset, int $bit_size, array $value, ?array $ctx = null): \Aerospike\Operation {}
    }

    /**
     * BitOverflowAction specifies the action to take when bitwise add/subtract results in overflow/underflow.
     * Note: the backing aero type is `BitwiseOverflowActions` (plural).
     */
    class BitwiseOverflowAction {
        public function __construct() {}

        /**
         * BitOverflowActionFail specifies to fail operation with error.
         *
         * @return \Aerospike\BitwiseOverflowAction
         */
        public static function fail(): \Aerospike\BitwiseOverflowAction {}

        /**
         * BitOverflowActionSaturate specifies that in add/subtract overflows/underflows, set to max/min value.
         * Example: MAXINT + 1 = MAXINT
         *
         * @return \Aerospike\BitwiseOverflowAction
         */
        public static function saturate(): \Aerospike\BitwiseOverflowAction {}

        /**
         * BitOverflowActionWrap specifies that in add/subtract overflows/underflows, wrap the value.
         * Example: MAXINT + 1 = -1
         *
         * @return \Aerospike\BitwiseOverflowAction
         */
        public static function wrap(): \Aerospike\BitwiseOverflowAction {}
    }

    /**
     * BitPolicy determines the Bit operation policy.
     * Note: the backing aero type is `BitPolicy` (not BitwisePolicy).
     */
    class BitwisePolicy {
        /**
         * new BitwisePolicy(flags) will return a BitPolicy with provided write flags.
         *
         * @param mixed $flags
         */
        public function __construct(mixed $flags = null) {}
    }

    /**
     * BitResizeFlags specifies the bitwise operation flags for resize.
     */
    class BitwiseResizeFlags {
        public function __construct() {}

        /**
         * BitResizeFlagsDefault specifies the default flag.
         *
         * @return \Aerospike\BitwiseResizeFlags
         */
        public static function default(): \Aerospike\BitwiseResizeFlags {}

        /**
         * BitResizeFlagsFromFront Adds/removes bytes from the beginning instead of the end.
         *
         * @return \Aerospike\BitwiseResizeFlags
         */
        public static function fromFront(): \Aerospike\BitwiseResizeFlags {}

        /**
         * BitResizeFlagsGrowOnly will only allow the []byte size to increase.
         *
         * @return \Aerospike\BitwiseResizeFlags
         */
        public static function growOnly(): \Aerospike\BitwiseResizeFlags {}

        /**
         * BitResizeFlagsShrinkOnly will only allow the []byte size to decrease.
         *
         * @return \Aerospike\BitwiseResizeFlags
         */
        public static function shrinkOnly(): \Aerospike\BitwiseResizeFlags {}
    }

    /**
     * BitWriteFlags specify bitwise operation policy write flags.
     */
    class BitwiseWriteFlags {
        public function __construct() {}

        /**
         * BitWriteFlagsCreateOnly specifies that:
         * If the bin already exists, the operation will be denied.
         * If the bin does not exist, a new bin will be created.
         *
         * @return \Aerospike\BitwiseWriteFlags
         */
        public static function createOnly(): \Aerospike\BitwiseWriteFlags {}

        /**
         * BitWriteFlagsDefault allows create or update.
         *
         * @return \Aerospike\BitwiseWriteFlags
         */
        public static function default(): \Aerospike\BitwiseWriteFlags {}

        /**
         * BitWriteFlagsNoFail specifies not to raise error if operation is denied.
         *
         * @return \Aerospike\BitwiseWriteFlags
         */
        public static function noFail(): \Aerospike\BitwiseWriteFlags {}

        /**
         * BitWriteFlagsPartial allows other valid operations to be committed if this operations is
         * denied due to flag constraints.
         *
         * @return \Aerospike\BitwiseWriteFlags
         */
        public static function partial(): \Aerospike\BitwiseWriteFlags {}

        /**
         * BitWriteFlagsUpdateOnly specifies that:
         * If the bin already exists, the bin will be overwritten.
         * If the bin does not exist, the operation will be denied.
         *
         * @return \Aerospike\BitwiseWriteFlags
         */
        public static function updateOnly(): \Aerospike\BitwiseWriteFlags {}
    }

    class Client {
        /**
         * Not directly instantiable; use Client::connect().
         */
        private function __construct() {}

        /**
         * v1 compatibility shim: forwards `$client->hosts` to `getHosts()`.
         *
         * @param string $name
         * @return mixed
         */
        public function __get(string $name): mixed {}

        /**
         * Add integer bin values to existing record bin values.
         *
         * @param \Aerospike\WritePolicy $policy
         * @param \Aerospike\Key $key
         * @param array $bins
         * @return void
         */
        public function add(\Aerospike\WritePolicy $policy, \Aerospike\Key $key, array $bins): void {}

        /**
         * Append bin string values to existing record bin values.
         *
         * @param \Aerospike\WritePolicy $policy
         * @param \Aerospike\Key $key
         * @param array $bins
         * @return void
         */
        public function append(\Aerospike\WritePolicy $policy, \Aerospike\Key $key, array $bins): void {}

        /**
         * Execute read/write operations on multiple records in one batch call.
         * Each element in `cmds` must be a BatchRead, BatchWrite, BatchDelete, or BatchUdf object.
         * Requires server version 6.0+.
         *
         * @param \Aerospike\BatchPolicy $policy
         * @param array $cmds
         * @return array
         */
        public function batch(\Aerospike\BatchPolicy $policy, array $cmds): array {}

        /**
         * @param \Aerospike\AdminPolicy $policy
         * @param string $user
         * @param string $password
         * @return void
         */
        public function changePassword(\Aerospike\AdminPolicy $policy, string $user, string $password): void {}

        /**
         * Connect to the Aerospike database cluster.
         *
         * v2 BREAKING: takes a hosts string ("host:port,...") instead of a Unix socket path.
         *
         * @param string $hosts Comma-separated list of host:port pairs, e.g. "127.0.0.1:3000"
         * @param \Aerospike\ClientPolicy|null $policy Optional client policy controlling auth, pool sizes, timeouts, etc.
         * @return mixed
         */
        public static function connect(string $hosts, ?\Aerospike\ClientPolicy $policy = null): mixed {}

        /**
         * Create a secondary index on a bin.
         *
         * v2 BREAKING: `ctx` is currently ignored; the underlying aerospike crate does not yet
         * expose ctx-aware index creation through `create_index_on_bin`.
         *
         * @param \Aerospike\WritePolicy $policy
         * @param string $namespace
         * @param string $set_name
         * @param string $bin_name
         * @param string $index_name
         * @param \Aerospike\IndexType $index_type
         * @param \Aerospike\IndexCollectionType|null $cit
         * @param array|null $_ctx
         * @return void
         */
        public function createIndex(\Aerospike\WritePolicy $policy, string $namespace, string $set_name, string $bin_name, string $index_name, \Aerospike\IndexType $index_type, ?\Aerospike\IndexCollectionType $cit = null, ?array $_ctx = null): void {}

        /**
         * @param \Aerospike\AdminPolicy $policy
         * @param string $role_name
         * @param array $privileges
         * @param array $allowlist
         * @param int $read_quota
         * @param int $write_quota
         * @return void
         */
        public function createRole(\Aerospike\AdminPolicy $policy, string $role_name, array $privileges, array $allowlist, int $read_quota, int $write_quota): void {}

        /**
         * @param \Aerospike\AdminPolicy $policy
         * @param string $user
         * @param string $password
         * @param array $roles
         * @return void
         */
        public function createUser(\Aerospike\AdminPolicy $policy, string $user, string $password, array $roles): void {}

        /**
         * Delete record for specified key. Returns `true` if the record existed before deletion.
         *
         * @param \Aerospike\WritePolicy $policy
         * @param \Aerospike\Key $key
         * @return bool
         */
        public function delete(\Aerospike\WritePolicy $policy, \Aerospike\Key $key): bool {}

        /**
         * Delete a secondary index.
         *
         * @param \Aerospike\WritePolicy $policy
         * @param string $namespace
         * @param string $set_name
         * @param string $index_name
         * @return void
         */
        public function dropIndex(\Aerospike\WritePolicy $policy, string $namespace, string $set_name, string $index_name): void {}

        /**
         * @param \Aerospike\AdminPolicy $policy
         * @param string $role_name
         * @return void
         */
        public function dropRole(\Aerospike\AdminPolicy $policy, string $role_name): void {}

        /**
         * @param \Aerospike\WritePolicy $policy
         * @param string $package_name
         * @return void
         */
        public function dropUdf(\Aerospike\WritePolicy $policy, string $package_name): void {}

        /**
         * @param \Aerospike\AdminPolicy $policy
         * @param string $user
         * @return void
         */
        public function dropUser(\Aerospike\AdminPolicy $policy, string $user): void {}

        /**
         * Determine if a record key exists.
         *
         * @param \Aerospike\ReadPolicy $policy
         * @param \Aerospike\Key $key
         * @return bool
         */
        public function exists(\Aerospike\ReadPolicy $policy, \Aerospike\Key $key): bool {}

        /**
         * Read record for the specified key. Depending on the bins value provided, all record bins,
         * only selected record bins or only the record headers will be returned.
         *
         * @param \Aerospike\ReadPolicy $policy
         * @param \Aerospike\Key $key
         * @param array|null $bins
         * @return \Aerospike\Record|null
         */
        public function get(\Aerospike\ReadPolicy $policy, \Aerospike\Key $key, ?array $bins = null): ?\Aerospike\Record {}

        /**
         * Read record header (generation, expiration) only. No bins are returned.
         *
         * @param \Aerospike\ReadPolicy $policy
         * @param \Aerospike\Key $key
         * @return \Aerospike\Record|null
         */
        public function getHeader(\Aerospike\ReadPolicy $policy, \Aerospike\Key $key): ?\Aerospike\Record {}

        /**
         * Close the connection to the Aerospike cluster and remove this client from the
         * per-process client cache, stopping its connection pool and background cluster-tend
         * task. The underlying connection is shared: any other PHP `Client` object obtained
         * from `connect()` with the same hosts and policy uses the same pool and becomes
         * unusable after `close()`. A subsequent `connect()` establishes a fresh connection.
         *
         * Calling `close()` is optional — cached clients are reused across requests by design
         * and are closed automatically at module shutdown. Use it when a connection is known
         * to be obsolete (e.g. after credential rotation) to release its pool immediately.
         *
         * @return void
         * @throws \Aerospike\AerospikeException
         */
        public function close(): void {}

        /**
         * Number of clients currently held by the per-process client cache. Diagnostic
         * helper: lets deployments (and tests) observe cache growth and eviction behavior.
         *
         * @return int
         */
        public static function cachedClientCount(): int {}

        /**
         * Returns the hosts string this client was connected to.
         *
         * @return string
         */
        public function getHosts(): string {}

        /**
         * Returns true if the client is connected to any cluster nodes and has not been
         * closed. Returns false immediately after `close()`.
         *
         * @return bool
         */
        public function isConnected(): bool {}

        /**
         * @param \Aerospike\AdminPolicy $policy
         * @param string $role_name
         * @param array $privileges
         * @return void
         */
        public function grantPrivileges(\Aerospike\AdminPolicy $policy, string $role_name, array $privileges): void {}

        /**
         * @param \Aerospike\AdminPolicy $policy
         * @param string $user
         * @param array $roles
         * @return void
         */
        public function grantRoles(\Aerospike\AdminPolicy $policy, string $user, array $roles): void {}

        /**
         * @param \Aerospike\ReadPolicy $policy
         * @return array
         */
        public function listUdf(\Aerospike\ReadPolicy $policy): array {}

        /**
         * Execute multiple operations on a single record atomically. Combines reads, writes,
         * CDT (list/map/bitwise/HLL) operations, and touch semantics in one round trip.
         *
         * @param \Aerospike\WritePolicy $policy
         * @param \Aerospike\Key $key
         * @param Operation[] $ops
         * @return \Aerospike\Record|null The resulting record, or null if the operations
         *                                produced no readable output and the record was not present.
         */
        public function operate(\Aerospike\WritePolicy $policy, \Aerospike\Key $key, array $ops): ?\Aerospike\Record {}

        /**
         * Prepend bin string values to existing record bin values.
         *
         * @param \Aerospike\WritePolicy $policy
         * @param \Aerospike\Key $key
         * @param array $bins
         * @return void
         */
        public function prepend(\Aerospike\WritePolicy $policy, \Aerospike\Key $key, array $bins): void {}

        /**
         * Write record bin(s). The policy specifies the transaction timeout, record expiration and
         * how the transaction is handled when the record already exists.
         *
         * @param \Aerospike\WritePolicy $policy
         * @param \Aerospike\Key $key
         * @param array $bins
         * @return void
         */
        public function put(\Aerospike\WritePolicy $policy, \Aerospike\Key $key, array $bins): void {}

        /**
         * Execute a query on all server nodes and return a record iterator.
         *
         * @param \Aerospike\QueryPolicy $policy
         * @param mixed $partition_filter
         * @param \Aerospike\Statement $statement
         * @return \Aerospike\Recordset
         */
        public function query(\Aerospike\QueryPolicy $policy, mixed $partition_filter, \Aerospike\Statement $statement): \Aerospike\Recordset {}

        /**
         * @param \Aerospike\AdminPolicy $policy
         * @param string|null $role_name
         * @return array
         */
        public function queryRoles(\Aerospike\AdminPolicy $policy, ?string $role_name = null): array {}

        /**
         * @param \Aerospike\AdminPolicy $policy
         * @param string|null $user
         * @return array
         */
        public function queryUsers(\Aerospike\AdminPolicy $policy, ?string $user = null): array {}

        /**
         * RegisterUDF registers a package containing user defined functions with server.
         *
         * @param \Aerospike\WritePolicy $policy
         * @param string $udf_body
         * @param string $package_name
         * @param mixed $language
         * @return void
         */
        public function registerUdf(\Aerospike\WritePolicy $policy, string $udf_body, string $package_name, mixed $language = null): void {}

        /**
         * @param \Aerospike\AdminPolicy $policy
         * @param string $role_name
         * @param array $privileges
         * @return void
         */
        public function revokePrivileges(\Aerospike\AdminPolicy $policy, string $role_name, array $privileges): void {}

        /**
         * @param \Aerospike\AdminPolicy $policy
         * @param string $user
         * @param array $roles
         * @return void
         */
        public function revokeRoles(\Aerospike\AdminPolicy $policy, string $user, array $roles): void {}

        /**
         * Read all records in the specified namespace and set. In v2, scan is implemented
         * as a query with no secondary-index filters.
         *
         * @param \Aerospike\ScanPolicy $policy
         * @param mixed $partition_filter
         * @param string $namespace
         * @param string $set_name
         * @param array|null $bins
         * @return \Aerospike\Recordset
         */
        public function scan(\Aerospike\ScanPolicy $policy, mixed $partition_filter, string $namespace, string $set_name, ?array $bins = null): \Aerospike\Recordset {}

        /**
         * Returns the server build version string for each node in the cluster.
         * The returned HashMap maps node name (host:port) to version string (e.g. "7.0.0.1").
         *
         * @return array
         */
        public function serverVersion(): array {}

        /**
         * @param \Aerospike\AdminPolicy $policy
         * @param string $role_name
         * @param array $allowlist
         * @return void
         */
        public function setAllowlist(\Aerospike\AdminPolicy $policy, string $role_name, array $allowlist): void {}

        /**
         * @param \Aerospike\AdminPolicy $policy
         * @param string $role_name
         * @param int $read_quota
         * @param int $write_quota
         * @return void
         */
        public function setQuotas(\Aerospike\AdminPolicy $policy, string $role_name, int $read_quota, int $write_quota): void {}

        /**
         * Reset record's time to expiration using the policy's expiration.
         *
         * @param \Aerospike\WritePolicy $policy
         * @param \Aerospike\Key $key
         * @return void
         */
        public function touch(\Aerospike\WritePolicy $policy, \Aerospike\Key $key): void {}

        /**
         * Remove all records in the specified namespace/set efficiently.
         *
         * @param \Aerospike\InfoPolicy $policy
         * @param string $namespace
         * @param string $set_name
         * @param int|null $before_nanos
         * @return void
         */
        public function truncate(\Aerospike\InfoPolicy $policy, string $namespace, string $set_name, ?int $before_nanos = null): void {}

        /**
         * @param \Aerospike\WritePolicy $policy
         * @param \Aerospike\Key $key
         * @param string $package_name
         * @param string $function_name
         * @param array $args
         * @return mixed
         */
        public function udfExecute(\Aerospike\WritePolicy $policy, \Aerospike\Key $key, string $package_name, string $function_name, array $args): mixed {}
    }

    /**
     * `ClientPolicy` encapsulates parameters for creating a new `Client`. Pass an optional
     * `ClientPolicy` to `Client::connect(hosts, ?policy)` to control authentication, connection
     * pooling, cluster tending, and IP translation.
     *
     * v2 NEW: this class replaces the asld `asld.toml` config file. TLS is opt-in via
     * `setTls(...)`; connections are clear-text by default.
     */
    class ClientPolicy {
        public function __construct() {}

        /**
         * Returns a deterministic short fingerprint for this policy used to key the per-process
         * client cache. Two policies with the same fingerprint produce equivalent clients and may
         * share the cached instance. The password is hashed (never printed in clear) so
         * password rotation invalidates the cached client without leaking the secret.
         *
         * @return string
         */
        public function fingerprint(): string {}

        /**
         * Optional application identifier. Used by the server to correlate client operations with
         * server-side metrics. Defaults to the auth user when unset.
         *
         * @return string|null
         */
        public function getApplicationId(): ?string {}

        /**
         * Authentication mode as a lowercase string: "none", "internal", "external", or "pki".
         *
         * @return string
         */
        public function getAuthMode(): string {}

        /**
         * Expected cluster name. If set, server nodes must return this name during cluster tending
         * or they are excluded from the cluster view.
         *
         * @return string|null
         */
        public function getClusterName(): ?string {}

        /**
         * Number of connection pools per server node. Higher values reduce contention on
         * many-core machines at the cost of more open sockets.
         *
         * @return int
         */
        public function getConnPoolsPerNode(): int {}

        /**
         * Throw an exception if the initial host connection fails. Default `true`.
         *
         * @return bool
         */
        public function getFailIfNotConnected(): bool {}

        /**
         * Connection idle timeout in milliseconds. Connections idle longer than this are closed
         * and discarded from the pool.
         *
         * @return int
         */
        public function getIdleTimeout(): int {}

        /**
         * IP translation map: server-reported IP → real IP the client should dial. Empty map
         * disables translation. Mutually exclusive with `use_services_alternate`.
         *
         * @return array
         */
        public function getIpMap(): array {}

        /**
         * Maximum number of synchronous connections allowed per server node.
         *
         * @return int
         */
        public function getMaxConnsPerNode(): int {}

        /**
         * Minimum number of connections preallocated per server node.
         *
         * @return int
         */
        public function getMinConnsPerNode(): int {}

        /**
         * Interval (ms) between cluster-tend checks. Minimum is 10 ms; default is 1000 ms
         * (inherited from `aerospike-client-rust`, aligned with the Java and Go clients).
         *
         * **Tuning guidance**: every PHP process runs its own tend loop, so the steady-state
         * info-protocol RPS on each cluster node scales as `processes × nodes × (1 / interval)`.
         * For prefork deployments (php-fpm, mod_php) with many concurrent workers, raise the
         * interval to keep that fan-out manageable:
         *
         * | Deployment                                          | Recommended `tend_interval` |
         * | ---                                                 | ---                          |
         * | CLI tools, daemons, RoadRunner / FrankenPHP / Swoole | 1000 ms (default)            |
         * | php-fpm with 10–50 workers per pod                  | 2000–5000 ms                 |
         * | php-fpm with 100+ workers per pod                   | 5000–10000 ms                |
         *
         * Tradeoff: longer intervals slow detection of topology changes (node add/remove,
         * rebalance). Failover on data-path errors is handled separately by retry policies
         * and is unaffected.
         *
         * @return int
         */
        public function getTendInterval(): int {}

        /**
         * Initial host connection timeout in milliseconds.
         *
         * @return int
         */
        public function getTimeout(): int {}

        /**
         * Returns true if a TLS configuration is attached to this policy.
         *
         * @return bool
         */
        public function getTlsEnabled(): bool {}

        /**
         * Use `services-alternate` in cluster tending instead of `services`. Required when the
         * client and server are on different sides of NAT/firewall. Mutually exclusive with `ip_map`.
         *
         * @return bool
         */
        public function getUseServicesAlternate(): bool {}

        /**
         * Username for internal or external authentication, if set.
         *
         * @return string|null
         */
        public function getUser(): ?string {}

        /**
         * @param string|null $id
         * @return void
         */
        public function setApplicationId(?string $id = null): void {}

        /**
         * Configure internal authentication. The server stores a hashed password; the client never
         * sends the password in clear over the wire. This is the recommended default when running
         * against a security-enabled cluster.
         *
         * @param string $user
         * @param string $password
         * @return void
         */
        public function setAuth(string $user, string $password): void {}

        /**
         * Configure external authentication (LDAP). Requires a TLS-enabled connection because the
         * password is sent in clear at login. Returns an error if TLS is not configured.
         *
         * @param string $user
         * @param string $password
         * @return void
         */
        public function setAuthExternal(string $user, string $password): void {}

        /**
         * Disable authentication. The default for an unsecured cluster.
         *
         * @return void
         */
        public function setAuthNone(): void {}

        /**
         * Configure PKI authentication. Requires server v5.7+ and a configured TLS client
         * certificate. No user/password is required — identity is derived from the certificate.
         *
         * @return void
         */
        public function setAuthPki(): void {}

        /**
         * @param string|null $name
         * @return void
         */
        public function setClusterName(?string $name = null): void {}

        /**
         * @param int $n
         * @return void
         */
        public function setConnPoolsPerNode(int $n): void {}

        /**
         * @param bool $fail
         * @return void
         */
        public function setFailIfNotConnected(bool $fail): void {}

        /**
         * @param int $timeout_millis
         * @return void
         */
        public function setIdleTimeout(int $timeout_millis): void {}

        /**
         * @param array $map
         * @return void
         */
        public function setIpMap(array $map): void {}

        /**
         * @param int $n
         * @return void
         */
        public function setMaxConnsPerNode(int $n): void {}

        /**
         * @param int $n
         * @return void
         */
        public function setMinConnsPerNode(int $n): void {}

        /**
         * @param int $millis
         * @return void
         */
        public function setTendInterval(int $millis): void {}

        /**
         * @param int $timeout_millis
         * @return void
         */
        public function setTimeout(int $timeout_millis): void {}

        /**
         * Enable TLS for cluster connections.
         *
         * `ca_file` — path to a PEM file with one or more trusted root certificates. When null,
         * Mozilla's webpki-roots bundle is used as the trust anchor set.
         *
         * `cert_file` / `key_file` — when both provided, configure mutual TLS using a client
         * certificate chain (PEM) and private key (PEM, PKCS#8 / PKCS#1 / SEC1). Provide both
         * or neither; mixing one with the other is rejected.
         *
         * `server_name` — currently unused (rustls validates the SNI/peer name supplied by
         * the aerospike client during connect). Accepted for forward compatibility.
         *
         * Throws an AerospikeException if a file is missing, contains no parseable
         * certificates, or the key cannot be loaded. The file contents are hashed into
         * fingerprint() so cert rotation invalidates the cached Client.
         *
         * @param string|null $ca_file
         * @param string|null $cert_file
         * @param string|null $key_file
         * @param string|null $server_name
         * @return void
         */
        public function setTls(?string $ca_file = null, ?string $cert_file = null, ?string $key_file = null, ?string $server_name = null): void {}

        /**
         * Disable TLS, reverting the policy to clear-text connections.
         *
         * @return void
         */
        public function setTlsNone(): void {}

        /**
         * @param bool $alt
         * @return void
         */
        public function setUseServicesAlternate(bool $alt): void {}
    }

    /**
     * CommitLevel indicates the desired consistency guarantee when committing a transaction on the server.
     */
    class CommitLevel {
        public function __construct() {}

        /**
         * CommitAll indicates the server should wait until successfully committing master and all replicas.
         *
         * @return \Aerospike\CommitLevel
         */
        public static function commitAll(): \Aerospike\CommitLevel {}

        /**
         * CommitMaster indicates the server should wait until successfully committing master only.
         *
         * @return \Aerospike\CommitLevel
         */
        public static function commitMaster(): \Aerospike\CommitLevel {}
    }

    class Concurrency {
        public function __construct() {}

        /**
         * Issue up to N commands in parallel threads. When a request completes, a new request
         * will be issued until all threads are complete. This mode prevents too many parallel threads
         * being created for large cluster implementations. The downside is extra threads will still
         * need to be created (or taken from a thread pool).
         *
         * E.g. if there are 16 nodes/namespace combinations requested and concurrency is set to
         * `MaxThreads(8)`, then batch requests will be made for 8 node/namespace combinations in
         * parallel threads. When a request completes, a new request will be issued until all 16
         * requests are complete.
         *
         * @param int $threads
         * @return \Aerospike\Concurrency
         */
        public static function maxThreads(int $threads): \Aerospike\Concurrency {}

        /**
         * Issue all commands in parallel threads. This mode has a performance advantage for
         * extremely large batch sizes because each node can process the request immediately. The
         * downside is extra threads will need to be created (or takedn from a thread pool).
         *
         * @return \Aerospike\Concurrency
         */
        public static function parallel(): \Aerospike\Concurrency {}

        /**
         * Issue commands sequentially. This mode has a performance advantage for small to
         * medium sized batch sizes because requests can be issued in the main transaction thread.
         * This is the default.
         *
         * @return \Aerospike\Concurrency
         */
        public static function sequential(): \Aerospike\Concurrency {}
    }

    class ConsistencyLevel {
        public function __construct() {}

        /**
         * ConsistencyAll indicates that all replicas should be consulted in
         * the read operation.
         *
         * @return \Aerospike\ConsistencyLevel
         */
        public static function consistencyAll(): \Aerospike\ConsistencyLevel {}

        /**
         * ConsistencyOne indicates only a single replica should be consulted in
         * the read operation.
         *
         * @return \Aerospike\ConsistencyLevel
         */
        public static function consistencyOne(): \Aerospike\ConsistencyLevel {}
    }

    /**
     * CDTContext defines Nested CDT context. Identifies the location of nested list/map to apply the operation.
     * for the current level.
     * An array of CTX identifies location of the list/map on multiple
     * levels on nesting.
     */
    class Context {
        public function __construct() {}

        /**
         * CtxListIndex defines Lookup list by index offset.
         * If the index is negative, the resolved index starts backwards from end of list.
         * If an index is out of bounds, a parameter error will be returned.
         * Examples:
         * 0: First item.
         * 4: Fifth item.
         * -1: Last item.
         * -3: Third to last item.
         *
         * @param int $index
         * @return \Aerospike\Context
         */
        public static function listIndex(int $index): \Aerospike\Context {}

        /**
         * CtxListIndexCreate list with given type at index offset, given an order and pad.
         *
         * @param int $index
         * @param mixed $order
         * @param bool $pad
         * @return \Aerospike\Context
         */
        public static function listIndexCreate(int $index, mixed $order, bool $pad): \Aerospike\Context {}

        /**
         * CtxListRank defines Lookup list by rank.
         * 0 = smallest value
         * N = Nth smallest value
         * -1 = largest value
         *
         * @param int $rank
         * @return \Aerospike\Context
         */
        public static function listRank(int $rank): \Aerospike\Context {}

        /**
         * CtxListValue defines Lookup list by value.
         *
         * @param mixed $key
         * @return \Aerospike\Context
         */
        public static function listValue(mixed $key): \Aerospike\Context {}

        /**
         * CtxMapIndex defines Lookup map by index offset.
         * If the index is negative, the resolved index starts backwards from end of list.
         * If an index is out of bounds, a parameter error will be returned.
         * Examples:
         * 0: First item.
         * 4: Fifth item.
         * -1: Last item.
         * -3: Third to last item.
         *
         * @param int $index
         * @return \Aerospike\Context
         */
        public static function mapIndex(int $index): \Aerospike\Context {}

        /**
         * CtxMapKey defines Lookup map by key.
         *
         * @param mixed $key
         * @return \Aerospike\Context
         */
        public static function mapKey(mixed $key): \Aerospike\Context {}

        /**
         * CtxMapKeyCreate creates map with given type at map key.
         *
         * @param mixed $key
         * @param mixed $order
         * @return \Aerospike\Context
         */
        public static function mapKeyCreate(mixed $key, mixed $order): \Aerospike\Context {}

        /**
         * CtxMapRank defines Lookup map by rank.
         * 0 = smallest value
         * N = Nth smallest value
         * -1 = largest value
         *
         * @param int $rank
         * @return \Aerospike\Context
         */
        public static function mapRank(int $rank): \Aerospike\Context {}

        /**
         * CtxMapValue defines Lookup map by value.
         *
         * @param mixed $key
         * @return \Aerospike\Context
         */
        public static function mapValue(mixed $key): \Aerospike\Context {}
    }

    /**
     * ExpType defines the expression's data type.
     */
    class ExpType {
        public function __construct() {}

        /**
         * ExpTypeBLOB is BLOB Expression Type
         *
         * @return \Aerospike\ExpType
         */
        public static function blob(): \Aerospike\ExpType {}

        /**
         * ExpTypeBOOL is BOOLEAN Expression Type
         *
         * @return \Aerospike\ExpType
         */
        public static function bool(): \Aerospike\ExpType {}

        /**
         * ExpTypeFLOAT is FLOAT Expression Type
         *
         * @return \Aerospike\ExpType
         */
        public static function float(): \Aerospike\ExpType {}

        /**
         * ExpTypeGEO is GEO String Expression Type
         *
         * @return \Aerospike\ExpType
         */
        public static function geo(): \Aerospike\ExpType {}

        /**
         * ExpTypeHLL is HLL Expression Type
         *
         * @return \Aerospike\ExpType
         */
        public static function hll(): \Aerospike\ExpType {}

        /**
         * ExpTypeINT is INTEGER Expression Type
         *
         * @return \Aerospike\ExpType
         */
        public static function int(): \Aerospike\ExpType {}

        /**
         * ExpTypeLIST is LIST Expression Type
         *
         * @return \Aerospike\ExpType
         */
        public static function list(): \Aerospike\ExpType {}

        /**
         * ExpTypeMAP is MAP Expression Type
         *
         * @return \Aerospike\ExpType
         */
        public static function map(): \Aerospike\ExpType {}

        /**
         * ExpTypeNIL is NIL Expression Type
         *
         * @return \Aerospike\ExpType
         */
        public static function nil(): \Aerospike\ExpType {}

        /**
         * ExpTypeSTRING is STRING Expression Type
         *
         * @return \Aerospike\ExpType
         */
        public static function string(): \Aerospike\ExpType {}
    }

    class Expiration {
        public function __construct() {}

        /**
         * Do not change the record's expiry time when updating the record.
         *
         * @return \Aerospike\Expiration
         */
        public static function dontUpdate(): \Aerospike\Expiration {}

        /**
         * Answers with the expiration's current time to live in units of seconds.
         * Returns null for any non-Seconds variant.
         *
         * @return int|null
         */
        public function getTtl(): ?int {}

        /**
         * Answers true only if the expiration is set to use the namespace default.
         *
         * @return bool
         */
        public function isNamespaceDefault(): bool {}

        /**
         * Set the record's expiry time using the default TTL for the namespace.
         *
         * @return \Aerospike\Expiration
         */
        public static function namespaceDefault(): \Aerospike\Expiration {}

        /**
         * Set the record to never expire.
         *
         * @return \Aerospike\Expiration
         */
        public static function never(): \Aerospike\Expiration {}

        /**
         * Set the record to expire X seconds from now.  See also `getTtl()`.
         *
         * @param int $seconds
         * @return \Aerospike\Expiration
         */
        public static function seconds(int $seconds): \Aerospike\Expiration {}

        /**
         * Answers true only if the expiration is set to never expire.
         *
         * @return bool
         */
        public function willNeverExpire(): bool {}

        /**
         * True if the expiration is configured to change during a record update.
         *
         * @return bool
         */
        public function willUpdateExpiration(): bool {}
    }

    /**
     * Filter expression, which can be applied to most commands, to control which records are
     * affected by the command.
     */
    class Expression {
        public function __construct() {}

        /**
         * Create "and" (&&) operator that applies to a variable number of expressions.
         *
         * @param array $exps
         * @return \Aerospike\Expression
         */
        public static function and(array $exps): \Aerospike\Expression {}

        /**
         * Create function that returns if bin of specified name exists.
         *
         * @param string $name
         * @return \Aerospike\Expression
         */
        public static function binExists(string $name): \Aerospike\Expression {}

        /**
         * ExpBinType creates a function that returns bin's integer particle type. Valid values are:
         *
         * NULL    = 0
         * INTEGER = 1
         * FLOAT   = 2
         * STRING  = 3
         * BLOB    = 4
         * DIGEST  = 6
         * BOOL    = 17
         * HLL     = 18
         * MAP     = 19
         * LIST    = 20
         * LDT     = 21
         * GEOJSON = 23
         *
         * @param string $name
         * @return \Aerospike\Expression
         */
        public static function binType(string $name): \Aerospike\Expression {}

        /**
         * Create blob bin expression.
         *
         * @param string $name
         * @return \Aerospike\Expression
         */
        public static function blobBin(string $name): \Aerospike\Expression {}

        /**
         * Creates Blob bin value.
         *
         * @param array $val
         * @return \Aerospike\Expression
         */
        public static function blobVal(array $val): \Aerospike\Expression {}

        /**
         * Creates a Boolean value.
         *
         * @param bool $val
         * @return \Aerospike\Expression
         */
        public static function boolVal(bool $val): \Aerospike\Expression {}

        /**
         * Conditionally select an expression from a variable number of expression pairs
         * followed by default expression action.
         *
         * @param array $exps
         * @return \Aerospike\Expression
         */
        public static function cond(array $exps): \Aerospike\Expression {}

        /**
         * Assign variable to an expression that can be accessed later.
         *
         * @param string $name
         * @param \Aerospike\Expression $value
         * @return \Aerospike\Expression
         */
        public static function def(string $name, \Aerospike\Expression $value): \Aerospike\Expression {}

        /**
         * Create function that returns record size on disk.
         * If server storage-engine is memory, then zero is returned.
         *
         * This expression should only be used for server versions less than 7.0. Use
         * `record_size` for server version 7.0+.
         *
         * @return \Aerospike\Expression
         */
        public static function deviceSize(): \Aerospike\Expression {}

        /**
         * Create function that returns record digest modulo as integer.
         *
         * @param int $modulo
         * @return \Aerospike\Expression
         */
        public static function digestModulo(int $modulo): \Aerospike\Expression {}

        /**
         * Create equal (==) expression.
         *
         * @param \Aerospike\Expression $left
         * @param \Aerospike\Expression $right
         * @return \Aerospike\Expression
         */
        public static function eq(\Aerospike\Expression $left, \Aerospike\Expression $right): \Aerospike\Expression {}

        /**
         * Define variables and expressions in scope.
         *
         * @param array $exps
         * @return \Aerospike\Expression
         */
        public static function expLet(array $exps): \Aerospike\Expression {}

        /**
         * Create 64 bit float bin expression.
         *
         * @param string $name
         * @return \Aerospike\Expression
         */
        public static function floatBin(string $name): \Aerospike\Expression {}

        /**
         * Creates 64 bit float bin value.
         *
         * @param float $val
         * @return \Aerospike\Expression
         */
        public static function floatVal(float $val): \Aerospike\Expression {}

        /**
         * Create greater than or equal (>=) operation.
         *
         * @param \Aerospike\Expression $left
         * @param \Aerospike\Expression $right
         * @return \Aerospike\Expression
         */
        public static function ge(\Aerospike\Expression $left, \Aerospike\Expression $right): \Aerospike\Expression {}

        /**
         * Create geo bin expression.
         *
         * @param string $name
         * @return \Aerospike\Expression
         */
        public static function geoBin(string $name): \Aerospike\Expression {}

        /**
         * Create compare geospatial operation.
         *
         * @param \Aerospike\Expression $left
         * @param \Aerospike\Expression $right
         * @return \Aerospike\Expression
         */
        public static function geoCompare(\Aerospike\Expression $left, \Aerospike\Expression $right): \Aerospike\Expression {}

        /**
         * Create geospatial JSON string value.
         *
         * @param string $val
         * @return \Aerospike\Expression
         */
        public static function geoVal(string $val): \Aerospike\Expression {}

        /**
         * Create greater than (>) operation.
         *
         * @param \Aerospike\Expression $left
         * @param \Aerospike\Expression $right
         * @return \Aerospike\Expression
         */
        public static function gt(\Aerospike\Expression $left, \Aerospike\Expression $right): \Aerospike\Expression {}

        /**
         * Create a HLL bin expression.
         *
         * @param string $name
         * @return \Aerospike\Expression
         */
        public static function hllBin(string $name): \Aerospike\Expression {}

        /**
         * Create an Infinity value.
         *
         * @return \Aerospike\Expression
         */
        public static function infinity(): \Aerospike\Expression {}

        /**
         * Create integer "and" (&) operator.
         *
         * @param array $exps
         * @return \Aerospike\Expression
         */
        public static function intAnd(array $exps): \Aerospike\Expression {}

        /**
         * Create integer "arithmetic right shift" (>>) operator.
         *
         * @param \Aerospike\Expression $value
         * @param \Aerospike\Expression $shift
         * @return \Aerospike\Expression
         */
        public static function intArshift(\Aerospike\Expression $value, \Aerospike\Expression $shift): \Aerospike\Expression {}

        /**
         * Create 64 bit int bin expression.
         *
         * @param string $name
         * @return \Aerospike\Expression
         */
        public static function intBin(string $name): \Aerospike\Expression {}

        /**
         * Create expression that returns count of integer bits that are set to 1.
         *
         * @param \Aerospike\Expression $exp
         * @return \Aerospike\Expression
         */
        public static function intCount(\Aerospike\Expression $exp): \Aerospike\Expression {}

        /**
         * Create expression that scans integer bits left-to-right for a search bit value.
         *
         * @param \Aerospike\Expression $value
         * @param \Aerospike\Expression $search
         * @return \Aerospike\Expression
         */
        public static function intLscan(\Aerospike\Expression $value, \Aerospike\Expression $search): \Aerospike\Expression {}

        /**
         * Create integer "left shift" (<<) operator.
         *
         * @param \Aerospike\Expression $value
         * @param \Aerospike\Expression $shift
         * @return \Aerospike\Expression
         */
        public static function intLshift(\Aerospike\Expression $value, \Aerospike\Expression $shift): \Aerospike\Expression {}

        /**
         * Create integer "not" (~) operator.
         *
         * @param \Aerospike\Expression $exp
         * @return \Aerospike\Expression
         */
        public static function intNot(\Aerospike\Expression $exp): \Aerospike\Expression {}

        /**
         * Create integer "or" (|) operator.
         *
         * @param array $exps
         * @return \Aerospike\Expression
         */
        public static function intOr(array $exps): \Aerospike\Expression {}

        /**
         * Create expression that scans integer bits right-to-left for a search bit value.
         *
         * @param \Aerospike\Expression $value
         * @param \Aerospike\Expression $search
         * @return \Aerospike\Expression
         */
        public static function intRscan(\Aerospike\Expression $value, \Aerospike\Expression $search): \Aerospike\Expression {}

        /**
         * Create integer "logical right shift" (>>>) operator.
         *
         * @param \Aerospike\Expression $value
         * @param \Aerospike\Expression $shift
         * @return \Aerospike\Expression
         */
        public static function intRshift(\Aerospike\Expression $value, \Aerospike\Expression $shift): \Aerospike\Expression {}

        /**
         * Creates 64 bit integer value.
         *
         * @param int $val
         * @return \Aerospike\Expression
         */
        public static function intVal(int $val): \Aerospike\Expression {}

        /**
         * Create integer "xor" (^) operator.
         *
         * @param array $exps
         * @return \Aerospike\Expression
         */
        public static function intXor(array $exps): \Aerospike\Expression {}

        /**
         * Create expression that returns if record has been deleted and is still in tombstone state.
         *
         * @return \Aerospike\Expression
         */
        public static function isTombstone(): \Aerospike\Expression {}

        /**
         * Create a record key expression of specified type.
         *
         * @param mixed $exp_type
         * @return \Aerospike\Expression
         */
        public static function key(mixed $exp_type): \Aerospike\Expression {}

        /**
         * Create function that returns if the primary key is stored in the record meta data
         * as a boolean expression. This would occur when `send_key` is true on record write.
         *
         * @return \Aerospike\Expression
         */
        public static function keyExists(): \Aerospike\Expression {}

        /**
         * Create function that returns record last update time expressed as 64 bit integer
         * nanoseconds since 1970-01-01 epoch.
         *
         * @return \Aerospike\Expression
         */
        public static function lastUpdate(): \Aerospike\Expression {}

        /**
         * Create less than or equals (<=) operation.
         *
         * @param \Aerospike\Expression $left
         * @param \Aerospike\Expression $right
         * @return \Aerospike\Expression
         */
        public static function le(\Aerospike\Expression $left, \Aerospike\Expression $right): \Aerospike\Expression {}

        /**
         * Create list bin expression.
         *
         * @param string $name
         * @return \Aerospike\Expression
         */
        public static function listBin(string $name): \Aerospike\Expression {}

        /**
         * Create List bin value.
         *
         * @param array $val
         * @return \Aerospike\Expression
         */
        public static function listVal(array $val): \Aerospike\Expression {}

        /**
         * Create less than (<) operation.
         *
         * @param \Aerospike\Expression $left
         * @param \Aerospike\Expression $right
         * @return \Aerospike\Expression
         */
        public static function lt(\Aerospike\Expression $left, \Aerospike\Expression $right): \Aerospike\Expression {}

        /**
         * Create map bin expression.
         *
         * @param string $name
         * @return \Aerospike\Expression
         */
        public static function mapBin(string $name): \Aerospike\Expression {}

        /**
         * Create Map bin value. Returns `None` if `val` is not a PHP associative array (HashMap/Json).
         *
         * @param mixed $val
         * @return \Aerospike\Expression|null
         */
        public static function mapVal(mixed $val): ?\Aerospike\Expression {}

        /**
         * Create expression that returns the maximum value in a variable number of expressions.
         *
         * @param array $exps
         * @return \Aerospike\Expression
         */
        public static function max(array $exps): \Aerospike\Expression {}

        /**
         * Create expression that returns record size in memory (server 5.3..7.0).
         *
         * @return \Aerospike\Expression
         */
        public static function memorySize(): \Aerospike\Expression {}

        /**
         * Create expression that returns the minimum value in a variable number of expressions.
         *
         * @param array $exps
         * @return \Aerospike\Expression
         */
        public static function min(array $exps): \Aerospike\Expression {}

        /**
         * Create not equal (!=) expression.
         *
         * @param \Aerospike\Expression $left
         * @param \Aerospike\Expression $right
         * @return \Aerospike\Expression
         */
        public static function ne(\Aerospike\Expression $left, \Aerospike\Expression $right): \Aerospike\Expression {}

        /**
         * Create a Nil value.
         *
         * @return \Aerospike\Expression
         */
        public static function nil(): \Aerospike\Expression {}

        /**
         * Create "not" operator expression.
         *
         * @param \Aerospike\Expression $exp
         * @return \Aerospike\Expression
         */
        public static function not(\Aerospike\Expression $exp): \Aerospike\Expression {}

        /**
         * Create operator that returns absolute value of a number.
         *
         * @param \Aerospike\Expression $value
         * @return \Aerospike\Expression
         */
        public static function numAbs(\Aerospike\Expression $value): \Aerospike\Expression {}

        /**
         * Create "add" (+) operator that applies to a variable number of expressions.
         * Requires server version 5.6.0+.
         *
         * @param array $exps
         * @return \Aerospike\Expression
         */
        public static function numAdd(array $exps): \Aerospike\Expression {}

        /**
         * Create expression that rounds a floating point number up to the closest integer value.
         *
         * @param \Aerospike\Expression $num
         * @return \Aerospike\Expression
         */
        public static function numCeil(\Aerospike\Expression $num): \Aerospike\Expression {}

        /**
         * Create "divide" (/) operator that applies to a variable number of expressions.
         *
         * @param array $exps
         * @return \Aerospike\Expression
         */
        public static function numDiv(array $exps): \Aerospike\Expression {}

        /**
         * Create expression that rounds a floating point number down to the closest integer value.
         *
         * @param \Aerospike\Expression $num
         * @return \Aerospike\Expression
         */
        public static function numFloor(\Aerospike\Expression $num): \Aerospike\Expression {}

        /**
         * Create "log" operator for logarithm of "num" with base "base".
         *
         * @param \Aerospike\Expression $num
         * @param \Aerospike\Expression $base
         * @return \Aerospike\Expression
         */
        public static function numLog(\Aerospike\Expression $num, \Aerospike\Expression $base): \Aerospike\Expression {}

        /**
         * Create "modulo" (%) operator.
         *
         * @param \Aerospike\Expression $numerator
         * @param \Aerospike\Expression $denominator
         * @return \Aerospike\Expression
         */
        public static function numMod(\Aerospike\Expression $numerator, \Aerospike\Expression $denominator): \Aerospike\Expression {}

        /**
         * Create "multiply" (*) operator that applies to a variable number of expressions.
         *
         * @param array $exps
         * @return \Aerospike\Expression
         */
        public static function numMul(array $exps): \Aerospike\Expression {}

        /**
         * Create "power" operator that raises a "base" to the "exponent" power.
         *
         * @param \Aerospike\Expression $base
         * @param \Aerospike\Expression $exponent
         * @return \Aerospike\Expression
         */
        public static function numPow(\Aerospike\Expression $base, \Aerospike\Expression $exponent): \Aerospike\Expression {}

        /**
         * Create "subtract" (-) operator that applies to a variable number of expressions.
         *
         * @param array $exps
         * @return \Aerospike\Expression
         */
        public static function numSub(array $exps): \Aerospike\Expression {}

        /**
         * Create "or" (||) operator that applies to a variable number of expressions.
         *
         * @param array $exps
         * @return \Aerospike\Expression
         */
        public static function or(array $exps): \Aerospike\Expression {}

        /**
         * Create expression that returns record size on disk (server 7.0+).
         *
         * @return \Aerospike\Expression
         */
        public static function recordSize(): \Aerospike\Expression {}

        /**
         * Create function like regular expression string operation.
         *
         * @param string $regex
         * @param int $flags
         * @param \Aerospike\Expression $bin
         * @return \Aerospike\Expression
         */
        public static function regexCompare(string $regex, int $flags, \Aerospike\Expression $bin): \Aerospike\Expression {}

        /**
         * Create function that returns record set name string.
         *
         * @return \Aerospike\Expression
         */
        public static function setName(): \Aerospike\Expression {}

        /**
         * Create expression that returns milliseconds since the record was last updated.
         *
         * @return \Aerospike\Expression
         */
        public static function sinceUpdate(): \Aerospike\Expression {}

        /**
         * Create string bin expression.
         *
         * @param string $name
         * @return \Aerospike\Expression
         */
        public static function stringBin(string $name): \Aerospike\Expression {}

        /**
         * Creates String bin value.
         *
         * @param string $val
         * @return \Aerospike\Expression
         */
        public static function stringVal(string $val): \Aerospike\Expression {}

        /**
         * Create expression that converts an integer to a float.
         *
         * @param \Aerospike\Expression $num
         * @return \Aerospike\Expression
         */
        public static function toFloat(\Aerospike\Expression $num): \Aerospike\Expression {}

        /**
         * Create expression that converts a float to an integer.
         *
         * @param \Aerospike\Expression $num
         * @return \Aerospike\Expression
         */
        public static function toInt(\Aerospike\Expression $num): \Aerospike\Expression {}

        /**
         * Create function that returns record expiration time (TTL) in integer seconds.
         *
         * @return \Aerospike\Expression
         */
        public static function ttl(): \Aerospike\Expression {}

        /**
         * Create unknown value. Used to intentionally fail an expression.
         *
         * @return \Aerospike\Expression
         */
        public static function unknown(): \Aerospike\Expression {}

        /**
         * Retrieve expression value from a variable.
         *
         * @param string $name
         * @return \Aerospike\Expression
         */
        public static function var(string $name): \Aerospike\Expression {}

        /**
         * Create function that returns record expiration time expressed as 64 bit integer
         * nanoseconds since 1970-01-01 epoch.
         *
         * @return \Aerospike\Expression
         */
        public static function voidTime(): \Aerospike\Expression {}

        /**
         * Create a Wildcard value.
         *
         * @return \Aerospike\Expression
         */
        public static function wildcard(): \Aerospike\Expression {}

        /**
         * Create integer "xor" (^) operator that applies to a variable number of expressions.
         *
         * v1-compatible alias for `intXor` (the proto/v1 implementation also mapped to
         * integer XOR). For boolean XOR, call `boolXor` instead.
         *
         * @param array $exps
         * @return \Aerospike\Expression
         */
        public static function xor(array $exps): \Aerospike\Expression {}

        /**
         * Create boolean "xor" (^) operator that applies to a variable number of expressions.
         * New in v2 — exposes aero's boolean XOR builder (opcode 19). Use `intXor` / `xor`
         * for the integer-bitmask variant.
         *
         * @param array $exps
         * @return \Aerospike\Expression
         */
        public static function boolXor(array $exps): \Aerospike\Expression {}
    }

    /**
     * Query filter definition. Currently, only one filter is allowed in a Statement, and must be on a
     * bin which has a secondary index defined.
     */
    class Filter {
        public function __construct() {}

        /**
         * Creates a contains filter for queries on a collection index.
         *
         * @param string $bin_name
         * @param mixed $value
         * @param \Aerospike\IndexCollectionType|null $cit
         * @param array|null $ctx
         * @return \Aerospike\Filter
         */
        public static function contains(string $bin_name, mixed $value, ?\Aerospike\IndexCollectionType $cit = null, ?array $ctx = null): \Aerospike\Filter {}

        /**
         * Creates a contains-range filter for queries on a collection index. Only integer values
         * are supported.
         *
         * @param string $bin_name
         * @param mixed $begin
         * @param mixed $end
         * @param \Aerospike\IndexCollectionType|null $cit
         * @param array|null $ctx
         * @return \Aerospike\Filter
         */
        public static function containsRange(string $bin_name, mixed $begin, mixed $end, ?\Aerospike\IndexCollectionType $cit = null, ?array $ctx = null): \Aerospike\Filter {}

        /**
         * Creates an equality filter for queries. Value can be an integer, string, or blob.
         * Byte arrays are only supported on server v7+.
         *
         * @param string $bin_name
         * @param mixed $value
         * @param array|null $ctx
         * @return \Aerospike\Filter
         */
        public static function equal(string $bin_name, mixed $value, ?array $ctx = null): \Aerospike\Filter {}

        /**
         * Creates a range filter for queries. Only integer ranges are supported.
         *
         * @param string $bin_name
         * @param mixed $begin
         * @param mixed $end
         * @param array|null $ctx
         * @return \Aerospike\Filter
         */
        public static function range(string $bin_name, mixed $begin, mixed $end, ?array $ctx = null): \Aerospike\Filter {}

        /**
         * Creates a geospatial "regions containing point" filter for query.
         *
         * @param string $bin_name
         * @param float $lat
         * @param float $lng
         * @param \Aerospike\IndexCollectionType|null $cit
         * @param array|null $ctx
         * @return \Aerospike\Filter
         */
        public static function regionsContainingPoint(string $bin_name, float $lat, float $lng, ?\Aerospike\IndexCollectionType $cit = null, ?array $ctx = null): \Aerospike\Filter {}

        /**
         * Creates a geospatial "within radius" filter for query.
         *
         * @param string $bin_name
         * @param float $lat
         * @param float $lng
         * @param float $radius
         * @param \Aerospike\IndexCollectionType|null $cit
         * @param array|null $ctx
         * @return \Aerospike\Filter
         */
        public static function withinRadius(string $bin_name, float $lat, float $lng, float $radius, ?\Aerospike\IndexCollectionType $cit = null, ?array $ctx = null): \Aerospike\Filter {}

        /**
         * Creates a geospatial "within region" filter for query. Argument must be a valid GeoJSON region.
         *
         * @param string $bin_name
         * @param string $region
         * @param \Aerospike\IndexCollectionType|null $cit
         * @param array|null $ctx
         * @return \Aerospike\Filter
         */
        public static function withinRegion(string $bin_name, string $region, ?\Aerospike\IndexCollectionType $cit = null, ?array $ctx = null): \Aerospike\Filter {}
    }

    /**
     *
     *  GenerationPolicy
     *
     * `GenerationPolicy` determines how to handle record writes based on record generation.
     */
    class GenerationPolicy {
        public function __construct() {}

        /**
         * ExpectGenEqual means: Update/delete record if expected generation is equal to server generation.
         *
         * @return \Aerospike\GenerationPolicy
         */
        public static function expectGenEqual(): \Aerospike\GenerationPolicy {}

        /**
         * ExpectGenGreater means: Update/delete record if expected generation greater than the server generation.
         *
         * @return \Aerospike\GenerationPolicy
         */
        public static function expectGenGreater(): \Aerospike\GenerationPolicy {}

        /**
         * None means: Do not use record generation to restrict writes.
         *
         * @return \Aerospike\GenerationPolicy
         */
        public static function none(): \Aerospike\GenerationPolicy {}
    }

    /**
     * Implementation of the GeoJson Value for Aerospike.
     */
    class GeoJSON {
        public function __construct() {}

        /**
         * Returns a string representation of the value.
         *
         * @return string
         */
        public function asString(): string {}

        /**
         * @return string
         */
        public function getValue(): string {}

        /**
         * @param string $geo
         * @return void
         */
        public function setValue(string $geo): void {}
    }

    /**
     * Implementation of the HyperLogLog (HLL) data structure for Aerospike.
     */
    class HLL {
        public function __construct() {}

        /**
         * Returns a string representation of the value.
         *
         * @return string
         */
        public function asString(): string {}

        /**
         * @return array
         */
        public function getValue(): array {}

        /**
         * @param array $hll
         * @return void
         */
        public function setValue(array $hll): void {}
    }

    /**
     * HyperLogLog (HLL) operations.
     * Requires server versions >= 4.9.
     *
     * HyperLogLog operations on HLL items nested in lists/maps are not currently
     * supported by the server.
     */
    class HllOp {
        public function __construct() {}

        /**
         * HLLAddOp creates HLL add operation with minhash bits.
         * Server adds values to HLL set. If HLL bin does not exist, use indexBitCount and minHashBitCount
         * to create HLL bin. Server returns number of entries that caused HLL to update a register.
         *
         * @param \Aerospike\HllPolicy $policy
         * @param string $bin_name
         * @param array $list
         * @param int $index_bit_count
         * @param int $min_hash_bit_count
         * @return \Aerospike\Operation
         */
        public static function add(\Aerospike\HllPolicy $policy, string $bin_name, array $list, int $index_bit_count, int $min_hash_bit_count): \Aerospike\Operation {}

        /**
         * HLLDescribeOp creates HLL describe operation.
         * Server returns indexBitCount and minHashBitCount used to create HLL bin in a list of longs.
         * The list size is 2.
         *
         * @param string $bin_name
         * @return \Aerospike\Operation
         */
        public static function describe(string $bin_name): \Aerospike\Operation {}

        /**
         * HLLFoldOp creates HLL fold operation. Server folds indexBitCount to the specified value.
         * This can only be applied when minHashBitCount on the HLL bin is 0.
         *
         * @param string $bin_name
         * @param int $index_bit_count
         * @return \Aerospike\Operation
         */
        public static function fold(string $bin_name, int $index_bit_count): \Aerospike\Operation {}

        /**
         * HLLGetCountOp creates HLL getCount operation.
         * Server returns estimated number of elements in the HLL bin.
         *
         * @param string $bin_name
         * @return \Aerospike\Operation
         */
        public static function getCount(string $bin_name): \Aerospike\Operation {}

        /**
         * HLLGetIntersectCountOp creates HLL getIntersectCount operation.
         * Server returns estimated number of elements that would be contained by the intersection of
         * these HLL objects.
         *
         * @param string $bin_name
         * @param array $list
         * @return \Aerospike\Operation
         */
        public static function getIntersectCount(string $bin_name, array $list): \Aerospike\Operation {}

        /**
         * HLLGetSimilarityOp creates HLL getSimilarity operation.
         * Server returns estimated similarity of these HLL objects. Return type is a double.
         *
         * @param string $bin_name
         * @param array $list
         * @return \Aerospike\Operation
         */
        public static function getSimilarity(string $bin_name, array $list): \Aerospike\Operation {}

        /**
         * HLLGetUnionOp creates HLL getUnion operation.
         * Server returns an HLL object that is the union of all specified HLL objects in the list
         * with the HLL bin. Throws an AerospikeException if any element of `list` is not an HLL value.
         *
         * @param string $bin_name
         * @param array $list
         * @return \Aerospike\Operation
         */
        public static function getUnion(string $bin_name, array $list): \Aerospike\Operation {}

        /**
         * HLLGetUnionCountOp creates HLL getUnionCount operation.
         * Server returns estimated number of elements that would be contained by the union of these
         * HLL objects.
         *
         * @param string $bin_name
         * @param array $list
         * @return \Aerospike\Operation
         */
        public static function getUnionCount(string $bin_name, array $list): \Aerospike\Operation {}

        /**
         * HLLInitOp creates HLL init operation with minhash bits.
         * Server creates a new HLL or resets an existing HLL.
         * Server does not return a value.
         *
         * policy            write policy, use DefaultHLLPolicy for default
         * binName           name of bin
         * indexBitCount     number of index bits. Must be between 4 and 16 inclusive. Pass -1 for default.
         * minHashBitCount   number of min hash bits. Must be between 4 and 58 inclusive. Pass -1 for default.
         * indexBitCount + minHashBitCount must be <= 64.
         *
         * @param \Aerospike\HllPolicy $policy
         * @param string $bin_name
         * @param int $index_bit_count
         * @param int $min_hash_bit_count
         * @return \Aerospike\Operation
         */
        public static function init(\Aerospike\HllPolicy $policy, string $bin_name, int $index_bit_count, int $min_hash_bit_count): \Aerospike\Operation {}

        /**
         * HLLRefreshCountOp creates HLL refresh operation.
         * Server updates the cached count (if stale) and returns the count.
         *
         * @param string $bin_name
         * @return \Aerospike\Operation
         */
        public static function refreshCount(string $bin_name): \Aerospike\Operation {}

        /**
         * HLLSetUnionOp creates HLL set union operation.
         * Server sets union of specified HLL objects with HLL bin.
         * Throws an AerospikeException if any element of `list` is not an HLL value.
         *
         * @param \Aerospike\HllPolicy $policy
         * @param string $bin_name
         * @param array $list
         * @return \Aerospike\Operation
         */
        public static function setUnion(\Aerospike\HllPolicy $policy, string $bin_name, array $list): \Aerospike\Operation {}
    }

    /**
     * HLLPolicy determines the HyperLogLog operation policy.
     */
    class HllPolicy {
        /**
         * new HLLPolicy uses specified optional HLLWriteFlags when performing HLL operations.
         *
         * @param mixed $flags
         */
        public function __construct(mixed $flags = null) {}
    }

    /**
     * HLLWriteFlags specifies the HLL write operation flags.
     */
    class HllWriteFlags {
        public function __construct() {}

        /**
         * HLLWriteFlagsAllowFold allows the resulting set to be the minimum of provided index bits.
         * Also, allow the usage of less precise HLL algorithms when minHash bits
         * of all participating sets do not match.
         *
         * @return \Aerospike\HllWriteFlags
         */
        public static function allowFold(): \Aerospike\HllWriteFlags {}

        /**
         * HLLWriteFlagsCreateOnly behaves like the following:
         * If the bin already exists, the operation will be denied.
         * If the bin does not exist, a new bin will be created.
         *
         * @return \Aerospike\HllWriteFlags
         */
        public static function createOnly(): \Aerospike\HllWriteFlags {}

        /**
         * HLLWriteFlagsDefault is Default. Allow create or update.
         *
         * @return \Aerospike\HllWriteFlags
         */
        public static function default(): \Aerospike\HllWriteFlags {}

        /**
         * HLLWriteFlagsNoFail does not raise error if operation is denied.
         *
         * @return \Aerospike\HllWriteFlags
         */
        public static function noFail(): \Aerospike\HllWriteFlags {}

        /**
         * HLLWriteFlagsUpdateOnly behaves like the following:
         * If the bin already exists, the bin will be overwritten.
         * If the bin does not exist, the operation will be denied.
         *
         * @return \Aerospike\HllWriteFlags
         */
        public static function updateOnly(): \Aerospike\HllWriteFlags {}
    }

    /**
     * IndexCollectionType is the secondary index collection type.
     */
    class IndexCollectionType {
        public function __construct() {}

        /**
         * ICT_DEFAULT is the Normal scalar index.
         *
         * @return \Aerospike\IndexCollectionType
         */
        public static function default(): \Aerospike\IndexCollectionType {}

        /**
         * ICT_LIST is Index list elements.
         *
         * @return \Aerospike\IndexCollectionType
         */
        public static function list(): \Aerospike\IndexCollectionType {}

        /**
         * ICT_MAPKEYS is Index map keys.
         *
         * @return \Aerospike\IndexCollectionType
         */
        public static function mapKeys(): \Aerospike\IndexCollectionType {}

        /**
         * ICT_MAPVALUES is Index map values.
         *
         * @return \Aerospike\IndexCollectionType
         */
        public static function mapValues(): \Aerospike\IndexCollectionType {}
    }

    /**
     * IndexType the type of the secondary index.
     */
    class IndexType {
        public function __construct() {}

        /**
         * GEO2DSPHERE specifies 2-dimensional spherical geospatial index.
         *
         * @return \Aerospike\IndexType
         */
        public static function geo2DSphere(): \Aerospike\IndexType {}

        /**
         * NUMERIC specifies an index on numeric values.
         *
         * @return \Aerospike\IndexType
         */
        public static function numeric(): \Aerospike\IndexType {}

        /**
         * STRING specifies an index on string values.
         *
         * @return \Aerospike\IndexType
         */
        public static function string(): \Aerospike\IndexType {}
    }

    /**
     * Represents a infinity value for Aerospike.
     */
    class Infinity {
        public function __construct() {}
    }

    /**
     * `InfoPolicy` encapsulates parameters for all info-command operations.
     * (Standalone in v2 — aerospike-client-rust does not expose a dedicated info policy.)
     */
    class InfoPolicy {
        public function __construct() {}

        /**
         * @return int
         */
        public function getTimeout(): int {}

        /**
         * @param int $timeout_millis
         * @return void
         */
        public function setTimeout(int $timeout_millis): void {}
    }

    /**
     * Implementation of the Json (Map<String, Value>) data structure for Aerospike.
     */
    class Json {
        public function __construct() {}

        /**
         * Returns a string representation of the value.
         *
         * @return string
         */
        public function asString(): string {}

        /**
         * getter method to get the json value
         *
         * @return array
         */
        public function getValue(): array {}

        /**
         * setter method to set the json value
         *
         * @param array $v
         * @return void
         */
        public function setValue(array $v): void {}
    }

    /**
     * Key is the unique record identifier. Records can be identified using a specified namespace,
     * an optional set name, and a user defined key which must be unique within a set.
     * Records can also be identified by namespace/digest which is the combination used
     * on the server.
     */
    class Key {
        /**
         * @param string $namespace
         * @param string $set
         * @param mixed $key
         */
        public function __construct(string $namespace, string $set, mixed $key) {}

        /**
         * v1 compatibility shim: forwards `$key->namespace`, `->set`, `->setname`,
         * `->userKey`, `->value`, `->digest`, `->digestBytes` to the corresponding getters.
         *
         * @param string $name
         * @return mixed
         */
        public function __get(string $name): mixed {}

        /**
         * get_digest returns key digest as string.
         *
         * @return string
         */
        public function getDigest(): string {}

        /**
         * get_digest_bytes returns key digest as byte array.
         *
         * @return array
         */
        public function getDigestBytes(): array {}

        /**
         * namespace. Equivalent to database name.
         *
         * @return string
         */
        public function getNamespace(): string {}

        /**
         * Optional set name. Equivalent to database table.
         *
         * @return string
         */
        public function getSetname(): string {}

        /**
         * getValue() returns key's value.
         *
         * @return mixed
         */
        public function getValue(): mixed {}

        /**
         * PartitionId returns the partition that the key belongs to.
         *
         * @return int|null
         */
        public function partitionId(): ?int {}
    }

    /**
     * List operations support negative indexing.  If the index is negative, the
     * resolved index starts backwards from end of list. If an index is out of bounds,
     * a parameter error will be returned. If a range is partially out of bounds, the
     * valid part of the range will be returned. Index/Range examples:
     *
     * Index/Range examples:
     *
     *    Index 0: First item in list.
     *    Index 4: Fifth item in list.
     *    Index -1: Last item in list.
     *    Index -3: Third to last item in list.
     *    Index 1 Count 2: Second and third items in list.
     *    Index -3 Count 3: Last three items in list.
     *    Index -5 Count 4: Range between fifth to last item to second to last item inclusive.
     *
     */
    class ListOp {
        public function __construct() {}

        /**
         * ListAppendOp creates a list append operation.
         * Server appends values to end of list bin.
         * Server returns list size on bin name.
         * Throws an AerospikeException if `values` is empty.
         *
         * @param \Aerospike\ListPolicy $policy
         * @param string $bin_name
         * @param array $values
         * @param array|null $ctx
         * @return \Aerospike\Operation
         * @throws \Aerospike\AerospikeException
         */
        public static function append(\Aerospike\ListPolicy $policy, string $bin_name, array $values, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * ListClearOp creates a list clear operation.
         * Server removes all items in list bin.
         * Server does not return a result by default.
         *
         * @param string $bin_name
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function clear(string $bin_name, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * ListCreateOp creates list create operation.
         * Server creates list at given context level. The context is allowed to be beyond list
         * boundaries only if pad is set to true. When `index` is true, the list is created with a
         * persisted index (and the `pad` argument is ignored — aero's `create_with_index` does not
         * support padding).
         *
         * @param string $bin_name
         * @param mixed $order
         * @param bool $pad
         * @param bool|null $index
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function create(string $bin_name, mixed $order, bool $pad, ?bool $index = null, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * ListGetByIndexOp creates list get by index operation.
         * Server selects list item identified by index and returns selected data specified by returnType.
         *
         * @param string $bin_name
         * @param int $index
         * @param mixed $return_type
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function getByIndex(string $bin_name, int $index, mixed $return_type = null, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * ListGetByIndexRangeOp creates list get by index range operation.
         * Server selects list items starting at specified index to the end of list and returns selected
         * data specified by returnType.
         *
         * @param string $bin_name
         * @param int $index
         * @param mixed $return_type
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function getByIndexRange(string $bin_name, int $index, mixed $return_type = null, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * ListGetByIndexRangeCountOp creates list get by index range operation.
         * Server selects "count" list items starting at specified index and returns selected data specified
         * by returnType.
         *
         * @param string $bin_name
         * @param int $index
         * @param int $count
         * @param mixed $return_type
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function getByIndexRangeCount(string $bin_name, int $index, int $count, mixed $return_type = null, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * ListGetByRankOp creates a list get by rank operation.
         * Server selects list item identified by rank and returns selected data specified by returnType.
         *
         * @param string $bin_name
         * @param int $rank
         * @param mixed $return_type
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function getByRank(string $bin_name, int $rank, mixed $return_type = null, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * ListGetByRankRangeOp creates a list get by rank range operation.
         * Server selects list items starting at specified rank to the last ranked item and returns selected
         * data specified by returnType.
         *
         * @param string $bin_name
         * @param int $rank
         * @param mixed $return_type
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function getByRankRange(string $bin_name, int $rank, mixed $return_type = null, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * ListGetByRankRangeCountOp creates a list get by rank range operation.
         * Server selects "count" list items starting at specified rank and returns selected data specified by returnType.
         *
         * @param string $bin_name
         * @param int $rank
         * @param int $count
         * @param mixed $return_type
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function getByRankRangeCount(string $bin_name, int $rank, int $count, mixed $return_type = null, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * ListGetByValueRangeOp creates a list get by value range operation.
         * Server selects list items identified by value range (valueBegin inclusive, valueEnd exclusive)
         * If valueBegin is nil, the range is less than valueEnd.
         * If valueEnd is nil, the range is greater than equal to valueBegin.
         * Server returns selected data specified by returnType.
         *
         * @param string $bin_name
         * @param mixed $begin
         * @param mixed $end
         * @param mixed $return_type
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function getByValueRange(string $bin_name, mixed $begin, mixed $end = null, mixed $return_type = null, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * ListGetByValueRelativeRankRangeOp creates a list get by value relative to rank range operation.
         * Server selects list items nearest to value and greater by relative rank.
         * Server returns selected data specified by returnType.
         *
         * Examples for ordered list [0,4,5,9,11,15]:
         *
         *	(value,rank) = [selected items]
         *	(5,0) = [5,9,11,15]
         *	(5,1) = [9,11,15]
         *	(5,-1) = [4,5,9,11,15]
         *	(3,0) = [4,5,9,11,15]
         *	(3,3) = [11,15]
         *	(3,-3) = [0,4,5,9,11,15]
         *
         * @param string $bin_name
         * @param mixed $value
         * @param int $rank
         * @param mixed $return_type
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function getByValueRelativeRankRange(string $bin_name, mixed $value, int $rank, mixed $return_type = null, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * ListGetByValueRelativeRankRangeCountOp creates a list get by value relative to rank range operation.
         * Server selects list items nearest to value and greater by relative rank with a count limit.
         * Server returns selected data specified by returnType.
         *
         * Examples for ordered list [0,4,5,9,11,15]:
         *
         *	(value,rank,count) = [selected items]
         *	(5,0,2) = [5,9]
         *	(5,1,1) = [9]
         *	(5,-1,2) = [4,5]
         *	(3,0,1) = [4]
         *	(3,3,7) = [11,15]
         *	(3,-3,2) = []
         *
         * @param string $bin_name
         * @param mixed $value
         * @param int $rank
         * @param int $count
         * @param mixed $return_type
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function getByValueRelativeRankRangeCount(string $bin_name, mixed $value, int $rank, int $count, mixed $return_type = null, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * ListGetByValueListOp creates a list get by value operation.
         * Server selects list items identified by values and returns selected data specified by returnType.
         *
         * @param string $bin_name
         * @param array $values
         * @param mixed $return_type
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function getByValues(string $bin_name, array $values, mixed $return_type = null, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * ListIncrementOp creates a list increment operation.
         * Server increments list[index] by value.
         * Server returns list[index] after incrementing.
         *
         * v2 BREAKING: aerospike-client-rust v2 only supports integer increments. Float
         * increments accepted by the proto version are no longer supported.
         *
         * @param string $bin_name
         * @param int $index
         * @param int $value
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function increment(string $bin_name, int $index, int $value, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * ListInsertOp creates a list insert operation.
         * Server inserts values starting at specified index of list bin.
         * Server returns list size on bin name.
         * Throws an AerospikeException if `values` is empty.
         *
         * @param \Aerospike\ListPolicy $policy
         * @param string $bin_name
         * @param int $index
         * @param array $values
         * @param array|null $ctx
         * @return \Aerospike\Operation
         * @throws \Aerospike\AerospikeException
         */
        public static function insert(\Aerospike\ListPolicy $policy, string $bin_name, int $index, array $values, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * ListPopOp creates list pop operation.
         * Server returns item at specified index and removes item from list bin.
         *
         * @param string $bin_name
         * @param int $index
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function pop(string $bin_name, int $index, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * ListPopRangeOp creates a list pop range operation.
         * Server returns items starting at specified index and removes items from list bin.
         *
         * @param string $bin_name
         * @param int $index
         * @param int $count
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function popRange(string $bin_name, int $index, int $count, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * ListPopRangeFromOp creates a list pop range operation.
         * Server returns items starting at specified index to the end of list and removes items from list bin.
         *
         * @param string $bin_name
         * @param int $index
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function popRangeFrom(string $bin_name, int $index, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * ListRemoveByIndexOp creates a list remove operation.
         * Server removes list item identified by index and returns removed data specified by returnType.
         *
         * @param string $bin_name
         * @param int $index
         * @param mixed $return_type
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function removeByIndex(string $bin_name, int $index, mixed $return_type = null, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * ListRemoveByIndexRangeOp creates a list remove operation.
         * Server removes list items starting at specified index to the end of list and returns removed
         * data specified by returnType.
         *
         * @param string $bin_name
         * @param int $index
         * @param mixed $return_type
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function removeByIndexRange(string $bin_name, int $index, mixed $return_type = null, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * ListRemoveByIndexRangeCountOp creates a list remove operation.
         * Server removes "count" list items starting at specified index and returns removed data specified by returnType.
         *
         * @param string $bin_name
         * @param int $index
         * @param int $count
         * @param mixed $return_type
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function removeByIndexRangeCount(string $bin_name, int $index, int $count, mixed $return_type = null, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * ListRemoveByRankOp creates a list remove operation.
         * Server removes list item identified by rank and returns removed data specified by returnType.
         *
         * @param string $bin_name
         * @param int $rank
         * @param mixed $return_type
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function removeByRank(string $bin_name, int $rank, mixed $return_type = null, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * ListRemoveByRankRangeOp creates a list remove operation.
         * Server removes list items starting at specified rank to the last ranked item and returns removed
         * data specified by returnType.
         *
         * @param string $bin_name
         * @param int $rank
         * @param mixed $return_type
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function removeByRankRange(string $bin_name, int $rank, mixed $return_type = null, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * ListRemoveByRankRangeCountOp creates a list remove operation.
         * Server removes "count" list items starting at specified rank and returns removed data specified by returnType.
         *
         * @param string $bin_name
         * @param int $rank
         * @param int $count
         * @param mixed $return_type
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function removeByRankRangeCount(string $bin_name, int $rank, int $count, mixed $return_type = null, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * ListRemoveByValueRangeOp creates a list remove operation.
         * Server removes list items identified by value range (valueBegin inclusive, valueEnd exclusive).
         * If valueBegin is nil, the range is less than valueEnd.
         * If valueEnd is nil, the range is greater than equal to valueBegin.
         * Server returns removed data specified by returnType.
         *
         * @param string $bin_name
         * @param mixed $begin
         * @param mixed $end
         * @param mixed $return_type
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function removeByValueRange(string $bin_name, mixed $begin, mixed $end = null, mixed $return_type = null, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * ListRemoveByValueRelativeRankRangeOp creates a list remove by value relative to rank range operation.
         * Server removes list items nearest to value and greater by relative rank.
         * Server returns removed data specified by returnType.
         *
         * Examples for ordered list [0,4,5,9,11,15]:
         *
         *	(value,rank) = [removed items]
         *	(5,0) = [5,9,11,15]
         *	(5,1) = [9,11,15]
         *	(5,-1) = [4,5,9,11,15]
         *	(3,0) = [4,5,9,11,15]
         *	(3,3) = [11,15]
         *	(3,-3) = [0,4,5,9,11,15]
         *
         * @param string $bin_name
         * @param mixed $value
         * @param int $rank
         * @param mixed $return_type
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function removeByValueRelativeRankRange(string $bin_name, mixed $value, int $rank, mixed $return_type = null, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * ListRemoveByValueRelativeRankRangeCountOp creates a list remove by value relative to rank range operation.
         * Server removes list items nearest to value and greater by relative rank with a count limit.
         * Server returns removed data specified by returnType.
         * Examples for ordered list [0,4,5,9,11,15]:
         *
         *	(value,rank,count) = [removed items]
         *	(5,0,2) = [5,9]
         *	(5,1,1) = [9]
         *	(5,-1,2) = [4,5]
         *	(3,0,1) = [4]
         *	(3,3,7) = [11,15]
         *	(3,-3,2) = []
         *
         * @param string $bin_name
         * @param mixed $value
         * @param int $rank
         * @param int $count
         * @param mixed $return_type
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function removeByValueRelativeRankRangeCount(string $bin_name, mixed $value, int $rank, int $count, mixed $return_type = null, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * ListRemoveRangeOp creates a list remove range operation.
         * Server removes "count" items starting at specified index from list bin.
         * Server returns number of items removed.
         *
         * @param string $bin_name
         * @param int $index
         * @param int $count
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function removeRange(string $bin_name, int $index, int $count, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * ListRemoveRangeFromOp creates a list remove range operation.
         * Server removes all items starting at specified index to the end of list.
         * Server returns number of items removed.
         *
         * @param string $bin_name
         * @param int $index
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function removeRangeFrom(string $bin_name, int $index, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * ListRemoveByValueListOp creates list remove by value operation.
         * Server removes items identified by values and returns removed data specified by returnType.
         *
         * @param string $bin_name
         * @param array $values
         * @param mixed $return_type
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function removeValues(string $bin_name, array $values, mixed $return_type = null, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * ListSetOp creates a list set operation.
         * Server sets item value at specified index in list bin.
         * Server does not return a result by default.
         * Throws an AerospikeException if `value` is null.
         *
         * @param string $bin_name
         * @param int $index
         * @param mixed $value
         * @param array|null $ctx
         * @return \Aerospike\Operation
         * @throws \Aerospike\AerospikeException
         */
        public static function set(string $bin_name, int $index, mixed $value, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * ListSetOrderOp creates a set list order operation.
         * Server sets list order. Server returns nil.
         *
         * @param string $bin_name
         * @param mixed $order
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function setOrder(string $bin_name, mixed $order, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * ListSizeOp creates a list size operation.
         * Server returns size of list on bin name.
         *
         * @param string $bin_name
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function size(string $bin_name, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * ListSortOp creates list sort operation.
         * Server sorts list according to sortFlags.
         * Server does not return a result by default.
         *
         * @param string $bin_name
         * @param \Aerospike\ListSortFlags $sort_flags
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function sort(string $bin_name, \Aerospike\ListSortFlags $sort_flags, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * ListTrimOp creates a list trim operation.
         * Server removes items in list bin that do not fall into range specified by index
         * and count range. If the range is out of bounds, then all items will be removed.
         * Server returns number of elements that were removed.
         *
         * @param string $bin_name
         * @param int $index
         * @param int $count
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function trim(string $bin_name, int $index, int $count, ?array $ctx = null): \Aerospike\Operation {}
    }

    /**
     * Specifies whether a command, that needs to be executed on multiple cluster nodes, should be
     * executed sequentially, one node at a time, or in parallel on multiple nodes using the client's
     * thread pool.
     */
    class ListOrderType {
        public function __construct() {}

        /**
         * @return int
         */
        public function flag(): int {}

        /**
         * ListOrderOrdered signifies that list is Ordered.
         *
         * @return \Aerospike\ListOrderType
         */
        public static function ordered(): \Aerospike\ListOrderType {}

        /**
         * ListOrderUnordered signifies that list is not ordered. This is the default.
         *
         * @return \Aerospike\ListOrderType
         */
        public static function unordered(): \Aerospike\ListOrderType {}
    }

    /**
     * ListPolicy directives when creating a list and writing list items.
     */
    class ListPolicy {
        /**
         * NewListPolicy creates a policy with directives when creating a list and writing list items.
         * Flags are ListWriteFlags. You can specify multiple by passing multiple values in the array;
         * they are combined with a bitwise OR.
         *
         * @param mixed $order
         * @param array|null $flags
         */
        public function __construct(mixed $order, ?array $flags = null) {}
    }

    /**
     * ListReturnType determines the returned values in CDT List operations.
     */
    class ListReturnType {
        public function __construct() {}

        /**
         * ListReturnTypeCount will return count of items selected.
         *
         * @return \Aerospike\ListReturnType
         */
        public static function count(): \Aerospike\ListReturnType {}

        /**
         * ListReturnTypeExists returns true if count > 0.
         *
         * @return \Aerospike\ListReturnType
         */
        public static function exists(): \Aerospike\ListReturnType {}

        /**
         * ListReturnTypeIndex will return index offset order.
         * 0 = first key
         * N = Nth key
         * -1 = last key
         *
         * @return \Aerospike\ListReturnType
         */
        public static function index(): \Aerospike\ListReturnType {}

        /**
         * ListReturnTypeInverted will invert meaning of list command and return values.  For example:
         * ListOperation.getByIndexRange(binName, index, count, ListReturnType.INDEX | ListReturnType.INVERTED)
         * With the INVERTED flag enabled, the items outside of the specified index range will be returned.
         * The meaning of the list command can also be inverted.  For example:
         * ListOperation.removeByIndexRange(binName, index, count, ListReturnType.INDEX | ListReturnType.INVERTED);
         * With the INVERTED flag enabled, the items outside of the specified index range will be removed and returned.
         *
         * @return \Aerospike\ListReturnType
         */
        public function inverted(): \Aerospike\ListReturnType {}

        /**
         * ListReturnTypeNone will not return a result.
         *
         * @return \Aerospike\ListReturnType
         */
        public static function none(): \Aerospike\ListReturnType {}

        /**
         * ListReturnTypeRank will return value order.
         * 0 = smallest value
         * N = Nth smallest value
         * -1 = largest value
         *
         * @return \Aerospike\ListReturnType
         */
        public static function rank(): \Aerospike\ListReturnType {}

        /**
         * ListReturnTypeReverseIndex will return reverse index offset order.
         * 0 = last key
         * -1 = first key
         *
         * @return \Aerospike\ListReturnType
         */
        public static function reverseIndex(): \Aerospike\ListReturnType {}

        /**
         * ListReturnTypeReverseRank will return reverse value order.
         * 0 = largest value
         * N = Nth largest value
         * -1 = smallest value
         *
         * @return \Aerospike\ListReturnType
         */
        public static function reverseRank(): \Aerospike\ListReturnType {}

        /**
         * ListReturnTypeValues will return value for single key read and value list for range read.
         * Note: proto named this variant `Value`; aero equivalent is `Values` (discriminant 7).
         *
         * @return \Aerospike\ListReturnType
         */
        public static function value(): \Aerospike\ListReturnType {}
    }

    /**
     * ListSortFlags determines sort flags for CDT list operations.
     */
    class ListSortFlags {
        public function __construct() {}

        /**
         * ListSortFlagsDefault is the default sort flag for CDT lists, and sorts in ascending order.
         *
         * @return \Aerospike\ListSortFlags
         */
        public static function default(): \Aerospike\ListSortFlags {}

        /**
         * ListSortFlagsDescending will sort the contents of the list in descending order.
         *
         * @return \Aerospike\ListSortFlags
         */
        public static function descending(): \Aerospike\ListSortFlags {}

        /**
         * ListSortFlagsDropDuplicates will drop duplicate values in the results of the CDT list operation.
         *
         * @return \Aerospike\ListSortFlags
         */
        public static function dropDuplicates(): \Aerospike\ListSortFlags {}
    }

    /**
     * ListWriteFlags determines write flags for CDT lists.
     */
    class ListWriteFlags {
        public function __construct() {}

        /**
         * ListWriteFlagsAddUnique means: only add unique values.
         *
         * @return \Aerospike\ListWriteFlags
         */
        public static function addUnique(): \Aerospike\ListWriteFlags {}

        /**
         * ListWriteFlagsDefault is the default behavior: allow duplicate values and insertions at any index.
         *
         * @return \Aerospike\ListWriteFlags
         */
        public static function default(): \Aerospike\ListWriteFlags {}

        /**
         * ListWriteFlagsInsertBounded means: enforce list boundaries when inserting. Do not allow values
         * to be inserted at an index outside the current list boundaries.
         *
         * @return \Aerospike\ListWriteFlags
         */
        public static function insertBounded(): \Aerospike\ListWriteFlags {}

        /**
         * ListWriteFlagsNoFail means: do not raise error if a list item fails due to write flag constraints.
         *
         * @return \Aerospike\ListWriteFlags
         */
        public static function noFail(): \Aerospike\ListWriteFlags {}

        /**
         * ListWriteFlagsPartial means: allow other valid list items to be committed if a list item fails due to
         * write flag constraints.
         *
         * @return \Aerospike\ListWriteFlags
         */
        public static function partial(): \Aerospike\ListWriteFlags {}
    }

    /**
     * Unique key map bin operations. Create map operations used by the client operate command.
     * The default unique key map is unordered.
     *
     * All maps maintain an index and a rank.  The index is the item offset from the start of the map,
     * for both unordered and ordered maps.  The rank is the sorted index of the value component.
     * Map supports negative indexing for index and rank.
     *
     * Index examples:
     *
     *  Index 0: First item in map.
     *  Index 4: Fifth item in map.
     *  Index -1: Last item in map.
     *  Index -3: Third to last item in map.
     *  Index 1 Count 2: Second and third items in map.
     *  Index -3 Count 3: Last three items in map.
     *  Index -5 Count 4: Range between fifth to last item to second to last item inclusive.
     *
     *
     * Rank examples:
     *
     *  Rank 0: Item with lowest value rank in map.
     *  Rank 4: Fifth lowest ranked item in map.
     *  Rank -1: Item with highest ranked value in map.
     *  Rank -3: Item with third highest ranked value in map.
     *  Rank 1 Count 2: Second and third lowest ranked items in map.
     *  Rank -3 Count 3: Top three ranked items in map.
     *
     *
     * Nested CDT operations are supported by optional CTX context arguments.  Examples:
     *
     *  bin = {key1:{key11:9,key12:4}, key2:{key21:3,key22:5}}
     *  Set map value to 11 for map key "key21" inside of map key "key2".
     *  MapOperation.put(MapPolicy.Default, "bin", StringValue("key21"), IntegerValue(11), CtxMapKey(StringValue("key2")))
     *  bin result = {key1:{key11:9,key12:4},key2:{key21:11,key22:5}}
     *
     *  bin : {key1:{key11:{key111:1},key12:{key121:5}}, key2:{key21:{"key211":7}}}
     *  Set map value to 11 in map key "key121" for highest ranked map ("key12") inside of map key "key1".
     *  MapPutOp(DefaultMapPolicy(), "bin", StringValue("key121"), IntegerValue(11), CtxMapKey(StringValue("key1")), CtxMapRank(-1))
     *  bin result = {key1:{key11:{key111:1},key12:{key121:11}}, key2:{key21:{"key211":7}}}
     */
    class MapOp {
        public function __construct() {}

        /**
         * MapClearOp creates map clear operation.
         * Server removes all items in map. Server returns nil.
         *
         * @param string $bin_name
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function clear(string $bin_name, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * MapCreateOp creates a map create operation.
         * Server creates map at given context level.
         *
         * v2 BREAKING: When `with_index` is `true`, the operation falls back to
         * `aero::operations::maps::create_with_index` which does NOT accept a CDT context.
         * Callers that used `with_index=true` together with `ctx` should drop `ctx` or split
         * the call into a separate `set_policy` operation.
         *
         * @param string $bin_name
         * @param \Aerospike\MapOrderType $order
         * @param bool|null $with_index
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function create(string $bin_name, \Aerospike\MapOrderType $order, ?bool $with_index = null, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * MapDecrementOp creates map decrement operation.
         * Server decrements values by `decr` for the item identified by `key` and returns final
         * result. Valid only for numbers.
         *
         * @param \Aerospike\MapPolicy $policy
         * @param string $bin_name
         * @param mixed $key
         * @param mixed $decr
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function decrement(\Aerospike\MapPolicy $policy, string $bin_name, mixed $key, mixed $decr, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * MapGetByIndexOp creates map get by index operation. Should be used with BatchRead.
         *
         * @param \Aerospike\MapPolicy $_policy
         * @param string $bin_name
         * @param int $index
         * @param mixed $return_type
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function getByIndex(\Aerospike\MapPolicy $_policy, string $bin_name, int $index, mixed $return_type = null, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * MapGetByIndexRangeOp creates map get by index range operation.
         *
         * v2 BREAKING: aerospike-client-rust v2 expects an `i64` index, not a `PHPValue` begin/end
         * pair. The previous proto-based signature was incompatible with the wire protocol and is
         * replaced by `(index: i64)`. Server selects map items starting at the specified index to
         * the end of the map. Should be used with BatchRead.
         *
         * @param \Aerospike\MapPolicy $_policy
         * @param string $bin_name
         * @param int $index
         * @param mixed $return_type
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function getByIndexRange(\Aerospike\MapPolicy $_policy, string $bin_name, int $index, mixed $return_type = null, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * MapGetByIndexRangeCountOp creates map get by index range operation.
         *
         * v2 BREAKING: the previous `rank` parameter (a copy-paste artifact from
         * `get_by_rank_range_count`) is removed. New signature is `(index, count)`.
         *
         * @param \Aerospike\MapPolicy $_policy
         * @param string $bin_name
         * @param int $index
         * @param int $count
         * @param mixed $return_type
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function getByIndexRangeCount(\Aerospike\MapPolicy $_policy, string $bin_name, int $index, int $count, mixed $return_type = null, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * MapGetByKeyRangeOp creates map get by key range operation.
         * Should be used with BatchRead.
         *
         * @param \Aerospike\MapPolicy $_policy
         * @param string $bin_name
         * @param mixed $begin
         * @param mixed $end
         * @param mixed $return_type
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function getByKeyRange(\Aerospike\MapPolicy $_policy, string $bin_name, mixed $begin, mixed $end, mixed $return_type = null, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * MapGetByKeyRelativeIndexRangeOp creates a map get by key relative to index range operation.
         *
         * @param \Aerospike\MapPolicy $_policy
         * @param string $bin_name
         * @param mixed $key
         * @param int $index
         * @param mixed $return_type
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function getByKeyRelativeIndexRange(\Aerospike\MapPolicy $_policy, string $bin_name, mixed $key, int $index, mixed $return_type = null, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * MapGetByKeyRelativeIndexRangeCountOp creates a map get by key relative to index range operation.
         *
         * @param \Aerospike\MapPolicy $_policy
         * @param string $bin_name
         * @param mixed $key
         * @param int $index
         * @param int $count
         * @param mixed $return_type
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function getByKeyRelativeIndexRangeCount(\Aerospike\MapPolicy $_policy, string $bin_name, mixed $key, int $index, int $count, mixed $return_type = null, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * MapGetByKeyListOp creates a map get by key list operation. Should be used with BatchRead.
         *
         * @param \Aerospike\MapPolicy $_policy
         * @param string $bin_name
         * @param array $keys
         * @param mixed $return_type
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function getByKeys(\Aerospike\MapPolicy $_policy, string $bin_name, array $keys, mixed $return_type = null, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * MapGetByRankOp creates map get by rank operation. Should be used with BatchRead.
         *
         * @param \Aerospike\MapPolicy $_policy
         * @param string $bin_name
         * @param int $rank
         * @param mixed $return_type
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function getByRank(\Aerospike\MapPolicy $_policy, string $bin_name, int $rank, mixed $return_type = null, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * MapGetByRankRangeOp creates map get by rank range operation.
         *
         * v2 BREAKING: signature changed from `(begin, end: PHPValue)` to `(rank: i64)`.
         * Server selects map items starting at the specified rank to the last ranked item.
         *
         * @param \Aerospike\MapPolicy $_policy
         * @param string $bin_name
         * @param int $rank
         * @param mixed $return_type
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function getByRankRange(\Aerospike\MapPolicy $_policy, string $bin_name, int $rank, mixed $return_type = null, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * MapGetByRankRangeCountOp creates map get by rank range operation.
         *
         * v2 BREAKING: the previous `range` parameter (copy-paste artifact) is removed.
         * New signature is `(rank, count)`.
         *
         * @param \Aerospike\MapPolicy $_policy
         * @param string $bin_name
         * @param int $rank
         * @param int $count
         * @param mixed $return_type
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function getByRankRangeCount(\Aerospike\MapPolicy $_policy, string $bin_name, int $rank, int $count, mixed $return_type = null, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * MapGetByValueRangeOp creates map get by value range operation. Should be used with BatchRead.
         *
         * @param \Aerospike\MapPolicy $_policy
         * @param string $bin_name
         * @param mixed $begin
         * @param mixed $end
         * @param mixed $return_type
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function getByValueRange(\Aerospike\MapPolicy $_policy, string $bin_name, mixed $begin, mixed $end, mixed $return_type = null, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * MapGetByValueRelativeRankRangeOp creates a map get by value relative to rank range operation.
         *
         * @param \Aerospike\MapPolicy $_policy
         * @param string $bin_name
         * @param mixed $value
         * @param int $rank
         * @param mixed $return_type
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function getByValueRelativeRankRange(\Aerospike\MapPolicy $_policy, string $bin_name, mixed $value, int $rank, mixed $return_type = null, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * MapGetByValueRelativeRankRangeCountOp creates a map get by value relative to rank range operation.
         *
         * @param \Aerospike\MapPolicy $_policy
         * @param string $bin_name
         * @param mixed $value
         * @param int $rank
         * @param int $count
         * @param mixed $return_type
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function getByValueRelativeRankRangeCount(\Aerospike\MapPolicy $_policy, string $bin_name, mixed $value, int $rank, int $count, mixed $return_type = null, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * MapGetByValueListOp creates a map get by value list operation. Should be used with BatchRead.
         *
         * @param \Aerospike\MapPolicy $_policy
         * @param string $bin_name
         * @param array $values
         * @param mixed $return_type
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function getByValues(\Aerospike\MapPolicy $_policy, string $bin_name, array $values, mixed $return_type = null, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * MapIncrementOp creates map increment operation.
         * Server increments values by `incr` for the item identified by `key` and returns final
         * result. Valid only for numbers.
         *
         * @param \Aerospike\MapPolicy $policy
         * @param string $bin_name
         * @param mixed $key
         * @param mixed $incr
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function increment(\Aerospike\MapPolicy $policy, string $bin_name, mixed $key, mixed $incr, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * MapPutOp creates map put-items operation.
         * Server writes each key/value item to the map bin and returns the map size.
         * Throws an AerospikeException if `map` is not a PHP associative array (map).
         *
         * @param \Aerospike\MapPolicy $policy
         * @param string $bin_name
         * @param mixed $map
         * @param array|null $ctx
         * @return \Aerospike\Operation
         * @throws \Aerospike\AerospikeException
         */
        public static function put(\Aerospike\MapPolicy $policy, string $bin_name, mixed $map, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * MapRemoveByIndexOp creates map remove operation.
         * Server removes map item identified by index and returns removed data specified by returnType.
         *
         * @param \Aerospike\MapPolicy $_policy
         * @param string $bin_name
         * @param int $index
         * @param mixed $return_type
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function removeByIndex(\Aerospike\MapPolicy $_policy, string $bin_name, int $index, mixed $return_type = null, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * MapRemoveByIndexRangeOp creates map remove operation.
         * Server removes map items starting at specified index to the end of map.
         *
         * @param \Aerospike\MapPolicy $_policy
         * @param string $bin_name
         * @param int $index
         * @param mixed $return_type
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function removeByIndexRange(\Aerospike\MapPolicy $_policy, string $bin_name, int $index, mixed $return_type = null, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * MapRemoveByIndexRangeCountOp creates map remove operation.
         * Server removes "count" map items starting at specified index.
         *
         * @param \Aerospike\MapPolicy $_policy
         * @param string $bin_name
         * @param int $index
         * @param int $count
         * @param mixed $return_type
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function removeByIndexRangeCount(\Aerospike\MapPolicy $_policy, string $bin_name, int $index, int $count, mixed $return_type = null, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * MapRemoveByKeyRangeOp creates map remove operation.
         * Server removes map items identified by key range (keyBegin inclusive, keyEnd exclusive).
         * If keyBegin is nil, the range is less than keyEnd.
         * If keyEnd is nil, the range is greater than equal to keyBegin.
         *
         * `policy` is accepted for PHP API stability and currently has no effect.
         *
         * @param \Aerospike\MapPolicy $_policy
         * @param string $bin_name
         * @param mixed $begin
         * @param mixed $end
         * @param mixed $return_type
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function removeByKeyRange(\Aerospike\MapPolicy $_policy, string $bin_name, mixed $begin, mixed $end, mixed $return_type = null, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * MapRemoveByKeyRelativeIndexRangeOp creates a map remove by key relative to index range operation.
         *
         * @param \Aerospike\MapPolicy $_policy
         * @param string $bin_name
         * @param mixed $key
         * @param int $index
         * @param mixed $return_type
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function removeByKeyRelativeIndexRange(\Aerospike\MapPolicy $_policy, string $bin_name, mixed $key, int $index, mixed $return_type = null, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * MapRemoveByKeyRelativeIndexRangeCountOp creates map remove by key relative to index range operation.
         *
         * @param \Aerospike\MapPolicy $_policy
         * @param string $bin_name
         * @param mixed $key
         * @param int $index
         * @param int $count
         * @param mixed $return_type
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function removeByKeyRelativeIndexRangeCount(\Aerospike\MapPolicy $_policy, string $bin_name, mixed $key, int $index, int $count, mixed $return_type = null, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * MapRemoveByKeyListOp creates map remove operation.
         * Server removes map items identified by keys and returns removed data specified by returnType.
         *
         * @param string $bin_name
         * @param array $keys
         * @param mixed $return_type
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function removeByKeys(string $bin_name, array $keys, mixed $return_type = null, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * MapRemoveByRankOp creates map remove operation.
         * Server removes map item identified by rank and returns removed data specified by returnType.
         *
         * @param \Aerospike\MapPolicy $_policy
         * @param string $bin_name
         * @param int $rank
         * @param mixed $return_type
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function removeByRank(\Aerospike\MapPolicy $_policy, string $bin_name, int $rank, mixed $return_type = null, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * MapRemoveByRankRangeOp creates map remove operation.
         * Server removes map items starting at specified rank to the last ranked item.
         *
         * @param \Aerospike\MapPolicy $_policy
         * @param string $bin_name
         * @param int $rank
         * @param mixed $return_type
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function removeByRankRange(\Aerospike\MapPolicy $_policy, string $bin_name, int $rank, mixed $return_type = null, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * MapRemoveByRankRangeCountOp creates map remove operation.
         * Server removes "count" map items starting at specified rank.
         *
         * @param \Aerospike\MapPolicy $_policy
         * @param string $bin_name
         * @param int $rank
         * @param int $count
         * @param mixed $return_type
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function removeByRankRangeCount(\Aerospike\MapPolicy $_policy, string $bin_name, int $rank, int $count, mixed $return_type = null, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * MapRemoveByValueRangeOp creates map remove operation.
         * Server removes map items identified by value range (valueBegin inclusive, valueEnd exclusive).
         *
         * @param \Aerospike\MapPolicy $_policy
         * @param string $bin_name
         * @param mixed $begin
         * @param mixed $end
         * @param mixed $return_type
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function removeByValueRange(\Aerospike\MapPolicy $_policy, string $bin_name, mixed $begin, mixed $end, mixed $return_type = null, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * MapRemoveByValueRelativeRankRangeOp creates a map remove by value relative to rank range operation.
         * Server removes map items nearest to value and greater by relative rank.
         *
         * @param \Aerospike\MapPolicy $_policy
         * @param string $bin_name
         * @param mixed $value
         * @param int $rank
         * @param mixed $return_type
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function removeByValueRelativeRankRange(\Aerospike\MapPolicy $_policy, string $bin_name, mixed $value, int $rank, mixed $return_type = null, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * MapRemoveByValueRelativeRankRangeCountOp creates a map remove by value relative to rank range operation.
         * Server removes map items nearest to value and greater by relative rank with a count limit.
         *
         * @param \Aerospike\MapPolicy $_policy
         * @param string $bin_name
         * @param mixed $value
         * @param int $rank
         * @param int $count
         * @param mixed $return_type
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function removeByValueRelativeRankRangeCount(\Aerospike\MapPolicy $_policy, string $bin_name, mixed $value, int $rank, int $count, mixed $return_type = null, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * MapRemoveByValueListOp creates map remove operation.
         * Server removes map items identified by values and returns removed data specified by returnType.
         *
         * @param \Aerospike\MapPolicy $_policy
         * @param string $bin_name
         * @param array $values
         * @param mixed $return_type
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function removeByValues(\Aerospike\MapPolicy $_policy, string $bin_name, array $values, mixed $return_type = null, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * MapSetPolicyOp creates set map policy operation.
         * Server sets map policy attributes. Server returns nil.
         *
         * The required map policy attributes can be changed after the map is created.
         *
         * @param \Aerospike\MapPolicy $policy
         * @param string $bin_name
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function setPolicy(\Aerospike\MapPolicy $policy, string $bin_name, ?array $ctx = null): \Aerospike\Operation {}

        /**
         * MapSizeOp creates map size operation.
         * Server returns size of map.
         *
         * @param string $bin_name
         * @param array|null $ctx
         * @return \Aerospike\Operation
         */
        public static function size(string $bin_name, ?array $ctx = null): \Aerospike\Operation {}
    }

    /**
     * Specifies whether a command, that needs to be executed on multiple cluster nodes, should be
     * executed sequentially, one node at a time, or in parallel on multiple nodes using the client's
     * thread pool.
     */
    class MapOrderType {
        public function __construct() {}

        /**
         * @return int
         */
        public function attr(): int {}

        /**
         * @return int
         */
        public function flag(): int {}

        /**
         * Order map by key.
         *
         * @return \Aerospike\MapOrderType
         */
        public static function keyOrdered(): \Aerospike\MapOrderType {}

        /**
         * Order map by key, then value.
         *
         * @return \Aerospike\MapOrderType
         */
        public static function keyValueOrdered(): \Aerospike\MapOrderType {}

        /**
         * Map is not ordered. This is the default.
         *
         * @return \Aerospike\MapOrderType
         */
        public static function unordered(): \Aerospike\MapOrderType {}
    }

    /**
     * MapPolicy directives when creating a map and writing map items.
     */
    class MapPolicy {
        /**
         * Creates a MapPolicy with optional write flags (server >= 4.3) or defaults to
         * `MapWriteMode::Update` when no flags are supplied (servers < 4.3).
         *
         * @param \Aerospike\MapOrderType $order
         * @param array|null $flags
         * @param bool|null $persist_index
         */
        public function __construct(\Aerospike\MapOrderType $order, ?array $flags = null, ?bool $persist_index = null) {}
    }

    /**
     * MapReturnType defines the map return type.
     * Type of data to return when selecting or removing items from the map.
     */
    class MapReturnType {
        public function __construct() {}

        /**
         * COUNT will return count of items selected.
         *
         * @return \Aerospike\MapReturnType
         */
        public static function count(): \Aerospike\MapReturnType {}

        /**
         * EXISTS returns true if count > 0.
         *
         * @return \Aerospike\MapReturnType
         */
        public static function exists(): \Aerospike\MapReturnType {}

        /**
         * INDEX will return key index order.
         *
         * 0 = first key
         * N = Nth key
         * -1 = last key
         *
         * @return \Aerospike\MapReturnType
         */
        public static function index(): \Aerospike\MapReturnType {}

        /**
         * INVERTED will invert meaning of map command and return values. Combinator on a base
         * return type, mirroring ListReturnType. For example:
         * MapOp::removeByKeyRange($policy, $bin, $begin, $end, MapReturnType::key()->inverted())
         * With the INVERTED flag enabled, the keys outside of the specified key range will be
         * removed and returned.
         *
         * @return \Aerospike\MapReturnType
         */
        public function inverted(): \Aerospike\MapReturnType {}

        /**
         * KEY will return key for single key read and key list for range read.
         *
         * @return \Aerospike\MapReturnType
         */
        public static function key(): \Aerospike\MapReturnType {}

        /**
         * KEY_VALUE will return key/value items. The possible return types are:
         *
         * Value::HashMap : Returned for unordered maps
         * Value::KeyValueList : Returned for range results where range order needs to be preserved.
         *
         * @return \Aerospike\MapReturnType
         */
        public static function keyValue(): \Aerospike\MapReturnType {}

        /**
         * NONE will not return a result.
         *
         * @return \Aerospike\MapReturnType
         */
        public static function none(): \Aerospike\MapReturnType {}

        /**
         * ORDERED_MAP returns an ordered map.
         *
         * @return \Aerospike\MapReturnType
         */
        public static function orderedMap(): \Aerospike\MapReturnType {}

        /**
         * RANK will return value order.
         *
         * 0 = smallest value
         * N = Nth smallest value
         * -1 = largest value
         *
         * @return \Aerospike\MapReturnType
         */
        public static function rank(): \Aerospike\MapReturnType {}

        /**
         * REVERSE_INDEX will return reverse key order.
         *
         * 0 = last key
         * -1 = first key
         *
         * @return \Aerospike\MapReturnType
         */
        public static function reverseIndex(): \Aerospike\MapReturnType {}

        /**
         * REVERSE_RANK will return reverse value order.
         *
         * 0 = largest value
         * N = Nth largest value
         * -1 = smallest value
         *
         * @return \Aerospike\MapReturnType
         */
        public static function reverseRank(): \Aerospike\MapReturnType {}

        /**
         * UNORDERED_MAP returns an unordered map.
         *
         * @return \Aerospike\MapReturnType
         */
        public static function unorderedMap(): \Aerospike\MapReturnType {}

        /**
         * VALUE will return value for single key read and value list for range read.
         *
         * @return \Aerospike\MapReturnType
         */
        public static function value(): \Aerospike\MapReturnType {}
    }

    /**
     * Map write bit flags.
     * Requires server versions >= 4.3.
     *
     * NOTE: aero::MapWriteFlags is a module of u8 constants, not an enum.
     * This wrapper holds the raw u8 flag value so callers can OR flags together.
     */
    class MapWriteFlags {
        public function __construct() {}

        /**
         * If the key already exists, the item will be denied.
         * If the key does not exist, a new item will be created.
         *
         * @return \Aerospike\MapWriteFlags
         */
        public static function createOnly(): \Aerospike\MapWriteFlags {}

        /**
         * Default. Allow create or update.
         *
         * @return \Aerospike\MapWriteFlags
         */
        public static function default(): \Aerospike\MapWriteFlags {}

        /**
         * Do not raise error if a map item is denied due to write flag constraints.
         *
         * @return \Aerospike\MapWriteFlags
         */
        public static function noFail(): \Aerospike\MapWriteFlags {}

        /**
         * Allow other valid map items to be committed if a map item is denied due to
         * write flag constraints.
         *
         * @return \Aerospike\MapWriteFlags
         */
        public static function partial(): \Aerospike\MapWriteFlags {}

        /**
         * If the key already exists, the item will be overwritten.
         * If the key does not exist, the item will be denied.
         *
         * @return \Aerospike\MapWriteFlags
         */
        public static function updateOnly(): \Aerospike\MapWriteFlags {}
    }

    /**
     * MapWriteMode should only be used for server versions < 4.3.
     * MapWriteFlags are recommended for server versions >= 4.3.
     */
    class MapWriteMode {
        public function __construct() {}

        /**
         * If the key already exists, the write will fail.
         * If the key does not exist, a new item will be created.
         *
         * @return \Aerospike\MapWriteMode
         */
        public static function createOnly(): \Aerospike\MapWriteMode {}

        /**
         * If the key already exists, the item will be overwritten.
         * If the key does not exist, a new item will be created.
         *
         * @return \Aerospike\MapWriteMode
         */
        public static function update(): \Aerospike\MapWriteMode {}

        /**
         * If the key already exists, the item will be overwritten.
         * If the key does not exist, the write will fail.
         *
         * @return \Aerospike\MapWriteMode
         */
        public static function updateOnly(): \Aerospike\MapWriteMode {}
    }

    /**
     * OperationType determines operation type
     */
    class Operation {
        public function __construct() {}

        /**
         * integer add database operation.
         *
         * @param \Aerospike\Bin $bin
         * @return \Aerospike\Operation
         */
        public static function add(\Aerospike\Bin $bin): \Aerospike\Operation {}

        /**
         * string append database operation.
         *
         * @param \Aerospike\Bin $bin
         * @return \Aerospike\Operation
         */
        public static function append(\Aerospike\Bin $bin): \Aerospike\Operation {}

        /**
         * delete record database operation.
         *
         * @return \Aerospike\Operation
         */
        public static function delete(): \Aerospike\Operation {}

        /**
         * read bin database operation. When `bin_name` is `None`, reads all bins.
         *
         * @param string|null $bin_name
         * @return \Aerospike\Operation
         */
        public static function get(?string $bin_name = null): \Aerospike\Operation {}

        /**
         * read record header database operation.
         *
         * @return \Aerospike\Operation
         */
        public static function getHeader(): \Aerospike\Operation {}

        /**
         * string prepend database operation.
         *
         * @param \Aerospike\Bin $bin
         * @return \Aerospike\Operation
         */
        public static function prepend(\Aerospike\Bin $bin): \Aerospike\Operation {}

        /**
         * set database operation.
         *
         * @param \Aerospike\Bin $bin
         * @return \Aerospike\Operation
         */
        public static function put(\Aerospike\Bin $bin): \Aerospike\Operation {}

        /**
         * touch record database operation.
         *
         * @return \Aerospike\Operation
         */
        public static function touch(): \Aerospike\Operation {}
    }

    /**
     * Server particle types. Unsupported types are commented out.
     */
    class ParticleType {
        public function __construct() {}

        /**
         * @return \Aerospike\ParticleType
         */
        public static function blob(): \Aerospike\ParticleType {}

        /**
         * @return \Aerospike\ParticleType
         */
        public static function bool(): \Aerospike\ParticleType {}

        /**
         * @return \Aerospike\ParticleType
         */
        public static function digest(): \Aerospike\ParticleType {}

        /**
         * @return \Aerospike\ParticleType
         */
        public static function float(): \Aerospike\ParticleType {}

        /**
         * @return \Aerospike\ParticleType
         */
        public static function geoJson(): \Aerospike\ParticleType {}

        /**
         * @return \Aerospike\ParticleType
         */
        public static function hll(): \Aerospike\ParticleType {}

        /**
         * @return \Aerospike\ParticleType
         */
        public static function integer(): \Aerospike\ParticleType {}

        /**
         * @return \Aerospike\ParticleType
         */
        public static function list(): \Aerospike\ParticleType {}

        /**
         * @return \Aerospike\ParticleType
         */
        public static function map(): \Aerospike\ParticleType {}

        /**
         * @return \Aerospike\ParticleType
         */
        public static function null(): \Aerospike\ParticleType {}

        /**
         * @return \Aerospike\ParticleType
         */
        public static function string(): \Aerospike\ParticleType {}
    }

    /**
     * Partition cursor for scan/query operations. Used to resume reads across calls.
     *
     * v2 BREAKING:
     * - `get_partition_status()` and `init_partition_status()` removed. The aerospike v2 client
     *   manages `partitions` internally during `client.query(...)`. To resume a query, reuse the
     *   same `PartitionFilter` instance; the cursor state is preserved by the underlying
     *   `aero::PartitionFilter` (via `AtomicBool` flags and internally-owned partition vec).
     */
    class PartitionFilter {
        public function __construct() {}

        /**
         * Creates a partition filter that reads all the partitions.
         *
         * @return \Aerospike\PartitionFilter
         */
        public static function all(): \Aerospike\PartitionFilter {}

        /**
         * Creates a partition filter by a single partition id (0..4095).
         *
         * @param int $id
         * @return \Aerospike\PartitionFilter
         */
        public static function partition(int $id): \Aerospike\PartitionFilter {}

        /**
         * Creates a partition filter by a partition range. `begin` is in 0..4095; `count` is in 1..=4096.
         *
         * @param int $begin
         * @param int $count
         * @return \Aerospike\PartitionFilter
         */
        public static function range(int $begin, int $count): \Aerospike\PartitionFilter {}
    }

    /**
     * Status of a single partition during a scan/query. Used as a cursor.
     *
     * v2 BREAKING: `bval` is now `Option<u64>` on the underlying aero type (was `Option<i64>` in
     * proto). The PHP getter is widened to `Option<i64>` via lossy cast for backward compat —
     * callers should expect non-negative values.
     */
    class PartitionStatus {
        /**
         * @param int $id
         */
        public function __construct(int $id) {}

        /**
         * Record's bval.
         *
         * @return int|null
         */
        public function getBval(): ?int {}

        /**
         * Digest of the last key seen on the server for this partition (empty if none).
         *
         * @return array
         */
        public function getDigest(): array {}

        /**
         * Partition id (0..4095).
         *
         * @return int
         */
        public function getPartitionId(): int {}

        /**
         * Whether the partition requires a retry.
         *
         * @return bool
         */
        public function getRetry(): bool {}
    }

    /**
     * Privilege determines user access granularity.
     * Wraps `aerospike::Privilege` (aerospike-core v2).
     *
     * v2 BREAKING: proto had a string `name` field for the privilege code; aerospike-core
     * uses a typed `PrivilegeCode` enum in the `code` field.  The `get_name()` getter now
     * derives the string representation from `PrivilegeCode` via `String::from(&code)` so
     * the PHP API surface is preserved.
     *
     * `namespace` and `set_name` are `Option<String>` in aerospike-core.  The getters
     * return an empty string when `None` for backward compatibility.
     */
    class Privilege {
        public function __construct() {}

        /**
         * DataAdmin allows to manage indicies and user defined functions.
         *
         * @return string
         */
        public static function dataAdmin(): string {}

        /**
         * Returns the string name of the privilege code (e.g. "read", "read-write").
         * Derived from `PrivilegeCode` — replaces proto's string `name` field.
         *
         * @return string
         */
        public function getName(): string {}

        /**
         * Returns the namespace scope, or empty string if unscoped.
         *
         * @return string
         */
        public function getNamespace(): string {}

        /**
         * Returns the set name scope, or empty string if unscoped.
         *
         * @return string
         */
        public function getSetname(): string {}

        /**
         * Read allows read transactions with the database.
         *
         * @return string
         */
        public static function read(): string {}

        /**
         * ReadWrite allows read and write transactions with the database.
         *
         * @return string
         */
        public static function readWrite(): string {}

        /**
         * ReadWriteUDF allows read, write and UDF transactions with the database.
         *
         * @return string
         */
        public static function readWriteUdf(): string {}

        /**
         * SIndexAdmin allows to manage indicies.
         *
         * @return string
         */
        public static function sindexAdmin(): string {}

        /**
         * SysAdmin allows to manage indexes, user defined functions and server configuration.
         *
         * @return string
         */
        public static function sysAdmin(): string {}

        /**
         * Truncate allow issuing truncate commands.
         *
         * @return string
         */
        public static function truncate(): string {}

        /**
         * UDFAdmin allows to manage user defined functions.
         *
         * @return string
         */
        public static function udfAdmin(): string {}

        /**
         * UserAdmin allows to manages users and their roles.
         *
         * @return string
         */
        public static function userAdmin(): string {}

        /**
         * Write allows write transactions with the database.
         *
         * @return string
         */
        public static function write(): string {}
    }

    /**
     * QueryDuration represents the expected duration for a query operation in the Aerospike database.
     */
    class QueryDuration {
        public function __construct() {}

        /**
         * LONG specifies that the query is expected to return more than 100 records per node.
         *
         * @return \Aerospike\QueryDuration
         */
        public static function long(): \Aerospike\QueryDuration {}

        /**
         * LongRelaxAP will treat query as a LONG query, but relax read consistency for AP namespaces.
         *
         * @return \Aerospike\QueryDuration
         */
        public static function longRelaxAp(): \Aerospike\QueryDuration {}

        /**
         * Short specifies that the query is expected to return less than 100 records per node.
         *
         * @return \Aerospike\QueryDuration
         */
        public static function short(): \Aerospike\QueryDuration {}
    }

    /**
     * QueryPolicy encapsulates parameters for policy attributes used in query operations.
     *
     * v2 BREAKING: legacy fields `sleep_multiplier`, `send_key`, `use_compression`,
     * `exit_fast_on_exhausted_connection_pool`, `read_mode_sc` have been removed.
     */
    class QueryPolicy {
        public function __construct() {}

        /**
         * Expected query duration (Long, Short, LongRelaxAP). Server v6.0+.
         *
         * @return \Aerospike\QueryDuration
         */
        public function getExpectedDuration(): \Aerospike\QueryDuration {}

        /**
         * @return \Aerospike\Expression|null
         */
        public function getFilterExpression(): ?\Aerospike\Expression {}

        /**
         * Maximum number of concurrent requests to server nodes.
         *
         * @return int
         */
        public function getMaxConcurrentNodes(): int {}

        /**
         * @return int
         */
        public function getMaxRetries(): int {}

        /**
         * @return \Aerospike\ReadModeAP
         */
        public function getReadModeAp(): \Aerospike\ReadModeAP {}

        /**
         * Number of records to place in queue before blocking.
         *
         * @return int
         */
        public function getRecordQueueSize(): int {}

        /**
         * @return int
         */
        public function getSocketTimeout(): int {}

        /**
         * @return int
         */
        public function getTotalTimeout(): int {}

        /**
         * @param mixed $expected_duration
         * @return void
         */
        public function setExpectedDuration(mixed $expected_duration): void {}

        /**
         * @param mixed $filter_expression
         * @return void
         */
        public function setFilterExpression(mixed $filter_expression = null): void {}

        /**
         * @param int $max_concurrent_nodes
         * @return void
         */
        public function setMaxConcurrentNodes(int $max_concurrent_nodes): void {}

        /**
         * @param int $max_retries
         * @return void
         */
        public function setMaxRetries(int $max_retries): void {}

        /**
         * @param mixed $read_mode_ap
         * @return void
         */
        public function setReadModeAp(mixed $read_mode_ap): void {}

        /**
         * @param int $record_queue_size
         * @return void
         */
        public function setRecordQueueSize(int $record_queue_size): void {}

        /**
         * @param int $timeout_millis
         * @return void
         */
        public function setSocketTimeout(int $timeout_millis): void {}

        /**
         * @param int $timeout_millis
         * @return void
         */
        public function setTotalTimeout(int $timeout_millis): void {}
    }

    /**
     * ReadModeAP is the read policy in AP (availability) mode namespaces.
     * It indicates how duplicates should be consulted in a read operation.
     * Only makes a difference during migrations and only applicable in AP mode.
     *
     * Backed by `aerospike::ConsistencyLevel` since the Rust client merges AP read mode
     * into the unified consistency-level concept.
     */
    class ReadModeAP {
        public function __construct() {}

        /**
         * ReadModeAPAll indicates that all duplicates should be consulted in
         * the read operation.
         *
         * @return \Aerospike\ReadModeAP
         */
        public static function all(): \Aerospike\ReadModeAP {}

        /**
         * ReadModeAPOne indicates that a single node should be involved in the read operation.
         *
         * @return \Aerospike\ReadModeAP
         */
        public static function one(): \Aerospike\ReadModeAP {}
    }

    /**
     * ReadModeSC is the read policy in SC (strong consistency) mode namespaces.
     * Determines SC read consistency options.
     *
     * NOTE: aerospike-client-rust v2 does not yet model SC read modes separately; the
     * chosen value is stored on the policy for forward compatibility but currently has
     * no runtime effect.
     */
    class ReadModeSC {
        public function __construct() {}

        /**
         * ReadModeSCAllowReplica indicates that the server may read from master or any full (non-migrating) replica.
         *
         * @return \Aerospike\ReadModeSC
         */
        public static function allowReplica(): \Aerospike\ReadModeSC {}

        /**
         * ReadModeSCAllowUnavailable indicates that the server may read from master or any full (non-migrating) replica or from unavailable
         * partitions.
         *
         * @return \Aerospike\ReadModeSC
         */
        public static function allowUnavailable(): \Aerospike\ReadModeSC {}

        /**
         * ReadModeSCLinearize ensures ALL clients will only see an increasing sequence of record versions.
         *
         * @return \Aerospike\ReadModeSC
         */
        public static function linearize(): \Aerospike\ReadModeSC {}

        /**
         * ReadModeSCSession ensures this client will only see an increasing sequence of record versions.
         *
         * @return \Aerospike\ReadModeSC
         */
        public static function session(): \Aerospike\ReadModeSC {}
    }

    /**
     * `ReadPolicy` encapsulates parameters for transaction policy attributes
     * used in all database operation calls.
     *
     * v2 BREAKING: `sleep_multiplier`, `use_compression`, `exit_fast_on_exhausted_connection_pool`,
     * `send_key` and `read_mode_sc` are not supported by the native aerospike-client-rust crate
     * and have been removed. `read_mode_ap` maps to the new `consistency_level` concept.
     */
    class ReadPolicy {
        public function __construct() {}

        /**
         * FilterExpression is the optional Filter Expression. Supported on Server v5.2+
         *
         * @return \Aerospike\Expression|null
         */
        public function getFilterExpression(): ?\Aerospike\Expression {}

        /**
         * MaxRetries determines the maximum number of retries before aborting the current transaction.
         *
         * @return int
         */
        public function getMaxRetries(): int {}

        /**
         * ReadModeAP indicates read policy for AP (availability) namespaces.
         * Maps to the underlying consistency_level (ConsistencyOne/ConsistencyAll).
         *
         * @return \Aerospike\ReadModeAP
         */
        public function getReadModeAp(): \Aerospike\ReadModeAP {}

        /**
         * ReadTouchTTLPercent determines how record TTL is affected on reads.
         * 0 = use server default, -1 = don't reset, 1..=100 = percentage. Supported in server v8+.
         *
         * @return int
         */
        public function getReadTouchTtlPercent(): int {}

        /**
         * SocketTimeout determines network timeout for each attempt in milliseconds.
         *
         * @return int
         */
        public function getSocketTimeout(): int {}

        /**
         * TotalTimeout specifies total transaction timeout in milliseconds.
         *
         * @return int
         */
        public function getTotalTimeout(): int {}

        /**
         * @param mixed $filter_expression
         * @return void
         */
        public function setFilterExpression(mixed $filter_expression = null): void {}

        /**
         * @param int $max_retries
         * @return void
         */
        public function setMaxRetries(int $max_retries): void {}

        /**
         * @param mixed $read_mode_ap
         * @return void
         */
        public function setReadModeAp(mixed $read_mode_ap): void {}

        /**
         * 0 = server default, -1 = don't reset, 1..=100 = percentage. Any other value
         * throws an AerospikeException.
         *
         * @param int $percent
         * @return void
         * @throws \Aerospike\AerospikeException
         */
        public function setReadTouchTtlPercent(int $percent): void {}

        /**
         * @param int $timeout_millis
         * @return void
         */
        public function setSocketTimeout(int $timeout_millis): void {}

        /**
         * @param int $timeout_millis
         * @return void
         */
        public function setTotalTimeout(int $timeout_millis): void {}
    }

    /**
     *
     *  Record
     *
     * Container object for a database record.
     */
    class Record {
        public function __construct() {}

        /**
         * v1 compatibility shim: forwards `$record->bins`, `->generation`, `->ttl`,
         * `->key`, `->expiration` to the corresponding getters.
         *
         * @param string $name
         * @return mixed
         */
        public function __get(string $name): mixed {}

        /**
         * Bins is the map of requested name/value bins.
         *
         * @param string $name
         * @return mixed
         */
        public function bin(string $name): mixed {}

        /**
         * Bins is the map of requested name/value bins.
         *
         * @return mixed
         */
        public function getBins(): mixed {}

        /**
         * Expiration indicates when a record will expire (Time-To-Live).
         * Returns the remaining TTL in seconds, or `Never` if the record never expires.
         *
         * @return \Aerospike\Expiration
         */
        public function getExpiration(): \Aerospike\Expiration {}

        /**
         * Generation shows record modification count.
         *
         * @return int|null
         */
        public function getGeneration(): ?int {}

        /**
         * Key is the record's key.
         * Might be empty, or may only consist of digest value.
         *
         * @return \Aerospike\Key|null
         */
        public function getKey(): ?\Aerospike\Key {}

        /**
         * Remaining time-to-live in seconds (positive integer), or null if the record
         * never expires, or if the TTL is unknown. v1-compatible: matches the semantics
         * of `aerospike-client-php` 1.x, which also returned the remaining TTL rather
         * than an absolute timestamp.
         *
         * Equivalent to `getRemainingTtl()`. For the full expiration state (including
         * "never expires" and "use namespace default"), use `getExpiration()`.
         *
         * @return int|null
         */
        public function getTtl(): ?int {}

        /**
         * Remaining TTL in seconds (positive integer), or null if the record never expires.
         * Equivalent to `$this->getExpiration()->getTtl()`, and to `getTtl()`.
         *
         * @return int|null
         */
        public function getRemainingTtl(): ?int {}
    }

    /**
     * RecordExistsAction determines how to handle writes when
     * the record already exists.
     */
    class RecordExistsAction {
        public function __construct() {}

        /**
         * CreateOnly means: Create only. Fail if record exists.
         *
         * @return \Aerospike\RecordExistsAction
         */
        public static function createOnly(): \Aerospike\RecordExistsAction {}

        /**
         * Replace means: Create or replace record.
         * Delete existing bins not referenced by write command bins.
         *
         * @return \Aerospike\RecordExistsAction
         */
        public static function replace(): \Aerospike\RecordExistsAction {}

        /**
         * ReplaceOnly means: Replace record only. Fail if record does not exist.
         *
         * @return \Aerospike\RecordExistsAction
         */
        public static function replaceOnly(): \Aerospike\RecordExistsAction {}

        /**
         * Update means: Create or update record.
         * Merge write command bins with existing bins.
         *
         * @return \Aerospike\RecordExistsAction
         */
        public static function update(): \Aerospike\RecordExistsAction {}

        /**
         * UpdateOnly means: Update record only. Fail if record does not exist.
         *
         * @return \Aerospike\RecordExistsAction
         */
        public static function updateOnly(): \Aerospike\RecordExistsAction {}
    }

    /**
     * Virtual collection of records retrieved through queries and scans.
     *
     * Wraps `aero::Recordset`, which manages a bounded queue between the client's background
     * node-reader tasks and the user thread. `next()` blocks until the next record is available
     * or the recordset is closed by the client (via `close()` or completion).
     */
    class Recordset {
        public function __construct() {}

        /**
         * Close the recordset. Background tasks finish at their next safe point.
         *
         * To stop a paginated scan/query early AND keep the pagination cursor, drain the
         * recordset after closing: keep calling next() until it returns null. Draining
         * consumes the records already delivered and then writes the cursor back into the
         * originating PartitionFilter, so the next scan resumes exactly after the consumed
         * records. Abandoning the recordset right after close() leaves the cursor at the
         * previous page boundary (already-seen records are returned again on resume).
         *
         * @return void
         */
        public function close(): void {}

        /**
         * Returns true if the operation hasn't been finished or cancelled.
         *
         * @return bool
         */
        public function getActive(): bool {}

        /**
         * Returns the next record from the queue, blocking until a record arrives or the
         * recordset closes. Returns `null` when the stream is exhausted.
         *
         * CAUTION: with total_timeout = 0 on the scan/query policy there is no upper
         * bound on how long this blocks. Set a non-zero total_timeout (or the
         * aerospike.read_timeout INI) for streaming reads in production.
         *
         * @return \Aerospike\Record|null
         * @throws \Aerospike\AerospikeException
         */
        public function next(): ?\Aerospike\Record {}
    }

    /**
     * ResultCode signifies the database operation error codes.
     * The positive numbers align with the server side file kvs.h.
     */
    class ResultCode {
        /**
         * AEROSPIKE_ERR_LUA_FILE_NOT_FOUND defines LUA file does not exist.
         */
        const AEROSPIKE_ERR_LUA_FILE_NOT_FOUND = 1302;

        /**
         * AEROSPIKE_ERR_UDF_NOT_FOUND defines UDF does not exist.
         */
        const AEROSPIKE_ERR_UDF_NOT_FOUND = 1301;

        /**
         * ALWAYS_FORBIDDEN defines operation not allowed in current configuration.
         */
        const ALWAYS_FORBIDDEN = 10;

        /**
         * BATCH_DISABLED defines batch functionality has been disabled.
         */
        const BATCH_DISABLED = 150;

        /**
         * BATCH_FAILED means one or more keys failed in a batch.
         */
        const BATCH_FAILED = -20;

        /**
         * BATCH_MAX_REQUESTS_EXCEEDED defines batch max requests have been exceeded.
         */
        const BATCH_MAX_REQUESTS_EXCEEDED = 151;

        /**
         * BATCH_QUEUES_FULL defines all batch queues are full.
         */
        const BATCH_QUEUES_FULL = 152;

        /**
         * BIN_EXISTS_ERROR defines bin already exists on a create-only operation.
         */
        const BIN_EXISTS_ERROR = 6;

        /**
         * BIN_NAME_TOO_LONG defines bin name length greater than 14 characters, or maximum number of unique bin names are exceeded;
         */
        const BIN_NAME_TOO_LONG = 21;

        /**
         * BIN_NOT_FOUND defines bin not found on update-only operation.
         */
        const BIN_NOT_FOUND = 17;

        /**
         * BIN_TYPE_ERROR defines operation is not supported with configured bin type (single-bin or multi-bin);
         */
        const BIN_TYPE_ERROR = 12;

        /**
         * CLUSTER_KEY_MISMATCH defines expected cluster ID was not received.
         */
        const CLUSTER_KEY_MISMATCH = 7;

        /**
         * CLUSTER_NAME_MISMATCH_ERROR defines cluster Name does not match the ClientPolicy.ClusterName value.
         */
        const CLUSTER_NAME_MISMATCH_ERROR = -10;

        /**
         * COMMAND_REJECTED defines info Command was rejected by the server.
         */
        const COMMAND_REJECTED = -6;

        /**
         * COMMON_ERROR defines a common, none-aerospike error. Checked the wrapped error for detail.
         */
        const COMMON_ERROR = -17;

        /**
         * DEVICE_OVERLOAD defines device not keeping up with writes.
         */
        const DEVICE_OVERLOAD = 18;

        /**
         * ENTERPRISE_ONLY defines attempt to use an Enterprise feature on a Community server or a server without the applicable feature key;
         */
        const ENTERPRISE_ONLY = 25;

        /**
         * EXPIRED_PASSWORD defines security credential is invalid.
         */
        const EXPIRED_PASSWORD = 63;

        /**
         * EXPIRED_SESSION defines login session expired.
         */
        const EXPIRED_SESSION = 66;

        /**
         * FAIL_ELEMENT_EXISTS defines element Already Exists in CDT
         */
        const FAIL_ELEMENT_EXISTS = 24;

        /**
         * FAIL_ELEMENT_NOT_FOUND defines element Not Found in CDT
         */
        const FAIL_ELEMENT_NOT_FOUND = 23;

        /**
         * FAIL_FORBIDDEN defines operation not allowed at this time.
         */
        const FAIL_FORBIDDEN = 22;

        /**
         * FILTERED_OUT defines the transaction was not performed because the filter was false.
         */
        const FILTERED_OUT = 27;

        /**
         * FORBIDDEN_PASSWORD defines forbidden password (e.g. recently used)
         */
        const FORBIDDEN_PASSWORD = 64;

        /**
         * GENERATION_ERROR defines on modifying a record with unexpected generation.
         */
        const GENERATION_ERROR = 3;

        /**
         * GEO_INVALID_GEOJSON defines invalid GeoJSON on insert/update
         */
        const GEO_INVALID_GEOJSON = 160;

        /**
         * ILLEGAL_STATE defines security protocol not followed.
         */
        const ILLEGAL_STATE = 56;

        /**
         * INDEX_FOUND defines secondary index already exists.
         */
        const INDEX_FOUND = 200;

        /**
         * INDEX_GENERIC defines generic secondary index error.
         */
        const INDEX_GENERIC = 204;

        /**
         * INDEX_MAXCOUNT defines maximum number of indexes exceeded.
         */
        const INDEX_MAX_COUNT = 206;

        /**
         * INDEX_NAME_MAXLEN defines index name maximum length exceeded.
         */
        const INDEX_NAME_MAX_LEN = 205;

        /**
         * INDEX_NOTFOUND defines requested secondary index does not exist.
         */
        const INDEX_NOT_FOUND = 201;

        /**
         * INDEX_NOTREADABLE defines secondary index not available.
         */
        const INDEX_NOT_READABLE = 203;

        /**
         * INDEX_OOM defines secondary index memory space exceeded.
         */
        const INDEX_OOM = 202;

        /**
         * INVALID_CLUSTER_PARTITION_MAP defines cluster has an invalid partition map, usually due to bad configuration.
         */
        const INVALID_CLUSTER_PARTITION_MAP = -12;

        /**
         * INVALID_COMMAND defines administration command is invalid.
         */
        const INVALID_COMMAND = 54;

        /**
         * INVALID_CREDENTIAL defines security credential is invalid.
         */
        const INVALID_CREDENTIAL = 65;

        /**
         * INVALID_FIELD defines administration field is invalid.
         */
        const INVALID_FIELD = 55;

        /**
         * INVALID_NAMESPACE defines invalid namespace.
         */
        const INVALID_NAMESPACE = 20;

        /**
         * INVALID_NODE_ERROR defines chosen node is not currently active.
         */
        const INVALID_NODE_ERROR = -3;

        /**
         * INVALID_PASSWORD defines password is invalid.
         */
        const INVALID_PASSWORD = 62;

        /**
         * INVALID_PRIVILEGE defines privilege is invalid.
         */
        const INVALID_PRIVILEGE = 72;

        /**
         * INVALID_QUOTA defines invalid quota value.
         */
        const INVALID_QUOTA = 75;

        /**
         * INVALID_ROLE defines role name is invalid.
         */
        const INVALID_ROLE = 70;

        /**
         * INVALID_USER defines user name is invalid.
         */
        const INVALID_USER = 60;

        /**
         * INVALID_WHITELIST defines invalid IP address whiltelist
         */
        const INVALID_WHITELIST = 73;

        /**
         * KEY_BUSY defines too many concurrent operations on the same record.
         */
        const KEY_BUSY = 14;

        /**
         * KEY_EXISTS_ERROR defines on create-only (write unique) operations on a record that already exists.
         */
        const KEY_EXISTS_ERROR = 5;

        /**
         * KEY_MISMATCH defines key type mismatch.
         */
        const KEY_MISMATCH = 19;

        /**
         * KEY_NOT_FOUND_ERROR defines on retrieving, touching or replacing a record that doesn't exist.
         */
        const KEY_NOT_FOUND_ERROR = 2;

        /**
         * LOST_CONFLICT defines write command loses conflict to XDR.
         */
        const LOST_CONFLICT = 28;

        /**
         * MAX_ERROR_RATE defines max errors limit reached.
         */
        const MAX_ERROR_RATE = -15;

        /**
         * MAX_RETRIES_EXCEEDED defines max retries limit reached.
         */
        const MAX_RETRIES_EXCEEDED = -16;

        /**
         * NETWORK_ERROR defines a network error. Checked the wrapped error for detail.
         */
        const NETWORK_ERROR = -18;

        /**
         * NOT_AUTHENTICATED defines user must be authentication before performing database operations.
         */
        const NOT_AUTHENTICATED = 80;

        /**
         * NOT_WHITELISTED defines command not allowed because sender IP address not whitelisted.
         */
        const NOT_WHITELISTED = 82;

        /**
         * NO_AVAILABLE_CONNECTIONS_TO_NODE defines there were no connections available to the node in the pool, and the pool was limited
         */
        const NO_AVAILABLE_CONNECTIONS_TO_NODE = -8;

        /**
         * NO_RESPONSE means no response was received from the server.
         */
        const NO_RESPONSE = -19;

        /**
         * OK defines operation was successful.
         */
        const OK = 0;

        /**
         * OP_NOT_APPLICABLE defines the operation cannot be applied to the current bin value on the server.
         */
        const OP_NOT_APPLICABLE = 26;

        /**
         * PARAMETER_ERROR defines bad parameter(s) were passed in database operation call.
         */
        const PARAMETER_ERROR = 4;

        /**
         * PARSE_ERROR defines client parse error.
         */
        const PARSE_ERROR = -2;

        /**
         * PARTITION_UNAVAILABLE defines partition is unavailable.
         */
        const PARTITION_UNAVAILABLE = 11;

        /**
         * QUERY_ABORTED defines secondary index query aborted.
         */
        const QUERY_ABORTED = 210;

        /**
         * QUERY_DUPLICATE defines duplicate TaskId sent for the statement
         */
        const QUERY_DUPLICATE = 215;

        /**
         * QUERY_END defines there are no more records left for query.
         */
        const QUERY_END = 50;

        /**
         * QUERY_GENERIC defines generic query error.
         */
        const QUERY_GENERIC = 213;

        /**
         * QUERY_NETIO_ERR defines query NetIO error on server
         */
        const QUERY_NET_IO_ERR = 214;

        /**
         * QUERY_QUEUEFULL defines secondary index queue full.
         */
        const QUERY_QUEUE_FULL = 211;

        /**
         * QUERY_TERMINATED defines query was terminated by user.
         */
        const QUERY_TERMINATED = -5;

        /**
         * QUERY_TIMEOUT defines secondary index query timed out on server.
         */
        const QUERY_TIMEOUT = 212;

        /**
         * QUOTAS_NOT_ENABLED defines Quotas not enabled on server.
         */
        const QUOTAS_NOT_ENABLED = 74;

        /**
         * QUOTA_EXCEEDED defines Quota exceeded.
         */
        const QUOTA_EXCEEDED = 83;

        /**
         * RACK_NOT_DEFINED defines requested Rack for node/namespace was not defined in the cluster.
         */
        const RACK_NOT_DEFINED = -13;

        /**
         * RECORDSET_CLOSED defines recordset has already been closed or cancelled
         */
        const RECORDSET_CLOSED = -9;

        /**
         * RECORD_TOO_BIG defines record size exceeds limit.
         */
        const RECORD_TOO_BIG = 13;

        /**
         * ROLE_ALREADY_EXISTS defines role already exists.
         */
        const ROLE_ALREADY_EXISTS = 71;

        /**
         * ROLE_VIOLATION defines user does not posses the required role to perform the database operation.
         */
        const ROLE_VIOLATION = 81;

        /**
         * SCAN_ABORT defines scan aborted by server.
         */
        const SCAN_ABORT = 15;

        /**
         * SCAN_TERMINATED defines scan was terminated by user.
         */
        const SCAN_TERMINATED = -4;

        /**
         * SECURITY_NOT_ENABLED defines administration command is invalid.
         */
        const SECURITY_NOT_ENABLED = 52;

        /**
         * SECURITY_NOT_SUPPORTED defines security type not supported by connected server.
         */
        const SECURITY_NOT_SUPPORTED = 51;

        /**
         * SECURITY_SCHEME_NOT_SUPPORTED defines administration field is invalid.
         */
        const SECURITY_SCHEME_NOT_SUPPORTED = 53;

        /**
         * SERIALIZE_ERROR defines client serialization error.
         */
        const SERIALIZE_ERROR = -1;

        /**
         * SERVER_ERROR defines unknown server failure.
         */
        const SERVER_ERROR = 1;

        /**
         * SERVER_MEM_ERROR defines server has run out of memory.
         */
        const SERVER_MEM_ERROR = 8;

        /**
         * SERVER_NOT_AVAILABLE defines server is not accepting requests.
         */
        const SERVER_NOT_AVAILABLE = -11;

        /**
         * TIMEOUT defines client or server has timed out.
         */
        const TIMEOUT = 9;

        /**
         * TYPE_NOT_SUPPORTED defines data type is not supported by aerospike server.
         */
        const TYPE_NOT_SUPPORTED = -7;

        /**
         * UDF_BAD_RESPONSE defines a user defined function returned an error code.
         */
        const UDF_BAD_RESPONSE = 100;

        /**
         * UNSUPPORTED_FEATURE defines unsupported Server Feature (e.g. Scan + UDF)
         */
        const UNSUPPORTED_FEATURE = 16;

        /**
         * USER_ALREADY_EXISTS defines user was previously created.
         */
        const USER_ALREADY_EXISTS = 61;

        public function __construct() {}

        /**
         * @param int $code
         * @return string
         */
        public static function toString(int $code): string {}
    }

    /**
     * Role allows granular access to database entities for users.
     * Wraps `aerospike::Role` (aerospike-core v2).
     *
     * v2 NOTE: `read_quota` and `write_quota` are `u32` in aerospike-core.
     * The PHP getters return `u64` for backward compatibility (widening cast).
     * v2 BREAKING: the old `write_quota()` getter (missing `get_` prefix) is renamed to
     * `get_write_quota()` for consistency with all other getters.
     */
    class Role {
        public function __construct() {}

        /**
         * Allowlist is the list of allowable IP addresses.
         *
         * @return array
         */
        public function getAllowlist(): array {}

        /**
         * Name is role name.
         *
         * @return string
         */
        public function getName(): string {}

        /**
         * Privileges is the list of assigned privileges.
         *
         * @return array
         */
        public function getPrivileges(): array {}

        /**
         * ReadQuota is the maximum reads per second limit for the role.
         *
         * @return int
         */
        public function getReadQuota(): int {}

        /**
         * WriteQuota is the maximum writes per second limit for the role.
         * v2 BREAKING: renamed from `write_quota()` (no `get_` prefix) to `get_write_quota()`.
         *
         * @return int
         */
        public function getWriteQuota(): int {}
    }

    /**
     * `ScanPolicy` encapsulates optional parameters used in scan operations.
     *
     * v2 BREAKING: scan was unified into query in aerospike-client-rust v2.
     * `ScanPolicy` is now backed by `aerospike::QueryPolicy`. Legacy fields
     * `sleep_multiplier`, `send_key`, `use_compression`, `exit_fast_on_exhausted_connection_pool`,
     * `read_mode_sc` have been removed.
     */
    class ScanPolicy {
        public function __construct() {}

        /**
         * @return \Aerospike\Expression|null
         */
        public function getFilterExpression(): ?\Aerospike\Expression {}

        /**
         * Maximum number of concurrent requests to server nodes.
         *
         * @return int
         */
        public function getMaxConcurrentNodes(): int {}

        /**
         * Number of records to scan per node (0 = no limit).
         *
         * @return int
         */
        public function getMaxRecords(): int {}

        /**
         * @return int
         */
        public function getMaxRetries(): int {}

        /**
         * @return \Aerospike\ReadModeAP
         */
        public function getReadModeAp(): \Aerospike\ReadModeAP {}

        /**
         * Number of records to place in queue before blocking.
         *
         * @return int
         */
        public function getRecordQueueSize(): int {}

        /**
         * @return int
         */
        public function getSocketTimeout(): int {}

        /**
         * @return int
         */
        public function getTotalTimeout(): int {}

        /**
         * @param mixed $filter_expression
         * @return void
         */
        public function setFilterExpression(mixed $filter_expression = null): void {}

        /**
         * @param int $max_concurrent_nodes
         * @return void
         */
        public function setMaxConcurrentNodes(int $max_concurrent_nodes): void {}

        /**
         * @param int $max_records
         * @return void
         */
        public function setMaxRecords(int $max_records): void {}

        /**
         * @param int $max_retries
         * @return void
         */
        public function setMaxRetries(int $max_retries): void {}

        /**
         * @param mixed $read_mode_ap
         * @return void
         */
        public function setReadModeAp(mixed $read_mode_ap): void {}

        /**
         * @param int $record_queue_size
         * @return void
         */
        public function setRecordQueueSize(int $record_queue_size): void {}

        /**
         * @param int $timeout_millis
         * @return void
         */
        public function setSocketTimeout(int $timeout_millis): void {}

        /**
         * @param int $timeout_millis
         * @return void
         */
        public function setTotalTimeout(int $timeout_millis): void {}
    }

    /**
     * Statement encapsulates query statement parameters.
     *
     * v2 BREAKING:
     * - `index_name` getter/setter removed. To target a specific secondary index, use the
     *   `Filter::equal_by_index` / `Filter::range_by_index` / `Filter::contains_by_index` helpers
     *   (not yet wrapped in PHP; will be added if needed).
     * - `return_data` toggle removed. Use `bin_names = Some(vec![])` for header-only reads
     *   (maps to `aero::Bins::None`); `bin_names = None` returns all bins (`aero::Bins::All`);
     *   non-empty `bin_names` returns the specified bins (`aero::Bins::Some(names)`).
     * - `task_id` removed; the aerospike v2 client manages it internally.
     */
    class Statement {
        /**
         * @param string $namespace
         * @param string $set_name
         * @param mixed $filter
         * @param array|null $bin_names
         */
        public function __construct(string $namespace, string $set_name, mixed $filter = null, ?array $bin_names = null) {}

        /**
         * Bin names to return (optional). Empty Vec is treated as Bins::None (header-only).
         *
         * @return array
         */
        public function getBinNames(): array {}

        /**
         * Query index filter (optional). Applied to the secondary index on query.
         * Query index filters must reference a bin which has a secondary index defined.
         *
         * @return \Aerospike\Filter|null
         */
        public function getFilter(): ?\Aerospike\Filter {}

        /**
         * Query namespace.
         *
         * @return string
         */
        public function getNamespace(): string {}

        /**
         * Query set name (optional).
         *
         * @return string
         */
        public function getSetname(): string {}

        /**
         * @param array $bin_names
         * @return void
         */
        public function setBinNames(array $bin_names): void {}

        /**
         * @param mixed $filter
         * @return void
         */
        public function setFilter(mixed $filter = null): void {}

        /**
         * @param string $namespace
         * @return void
         */
        public function setNamespace(string $namespace): void {}

        /**
         * @param string $set_name
         * @return void
         */
        public function setSetname(string $set_name): void {}
    }

    /**
     * `UdfLanguage` determines how to handle record writes based on record generation.
     */
    class UdfLanguage {
        public function __construct() {}

        /**
         * lua language.
         *
         * @return \Aerospike\UdfLanguage
         */
        public static function lua(): \Aerospike\UdfLanguage {}
    }

    /**
     * Represents UDF (User-Defined Function) metadata for Aerospike.
     * Standalone struct — aerospike-core v2 does not expose a UdfMeta type directly.
     * UDF listing will be implemented later via the Info command.
     */
    class UdfMeta {
        public function __construct() {}

        /**
         * v1 compatibility shim: forwards `$udf->packageName`, `->hash`, `->language` to
         * the corresponding getters.
         *
         * @param string $name
         * @return mixed
         */
        public function __get(string $name): mixed {}

        /**
         * Getter method to retrieve the hash of the UDF.
         *
         * @return string
         */
        public function getHash(): string {}

        /**
         * Getter method to retrieve the language of the UDF.
         * Today aero::UDFLang has only `Lua`, so this always returns `UdfLanguage::Lua()`.
         *
         * @return \Aerospike\UdfLanguage
         */
        public function getLanguage(): \Aerospike\UdfLanguage {}

        /**
         * Getter method to retrieve the package name of the UDF.
         *
         * @return string
         */
        public function getPackageName(): string {}
    }

    /**
     * UserRole contains information about a user and their assigned roles.
     * Wraps `aerospike::User` (aerospike-core v2).
     *
     * v2 NOTE: `read_info` and `write_info` are `Vec<u32>` in aerospike-core (not u64).
     * The PHP getters return `Vec<u64>` for backward compatibility (widening cast).
     * `conns_in_use` is `u32` in aerospike-core; the PHP getter returns `u64` for
     * backward compatibility.
     */
    class UserRole {
        public function __construct() {}

        /**
         * ConnsInUse is the number of currently open connections for the user.
         *
         * @return int
         */
        public function getConnsInUse(): int {}

        /**
         * ReadInfo is the list of read statistics. List may be nil.
         * Current statistics by offset are:
         *
         * 0: read quota in records per second
         * 1: single record read transaction rate (TPS)
         * 2: read scan/query record per second rate (RPS)
         * 3: number of limitless read scans/queries
         *
         * Future server releases may add additional statistics.
         *
         * @return array
         */
        public function getReadInfo(): array {}

        /**
         * Roles is a list of assigned roles.
         *
         * @return array
         */
        public function getRoles(): array {}

        /**
         * User name.
         * NOTE: aerospike-core v2 stores the user name in the `user` field (not `name`).
         *
         * @return string
         */
        public function getUser(): string {}

        /**
         * WriteInfo is the list of write statistics. List may be nil.
         * Current statistics by offset are:
         *
         * 0: write quota in records per second
         * 1: single record write transaction rate (TPS)
         * 2: write scan/query record per second rate (RPS)
         * 3: number of limitless write scans/queries
         *
         * Future server releases may add additional statistics.
         *
         * @return array
         */
        public function getWriteInfo(): array {}
    }

    class Value {
        public function __construct() {}

        /**
         * @param mixed $zval
         * @return mixed
         */
        public static function blob(mixed $zval): mixed {}

        /**
         * @param bool $val
         * @return mixed
         */
        public static function bool(bool $val): mixed {}

        /**
         * @param float $val
         * @return mixed
         */
        public static function float(float $val): mixed {}

        /**
         * @param string $val
         * @return mixed
         */
        public static function geoJson(string $val): mixed {}

        /**
         * @param array $val
         * @return mixed
         */
        public static function hll(array $val): mixed {}

        /**
         * @return mixed
         */
        public static function infinity(): mixed {}

        /**
         * @param int $val
         * @return mixed
         */
        public static function int(int $val): mixed {}

        /**
         * @param array $val
         * @return mixed
         */
        public static function json(array $val): mixed {}

        /**
         * @param array $val
         * @return mixed
         */
        public static function list(array $val): mixed {}

        /**
         * @param mixed $val
         * @return mixed
         */
        public static function map(mixed $val): mixed {}

        /**
         * @return mixed
         */
        public static function nil(): mixed {}

        /**
         * @param string $val
         * @return mixed
         */
        public static function string(string $val): mixed {}

        /**
         * @param int $val
         * @return mixed
         */
        public static function uint(int $val): mixed {}

        /**
         * @return mixed
         */
        public static function wildcard(): mixed {}
    }

    /**
     * Represents a wildcard value for Aerospike.
     */
    class Wildcard {
        public function __construct() {}
    }

    /**
     * `WritePolicy` encapsulates parameters for all write operations.
     *
     * v2 BREAKING: legacy fields `sleep_multiplier`, `use_compression`,
     * `exit_fast_on_exhausted_connection_pool`, `read_mode_sc` have been removed.
     */
    class WritePolicy {
        public function __construct() {}

        /**
         * Desired consistency guarantee when committing a transaction on the server.
         *
         * @return \Aerospike\CommitLevel
         */
        public function getCommitLevel(): \Aerospike\CommitLevel {}

        /**
         * DurableDelete leaves a tombstone for the record on deletion. Enterprise only.
         *
         * @return bool
         */
        public function getDurableDelete(): bool {}

        /**
         * Expiration / time-to-live for the record.
         *
         * @return \Aerospike\Expiration
         */
        public function getExpiration(): \Aerospike\Expiration {}

        /**
         * @return \Aerospike\Expression|null
         */
        public function getFilterExpression(): ?\Aerospike\Expression {}

        /**
         * Generation: expected generation count when generation_policy is set.
         *
         * @return int
         */
        public function getGeneration(): int {}

        /**
         * GenerationPolicy qualifies how to handle record writes based on record generation.
         *
         * @return \Aerospike\GenerationPolicy
         */
        public function getGenerationPolicy(): \Aerospike\GenerationPolicy {}

        /**
         * @return int
         */
        public function getMaxRetries(): int {}

        /**
         * @return \Aerospike\ReadModeAP
         */
        public function getReadModeAp(): \Aerospike\ReadModeAP {}

        /**
         * RecordExistsAction qualifies how to handle writes where the record already exists.
         *
         * @return \Aerospike\RecordExistsAction
         */
        public function getRecordExistsAction(): \Aerospike\RecordExistsAction {}

        /**
         * RespondPerEachOp: return a result for every operation in an operate() call.
         *
         * @return bool
         */
        public function getRespondPerEachOp(): bool {}

        /**
         * SendKey: store the user-defined key with the record on the server.
         *
         * @return bool
         */
        public function getSendKey(): bool {}

        /**
         * @return int
         */
        public function getSocketTimeout(): int {}

        /**
         * @return int
         */
        public function getTotalTimeout(): int {}

        /**
         * @param mixed $commit_level
         * @return void
         */
        public function setCommitLevel(mixed $commit_level): void {}

        /**
         * @param bool $durable_delete
         * @return void
         */
        public function setDurableDelete(bool $durable_delete): void {}

        /**
         * @param mixed $expiration
         * @return void
         */
        public function setExpiration(mixed $expiration): void {}

        /**
         * @param mixed $filter_expression
         * @return void
         */
        public function setFilterExpression(mixed $filter_expression = null): void {}

        /**
         * @param int $generation
         * @return void
         */
        public function setGeneration(int $generation): void {}

        /**
         * @param mixed $generation_policy
         * @return void
         */
        public function setGenerationPolicy(mixed $generation_policy): void {}

        /**
         * @param int $max_retries
         * @return void
         */
        public function setMaxRetries(int $max_retries): void {}

        /**
         * @param mixed $read_mode_ap
         * @return void
         */
        public function setReadModeAp(mixed $read_mode_ap): void {}

        /**
         * @param mixed $record_exists_action
         * @return void
         */
        public function setRecordExistsAction(mixed $record_exists_action): void {}

        /**
         * @param bool $respond_per_each_op
         * @return void
         */
        public function setRespondPerEachOp(bool $respond_per_each_op): void {}

        /**
         * @param bool $send_key
         * @return void
         */
        public function setSendKey(bool $send_key): void {}

        /**
         * @param int $timeout_millis
         * @return void
         */
        public function setSocketTimeout(int $timeout_millis): void {}

        /**
         * @param int $timeout_millis
         * @return void
         */
        public function setTotalTimeout(int $timeout_millis): void {}
    }
}
