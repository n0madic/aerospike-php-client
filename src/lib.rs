/*
 * Copyright 2012-2023 Aerospike, Inc.
 *
 * Portions may be licensed to Aerospike, Inc. under one or more contributor
 * license agreements WHICH ARE COMPATIBLE WITH THE APACHE LICENSE, VERSION 2.0.
 *
 * Licensed under the Apache License, Version 2.0 (the "License"); you may not
 * use this file except in compliance with the License. You may obtain a copy of
 * the License at http:///www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS, WITHOUT
 * WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied. See the
 * License for the specific language governing permissions and limitations under
 * the License.
 */

#![cfg_attr(windows, feature(abi_vectorcall))]
#![allow(non_snake_case)]
// PHP-exposed surface dictates names (`Recordset::next`) and signatures (CDT operation
// builders with 8+ parameters) that clippy would flag; suppress at crate level.
#![allow(clippy::should_implement_trait, clippy::too_many_arguments)]

use aerospike::{self as aero};

use std::collections::HashMap;
use std::ffi::c_void;
use std::fmt;
use std::hash::{Hash, Hasher};
use std::os::raw::c_int;
use std::sync::atomic::{AtomicI64, Ordering};
use std::sync::Arc;
use std::sync::LazyLock;
use std::sync::Mutex;

use aero::Task as AeroTask;

use ext_php_rs::binary::Binary;
use ext_php_rs::boxed::ZBox;
use ext_php_rs::convert::IntoZendObject;
use ext_php_rs::convert::{FromZval, IntoZval};
use ext_php_rs::error::Result;
use ext_php_rs::exception::throw_object;
use ext_php_rs::flags::DataType;
use ext_php_rs::info_table_end;
use ext_php_rs::info_table_row;
use ext_php_rs::info_table_start;
use ext_php_rs::php_class;
use ext_php_rs::prelude::*;
use ext_php_rs::types::ArrayKey;
use ext_php_rs::types::ZendHashTable;
use ext_php_rs::types::ZendObject;
use ext_php_rs::types::Zval;
use ext_php_rs::zend::ModuleEntry;

use log::trace;

struct ClientEntry {
    client: Arc<aero::Client>,
    hosts: String,
    /// Retained for diagnostics; cache eviction already happens via the cache key.
    #[allow(dead_code)]
    policy_fingerprint: String,
}

static TOKIO_RT: LazyLock<tokio::runtime::Runtime> = LazyLock::new(|| {
    tokio::runtime::Builder::new_multi_thread()
        .enable_all()
        .build()
        .expect("failed to create Tokio runtime")
});

static CLIENTS: LazyLock<Mutex<HashMap<String, ClientEntry>>> =
    LazyLock::new(|| Mutex::new(HashMap::new()));

////////////////////////////////////////////////////////////////////////////////////////////
//
//  INI configuration
//
//  Four INI directives override the corresponding policy defaults at policy-construction
//  time. `php.ini`, `php-fpm.conf` pools, `.user.ini`, and runtime `ini_set()` all work.
//
//      aerospike.tend_interval     — ClientPolicy::tend_interval (ms)
//      aerospike.connect_timeout   — ClientPolicy::timeout (ms)
//      aerospike.read_timeout      — ReadPolicy::total_timeout (ms)
//      aerospike.write_timeout     — WritePolicy::total_timeout (ms)
//
//  Each entry is registered with a string default of "0", which we treat as "no override
//  — use the upstream `aerospike-client-rust` default". Any positive integer wins over
//  the upstream default at construction. An explicit `$policy->set*()` call always wins
//  over the INI value (the INI is only consulted in `__construct`).
//
////////////////////////////////////////////////////////////////////////////////////////////

const INI_NAME_TEND_INTERVAL: &str = "aerospike.tend_interval";
const INI_NAME_CONNECT_TIMEOUT: &str = "aerospike.connect_timeout";
const INI_NAME_READ_TIMEOUT: &str = "aerospike.read_timeout";
const INI_NAME_WRITE_TIMEOUT: &str = "aerospike.write_timeout";

/// `"0"` is the registered default; `on_modify_long` parses it into `AtomicI64::new(0)`
/// and `ini_long_positive` interprets 0 as "no override".
const INI_DEFAULT: &str = "0";

static INI_TEND_INTERVAL: AtomicI64 = AtomicI64::new(0);
static INI_CONNECT_TIMEOUT: AtomicI64 = AtomicI64::new(0);
static INI_READ_TIMEOUT: AtomicI64 = AtomicI64::new(0);
static INI_WRITE_TIMEOUT: AtomicI64 = AtomicI64::new(0);

/// PHP `OnUpdateLong`-style callback. Parses the new value as a signed integer and stores
/// it into the `AtomicI64` passed through `mh_arg1`. PHP invokes this on module startup
/// (with the configured INI value or the registered default) and again on every
/// `ini_set()` for the directive. Returning `0` is SUCCESS; `-1` is FAILURE.
unsafe extern "C" fn on_modify_long(
    _entry: *mut ext_php_rs::ffi::zend_ini_entry,
    new_value: *mut ext_php_rs::ffi::zend_string,
    mh_arg1: *mut c_void,
    _mh_arg2: *mut c_void,
    _mh_arg3: *mut c_void,
    _stage: c_int,
) -> c_int {
    if new_value.is_null() || mh_arg1.is_null() {
        return 0;
    }
    let parsed: i64 = unsafe {
        let zs = &*new_value;
        let bytes = std::slice::from_raw_parts(zs.val.as_ptr().cast::<u8>(), zs.len);
        match std::str::from_utf8(bytes).map(str::trim) {
            Ok("") => 0,
            Ok(s) => match s.parse() {
                Ok(v) => v,
                Err(_) => return -1,
            },
            Err(_) => return -1,
        }
    };
    unsafe {
        let cell = &*(mh_arg1 as *const AtomicI64);
        cell.store(parsed, Ordering::Relaxed);
    }
    0
}

fn ini_entry_for(name: &str, atomic: &'static AtomicI64) -> ext_php_rs::zend::IniEntryDef {
    let mut entry = ext_php_rs::zend::IniEntryDef::new(
        name.to_string(),
        INI_DEFAULT.to_string(),
        &ext_php_rs::flags::IniEntryPermission::All,
    );
    entry.on_modify = Some(on_modify_long);
    entry.mh_arg1 = atomic as *const AtomicI64 as *mut c_void;
    entry
}

/// Module startup hook wired via `#[php(startup = aerospike_php_startup)]`. PHP invokes
/// this after the extension is loaded; we register our INI entries here so PHP knows about
/// them (no "PHP Warning: Unknown INI entry" for our directives) and will call back into
/// `on_modify_long` to populate the atomics from the configured value.
pub extern "C" fn aerospike_php_startup(_ty: c_int, mod_num: c_int) -> c_int {
    let entries = vec![
        ini_entry_for(INI_NAME_TEND_INTERVAL, &INI_TEND_INTERVAL),
        ini_entry_for(INI_NAME_CONNECT_TIMEOUT, &INI_CONNECT_TIMEOUT),
        ini_entry_for(INI_NAME_READ_TIMEOUT, &INI_READ_TIMEOUT),
        ini_entry_for(INI_NAME_WRITE_TIMEOUT, &INI_WRITE_TIMEOUT),
    ];
    ext_php_rs::zend::IniEntryDef::register(entries, mod_num);
    0
}

/// Returns `Some(v)` only when the INI value is a positive integer that fits in u32.
/// Zero (the registered default) and negative values both mean "no override — use the
/// underlying library default". Values that overflow u32 are also treated as no override
/// rather than silently truncating; the user gets the upstream default and can detect
/// the mistake via the explicit setter (`setTendInterval(u32::MAX + 1)` throws).
fn ini_long_positive(atomic: &AtomicI64) -> Option<u32> {
    let v = atomic.load(Ordering::Relaxed);
    if v > 0 && v <= u32::MAX as i64 {
        Some(v as u32)
    } else {
        None
    }
}

/// Convert an aerospike error to a PHP exception and throw it. Always returns `Ok(default)`
/// after throwing so the surrounding function returns a sentinel value and PHP sees the
/// exception. Use this in place of the repetitive
/// `let error: AerospikeException = (&e).into(); throw_object(error.into_zval(true)?)?;`
/// pattern.
fn throw_aero_error<T>(e: &aero::Error, default: T) -> PhpResult<T> {
    let error: AerospikeException = e.into();
    throw_object(error.into_zval(true)?)?;
    Ok(default)
}

/// Convert a u64 millisecond value to u32, throwing an `AerospikeException` if it
/// exceeds `u32::MAX`. Used by setter methods that take milliseconds — the underlying
/// aerospike crate stores timeouts as `u32`, and a silent `as u32` truncation would
/// turn `u32::MAX + 1` into `0` (which the aerospike client interprets as no timeout),
/// making large nonsensical values dangerously permissive.
fn millis_u64_to_u32(timeout_millis: u64) -> PhpResult<u32> {
    if timeout_millis > u32::MAX as u64 {
        return throw_msg(
            &format!(
                "timeout_millis {timeout_millis} exceeds u32::MAX ({}); aerospike timeouts are u32 milliseconds",
                u32::MAX
            ),
            0u32,
        );
    }
    Ok(timeout_millis as u32)
}

/// Convert a plain error message to a PHP exception and throw it. Returns `Ok(default)`
/// after throwing.
fn throw_msg<T>(msg: &str, default: T) -> PhpResult<T> {
    let error = AerospikeException::new(msg);
    throw_object(error.into_zval(true)?)?;
    Ok(default)
}

pub type AsResult<T = ()> = std::result::Result<T, AerospikeException>;

////////////////////////////////////////////////////////////////////////////////////////////
//
//  ExpressionType (ExpType)
//
////////////////////////////////////////////////////////////////////////////////////////////

/// ExpType defines the expression's data type.
#[php_class]
#[php(name = "Aerospike\\ExpType")]
#[derive(Clone, Copy)]
pub struct ExpType {
    _as: aero::expressions::ExpType,
}

impl FromZval<'_> for ExpType {
    const TYPE: DataType = DataType::Mixed;

    fn from_zval(zval: &Zval) -> Option<Self> {
        let f: &ExpType = zval.extract()?;

        Some(ExpType { _as: f._as })
    }
}

#[php_impl]
impl ExpType {
    /// ExpTypeNIL is NIL Expression Type
    pub fn Nil() -> Self {
        ExpType {
            _as: aero::expressions::ExpType::NIL,
        }
    }

    /// ExpTypeBOOL is BOOLEAN Expression Type
    pub fn Bool() -> Self {
        ExpType {
            _as: aero::expressions::ExpType::BOOL,
        }
    }

    /// ExpTypeINT is INTEGER Expression Type
    pub fn Int() -> Self {
        ExpType {
            _as: aero::expressions::ExpType::INT,
        }
    }

    /// ExpTypeSTRING is STRING Expression Type
    pub fn String() -> Self {
        ExpType {
            _as: aero::expressions::ExpType::STRING,
        }
    }

    /// ExpTypeLIST is LIST Expression Type
    pub fn List() -> Self {
        ExpType {
            _as: aero::expressions::ExpType::LIST,
        }
    }

    /// ExpTypeMAP is MAP Expression Type
    pub fn Map() -> Self {
        ExpType {
            _as: aero::expressions::ExpType::MAP,
        }
    }

    /// ExpTypeBLOB is BLOB Expression Type
    pub fn Blob() -> Self {
        ExpType {
            _as: aero::expressions::ExpType::BLOB,
        }
    }

    /// ExpTypeFLOAT is FLOAT Expression Type
    pub fn Float() -> Self {
        ExpType {
            _as: aero::expressions::ExpType::FLOAT,
        }
    }

    /// ExpTypeGEO is GEO String Expression Type
    pub fn Geo() -> Self {
        ExpType {
            _as: aero::expressions::ExpType::GEO,
        }
    }

    /// ExpTypeHLL is HLL Expression Type
    pub fn Hll() -> Self {
        ExpType {
            _as: aero::expressions::ExpType::HLL,
        }
    }
}

impl From<ExpType> for i32 {
    fn from(input: ExpType) -> Self {
        input._as as i32
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  Filter Expression
//
////////////////////////////////////////////////////////////////////////////////////////////

/// Filter expression, which can be applied to most commands, to control which records are
/// affected by the command.
#[php_class]
#[php(name = "Aerospike\\Expression")]
#[derive(Clone)]
pub struct Expression {
    _as: aero::expressions::Expression,
}

impl FromZval<'_> for Expression {
    const TYPE: DataType = DataType::Mixed;

    fn from_zval(zval: &Zval) -> Option<Self> {
        let f: &Expression = zval.extract()?;

        Some(Expression { _as: f._as.clone() })
    }
}

/// Clone a `Vec<&Expression>` into an owned `Vec<aero::expressions::Expression>` for the
/// variadic aero builders (`and`, `or`, `cond`, `num_add`, ...).
fn aero_exps(exps: Vec<&Expression>) -> Vec<aero::expressions::Expression> {
    exps.iter().map(|e| e._as.clone()).collect()
}

#[php_impl]
impl Expression {
    /// Create a record key expression of specified type.
    pub fn key(exp_type: ExpType) -> Self {
        Expression {
            _as: aero::expressions::key(exp_type._as),
        }
    }

    /// Create function that returns if the primary key is stored in the record meta data
    /// as a boolean expression. This would occur when `send_key` is true on record write.
    pub fn key_exists() -> Self {
        Expression {
            _as: aero::expressions::key_exists(),
        }
    }

    /// Create 64 bit int bin expression.
    pub fn int_bin(name: String) -> Self {
        Expression {
            _as: aero::expressions::int_bin(name),
        }
    }

    /// Create string bin expression.
    pub fn string_bin(name: String) -> Self {
        Expression {
            _as: aero::expressions::string_bin(name),
        }
    }

    /// Create blob bin expression.
    pub fn blob_bin(name: String) -> Self {
        Expression {
            _as: aero::expressions::blob_bin(name),
        }
    }

    /// Create 64 bit float bin expression.
    pub fn float_bin(name: String) -> Self {
        Expression {
            _as: aero::expressions::float_bin(name),
        }
    }

    /// Create geo bin expression.
    pub fn geo_bin(name: String) -> Self {
        Expression {
            _as: aero::expressions::geo_bin(name),
        }
    }

    /// Create list bin expression.
    pub fn list_bin(name: String) -> Self {
        Expression {
            _as: aero::expressions::list_bin(name),
        }
    }

    /// Create map bin expression.
    pub fn map_bin(name: String) -> Self {
        Expression {
            _as: aero::expressions::map_bin(name),
        }
    }

    /// Create a HLL bin expression.
    pub fn hll_bin(name: String) -> Self {
        Expression {
            _as: aero::expressions::hll_bin(name),
        }
    }

    /// Create function that returns if bin of specified name exists.
    pub fn bin_exists(name: String) -> Self {
        Expression {
            _as: aero::expressions::bin_exists(name),
        }
    }

    /// ExpBinType creates a function that returns bin's integer particle type. Valid values are:
    ///
    /// NULL    = 0
    /// INTEGER = 1
    /// FLOAT   = 2
    /// STRING  = 3
    /// BLOB    = 4
    /// DIGEST  = 6
    /// BOOL    = 17
    /// HLL     = 18
    /// MAP     = 19
    /// LIST    = 20
    /// LDT     = 21
    /// GEOJSON = 23
    pub fn bin_type(name: String) -> Self {
        Expression {
            _as: aero::expressions::bin_type(name),
        }
    }

    /// Create function that returns record set name string.
    pub fn set_name() -> Self {
        Expression {
            _as: aero::expressions::set_name(),
        }
    }

    /// Create expression that returns record size on disk (server 7.0+).
    pub fn record_size() -> Self {
        Expression {
            _as: aero::expressions::record_size(),
        }
    }

    /// Create function that returns record size on disk.
    /// If server storage-engine is memory, then zero is returned.
    ///
    /// This expression should only be used for server versions less than 7.0. Use
    /// `record_size` for server version 7.0+.
    pub fn device_size() -> Self {
        #[allow(deprecated)]
        let v = aero::expressions::device_size();
        Expression { _as: v }
    }

    /// Create expression that returns record size in memory (server 5.3..7.0).
    pub fn memory_size() -> Self {
        #[allow(deprecated)]
        let v = aero::expressions::memory_size();
        Expression { _as: v }
    }

    /// Create function that returns record last update time expressed as 64 bit integer
    /// nanoseconds since 1970-01-01 epoch.
    pub fn last_update() -> Self {
        Expression {
            _as: aero::expressions::last_update(),
        }
    }

    /// Create expression that returns milliseconds since the record was last updated.
    pub fn since_update() -> Self {
        Expression {
            _as: aero::expressions::since_update(),
        }
    }

    /// Create function that returns record expiration time expressed as 64 bit integer
    /// nanoseconds since 1970-01-01 epoch.
    pub fn void_time() -> Self {
        Expression {
            _as: aero::expressions::void_time(),
        }
    }

    /// Create function that returns record expiration time (TTL) in integer seconds.
    pub fn ttl() -> Self {
        Expression {
            _as: aero::expressions::ttl(),
        }
    }

    /// Create expression that returns if record has been deleted and is still in tombstone state.
    pub fn is_tombstone() -> Self {
        Expression {
            _as: aero::expressions::is_tombstone(),
        }
    }

    /// Create function that returns record digest modulo as integer.
    pub fn digest_modulo(modulo: i64) -> Self {
        Expression {
            _as: aero::expressions::digest_modulo(modulo),
        }
    }

    /// Create function like regular expression string operation.
    pub fn regex_compare(regex: String, flags: i64, bin: &Expression) -> Self {
        Expression {
            _as: aero::expressions::regex_compare(regex, flags, bin._as.clone()),
        }
    }

    /// Create compare geospatial operation.
    pub fn geo_compare(left: &Expression, right: &Expression) -> Self {
        Expression {
            _as: aero::expressions::geo_compare(left._as.clone(), right._as.clone()),
        }
    }

    /// Creates 64 bit integer value.
    pub fn int_val(val: i64) -> Self {
        Expression {
            _as: aero::expressions::int_val(val),
        }
    }

    /// Creates a Boolean value.
    pub fn bool_val(val: bool) -> Self {
        Expression {
            _as: aero::expressions::bool_val(val),
        }
    }

    /// Creates String bin value.
    pub fn string_val(val: String) -> Self {
        Expression {
            _as: aero::expressions::string_val(val),
        }
    }

    /// Creates 64 bit float bin value.
    pub fn float_val(val: f64) -> Self {
        Expression {
            _as: aero::expressions::float_val(val),
        }
    }

    /// Creates Blob bin value.
    pub fn blob_val(val: Vec<u8>) -> Self {
        Expression {
            _as: aero::expressions::blob_val(val),
        }
    }

    /// Create List bin value.
    pub fn list_val(val: Vec<PHPValue>) -> Self {
        Expression {
            _as: aero::expressions::list_val(php_values_to_aero(val)),
        }
    }

    /// Create Map bin value. Returns `None` if `val` is not a PHP associative array (HashMap/Json).
    pub fn map_val(val: PHPValue) -> Option<Self> {
        let m: HashMap<aero::Value, aero::Value> = match val {
            PHPValue::HashMap(h) => h.into_iter().map(|(k, v)| (k.into(), v.into())).collect(),
            PHPValue::Json(h) => h
                .into_iter()
                .map(|(k, v)| (aero::Value::String(k), v.into()))
                .collect(),
            _ => return None,
        };
        Some(Expression {
            _as: aero::expressions::map_val(m),
        })
    }

    /// Create geospatial JSON string value.
    pub fn geo_val(val: String) -> Self {
        Expression {
            _as: aero::expressions::geo_val(val),
        }
    }

    /// Create a Nil value.
    pub fn nil() -> Self {
        Expression {
            _as: aero::expressions::nil(),
        }
    }

    /// Create an Infinity value.
    pub fn infinity() -> Self {
        Expression {
            _as: aero::expressions::infinity(),
        }
    }

    /// Create a Wildcard value.
    pub fn wildcard() -> Self {
        Expression {
            _as: aero::expressions::wildcard(),
        }
    }

    /// Create "not" operator expression.
    pub fn not(exp: &Expression) -> Self {
        Expression {
            _as: aero::expressions::not(exp._as.clone()),
        }
    }

    /// Create "and" (&&) operator that applies to a variable number of expressions.
    pub fn and(exps: Vec<&Expression>) -> Self {
        Expression {
            _as: aero::expressions::and(aero_exps(exps)),
        }
    }

    /// Create "or" (||) operator that applies to a variable number of expressions.
    pub fn or(exps: Vec<&Expression>) -> Self {
        Expression {
            _as: aero::expressions::or(aero_exps(exps)),
        }
    }

    /// Create integer "xor" (^) operator that applies to a variable number of expressions.
    ///
    /// v1-compatible alias for `int_xor` (the proto/v1 implementation also mapped to
    /// integer XOR). For boolean XOR, call `bool_xor` instead.
    pub fn xor(exps: Vec<&Expression>) -> Self {
        Expression {
            _as: aero::expressions::int_xor(aero_exps(exps)),
        }
    }

    /// Create boolean "xor" (^) operator that applies to a variable number of expressions.
    /// New in v2 — exposes aero's boolean XOR builder (opcode 19). Use `int_xor` /
    /// `xor` for the integer-bitmask variant.
    pub fn bool_xor(exps: Vec<&Expression>) -> Self {
        Expression {
            _as: aero::expressions::xor(aero_exps(exps)),
        }
    }

    /// Create equal (==) expression.
    pub fn eq(left: &Expression, right: &Expression) -> Self {
        Expression {
            _as: aero::expressions::eq(left._as.clone(), right._as.clone()),
        }
    }

    /// Create not equal (!=) expression.
    pub fn ne(left: &Expression, right: &Expression) -> Self {
        Expression {
            _as: aero::expressions::ne(left._as.clone(), right._as.clone()),
        }
    }

    /// Create greater than (>) operation.
    pub fn gt(left: &Expression, right: &Expression) -> Self {
        Expression {
            _as: aero::expressions::gt(left._as.clone(), right._as.clone()),
        }
    }

    /// Create greater than or equal (>=) operation.
    pub fn ge(left: &Expression, right: &Expression) -> Self {
        Expression {
            _as: aero::expressions::ge(left._as.clone(), right._as.clone()),
        }
    }

    /// Create less than (<) operation.
    pub fn lt(left: &Expression, right: &Expression) -> Self {
        Expression {
            _as: aero::expressions::lt(left._as.clone(), right._as.clone()),
        }
    }

    /// Create less than or equals (<=) operation.
    pub fn le(left: &Expression, right: &Expression) -> Self {
        Expression {
            _as: aero::expressions::le(left._as.clone(), right._as.clone()),
        }
    }

    /// Create "add" (+) operator that applies to a variable number of expressions.
    /// Requires server version 5.6.0+.
    pub fn num_add(exps: Vec<&Expression>) -> Self {
        Expression {
            _as: aero::expressions::num_add(aero_exps(exps)),
        }
    }

    /// Create "subtract" (-) operator that applies to a variable number of expressions.
    pub fn num_sub(exps: Vec<&Expression>) -> Self {
        Expression {
            _as: aero::expressions::num_sub(aero_exps(exps)),
        }
    }

    /// Create "multiply" (*) operator that applies to a variable number of expressions.
    pub fn num_mul(exps: Vec<&Expression>) -> Self {
        Expression {
            _as: aero::expressions::num_mul(aero_exps(exps)),
        }
    }

    /// Create "divide" (/) operator that applies to a variable number of expressions.
    pub fn num_div(exps: Vec<&Expression>) -> Self {
        Expression {
            _as: aero::expressions::num_div(aero_exps(exps)),
        }
    }

    /// Create "power" operator that raises a "base" to the "exponent" power.
    pub fn num_pow(base: &Expression, exponent: &Expression) -> Self {
        Expression {
            _as: aero::expressions::num_pow(base._as.clone(), exponent._as.clone()),
        }
    }

    /// Create "log" operator for logarithm of "num" with base "base".
    pub fn num_log(num: &Expression, base: &Expression) -> Self {
        Expression {
            _as: aero::expressions::num_log(num._as.clone(), base._as.clone()),
        }
    }

    /// Create "modulo" (%) operator.
    pub fn num_mod(numerator: &Expression, denominator: &Expression) -> Self {
        Expression {
            _as: aero::expressions::num_mod(numerator._as.clone(), denominator._as.clone()),
        }
    }

    /// Create operator that returns absolute value of a number.
    pub fn num_abs(value: &Expression) -> Self {
        Expression {
            _as: aero::expressions::num_abs(value._as.clone()),
        }
    }

    /// Create expression that rounds a floating point number down to the closest integer value.
    pub fn num_floor(num: &Expression) -> Self {
        Expression {
            _as: aero::expressions::num_floor(num._as.clone()),
        }
    }

    /// Create expression that rounds a floating point number up to the closest integer value.
    pub fn num_ceil(num: &Expression) -> Self {
        Expression {
            _as: aero::expressions::num_ceil(num._as.clone()),
        }
    }

    /// Create expression that converts a float to an integer.
    pub fn to_int(num: &Expression) -> Self {
        Expression {
            _as: aero::expressions::to_int(num._as.clone()),
        }
    }

    /// Create expression that converts an integer to a float.
    pub fn to_float(num: &Expression) -> Self {
        Expression {
            _as: aero::expressions::to_float(num._as.clone()),
        }
    }

    /// Create integer "and" (&) operator.
    pub fn int_and(exps: Vec<&Expression>) -> Self {
        Expression {
            _as: aero::expressions::int_and(aero_exps(exps)),
        }
    }

    /// Create integer "or" (|) operator.
    pub fn int_or(exps: Vec<&Expression>) -> Self {
        Expression {
            _as: aero::expressions::int_or(aero_exps(exps)),
        }
    }

    /// Create integer "xor" (^) operator.
    pub fn int_xor(exps: Vec<&Expression>) -> Self {
        Expression {
            _as: aero::expressions::int_xor(aero_exps(exps)),
        }
    }

    /// Create integer "not" (~) operator.
    pub fn int_not(exp: &Expression) -> Self {
        Expression {
            _as: aero::expressions::int_not(exp._as.clone()),
        }
    }

    /// Create integer "left shift" (<<) operator.
    pub fn int_lshift(value: &Expression, shift: &Expression) -> Self {
        Expression {
            _as: aero::expressions::int_lshift(value._as.clone(), shift._as.clone()),
        }
    }

    /// Create integer "logical right shift" (>>>) operator.
    pub fn int_rshift(value: &Expression, shift: &Expression) -> Self {
        Expression {
            _as: aero::expressions::int_rshift(value._as.clone(), shift._as.clone()),
        }
    }

    /// Create integer "arithmetic right shift" (>>) operator.
    pub fn int_arshift(value: &Expression, shift: &Expression) -> Self {
        Expression {
            _as: aero::expressions::int_arshift(value._as.clone(), shift._as.clone()),
        }
    }

    /// Create expression that returns count of integer bits that are set to 1.
    pub fn int_count(exp: &Expression) -> Self {
        Expression {
            _as: aero::expressions::int_count(exp._as.clone()),
        }
    }

    /// Create expression that scans integer bits left-to-right for a search bit value.
    pub fn int_lscan(value: &Expression, search: &Expression) -> Self {
        Expression {
            _as: aero::expressions::int_lscan(value._as.clone(), search._as.clone()),
        }
    }

    /// Create expression that scans integer bits right-to-left for a search bit value.
    pub fn int_rscan(value: &Expression, search: &Expression) -> Self {
        Expression {
            _as: aero::expressions::int_rscan(value._as.clone(), search._as.clone()),
        }
    }

    /// Create expression that returns the minimum value in a variable number of expressions.
    pub fn min(exps: Vec<&Expression>) -> Self {
        Expression {
            _as: aero::expressions::min(aero_exps(exps)),
        }
    }

    /// Create expression that returns the maximum value in a variable number of expressions.
    pub fn max(exps: Vec<&Expression>) -> Self {
        Expression {
            _as: aero::expressions::max(aero_exps(exps)),
        }
    }

    /// Conditionally select an expression from a variable number of expression pairs
    /// followed by default expression action.
    pub fn cond(exps: Vec<&Expression>) -> Self {
        Expression {
            _as: aero::expressions::cond(aero_exps(exps)),
        }
    }

    /// Define variables and expressions in scope.
    pub fn exp_let(exps: Vec<&Expression>) -> Self {
        Expression {
            _as: aero::expressions::exp_let(aero_exps(exps)),
        }
    }

    /// Assign variable to an expression that can be accessed later.
    pub fn def(name: String, value: &Expression) -> Self {
        Expression {
            _as: aero::expressions::def(name, value._as.clone()),
        }
    }

    /// Retrieve expression value from a variable.
    pub fn var(name: String) -> Self {
        Expression {
            _as: aero::expressions::var(name),
        }
    }

    /// Create unknown value. Used to intentionally fail an expression.
    pub fn unknown() -> Self {
        Expression {
            _as: aero::expressions::unknown(),
        }
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  ReadModeAP
//
////////////////////////////////////////////////////////////////////////////////////////////

/// ReadModeAP is the read policy in AP (availability) mode namespaces.
/// It indicates how duplicates should be consulted in a read operation.
/// Only makes a difference during migrations and only applicable in AP mode.
///
/// Backed by `aerospike::ConsistencyLevel` since the Rust client merges AP read mode
/// into the unified consistency-level concept.
#[php_class]
#[php(name = "Aerospike\\ReadModeAP")]
pub struct ReadModeAP {
    _as: aero::ConsistencyLevel,
}

#[php_impl]
impl ReadModeAP {
    /// ReadModeAPOne indicates that a single node should be involved in the read operation.
    pub fn One() -> Self {
        ReadModeAP {
            _as: aero::ConsistencyLevel::ConsistencyOne,
        }
    }

    /// ReadModeAPAll indicates that all duplicates should be consulted in
    /// the read operation.
    pub fn All() -> Self {
        ReadModeAP {
            _as: aero::ConsistencyLevel::ConsistencyAll,
        }
    }
}

impl From<&ReadModeAP> for aero::ConsistencyLevel {
    fn from(v: &ReadModeAP) -> aero::ConsistencyLevel {
        v._as.clone()
    }
}

impl From<aero::ConsistencyLevel> for ReadModeAP {
    fn from(v: aero::ConsistencyLevel) -> ReadModeAP {
        ReadModeAP { _as: v }
    }
}

impl FromZval<'_> for ReadModeAP {
    const TYPE: DataType = DataType::Mixed;

    fn from_zval(zval: &Zval) -> Option<Self> {
        let f: &ReadModeAP = zval.extract()?;

        Some(ReadModeAP { _as: f._as.clone() })
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  ReadModeSC
//
////////////////////////////////////////////////////////////////////////////////////////////

/// ReadModeSC is the read policy in SC (strong consistency) mode namespaces.
/// Determines SC read consistency options.
///
/// NOTE: aerospike-client-rust v2 does not yet model SC read modes separately; the
/// chosen value is stored on the policy for forward compatibility but currently has
/// no runtime effect.
#[php_class]
#[php(name = "Aerospike\\ReadModeSC")]
#[derive(Clone, Copy)]
pub struct ReadModeSC {
    mode: ReadModeScMode,
}

#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub enum ReadModeScMode {
    Session,
    Linearize,
    AllowReplica,
    AllowUnavailable,
}

#[php_impl]
impl ReadModeSC {
    /// ReadModeSCSession ensures this client will only see an increasing sequence of record versions.
    pub fn Session() -> Self {
        ReadModeSC {
            mode: ReadModeScMode::Session,
        }
    }

    /// ReadModeSCLinearize ensures ALL clients will only see an increasing sequence of record versions.
    pub fn Linearize() -> Self {
        ReadModeSC {
            mode: ReadModeScMode::Linearize,
        }
    }

    /// ReadModeSCAllowReplica indicates that the server may read from master or any full (non-migrating) replica.
    pub fn AllowReplica() -> Self {
        ReadModeSC {
            mode: ReadModeScMode::AllowReplica,
        }
    }

    /// ReadModeSCAllowUnavailable indicates that the server may read from master or any full (non-migrating) replica or from unavailable
    /// partitions.
    pub fn AllowUnavailable() -> Self {
        ReadModeSC {
            mode: ReadModeScMode::AllowUnavailable,
        }
    }
}

impl FromZval<'_> for ReadModeSC {
    const TYPE: DataType = DataType::Mixed;

    fn from_zval(zval: &Zval) -> Option<Self> {
        let f: &ReadModeSC = zval.extract()?;

        Some(ReadModeSC { mode: f.mode })
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  RecordExistsAction
//
////////////////////////////////////////////////////////////////////////////////////////////

/// RecordExistsAction determines how to handle writes when
/// the record already exists.
#[php_class]
#[php(name = "Aerospike\\RecordExistsAction")]
pub struct RecordExistsAction {
    _as: aero::RecordExistsAction,
}

impl FromZval<'_> for RecordExistsAction {
    const TYPE: DataType = DataType::Mixed;

    fn from_zval(zval: &Zval) -> Option<Self> {
        let f: &RecordExistsAction = zval.extract()?;

        Some(RecordExistsAction { _as: f._as.clone() })
    }
}

#[php_impl]
impl RecordExistsAction {
    /// Update means: Create or update record.
    /// Merge write command bins with existing bins.
    pub fn Update() -> Self {
        RecordExistsAction {
            _as: aero::RecordExistsAction::Update,
        }
    }

    /// UpdateOnly means: Update record only. Fail if record does not exist.
    pub fn Update_Only() -> Self {
        RecordExistsAction {
            _as: aero::RecordExistsAction::UpdateOnly,
        }
    }

    /// Replace means: Create or replace record.
    /// Delete existing bins not referenced by write command bins.
    pub fn Replace() -> Self {
        RecordExistsAction {
            _as: aero::RecordExistsAction::Replace,
        }
    }

    /// ReplaceOnly means: Replace record only. Fail if record does not exist.
    pub fn Replace_Only() -> Self {
        RecordExistsAction {
            _as: aero::RecordExistsAction::ReplaceOnly,
        }
    }

    /// CreateOnly means: Create only. Fail if record exists.
    pub fn Create_Only() -> Self {
        RecordExistsAction {
            _as: aero::RecordExistsAction::CreateOnly,
        }
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  QueryDuration
//
////////////////////////////////////////////////////////////////////////////////////////////

/// QueryDuration represents the expected duration for a query operation in the Aerospike database.
#[php_class]
#[php(name = "Aerospike\\QueryDuration")]
pub struct QueryDuration {
    _as: aero::QueryDuration,
}

impl FromZval<'_> for QueryDuration {
    const TYPE: DataType = DataType::Mixed;

    fn from_zval(zval: &Zval) -> Option<Self> {
        let f: &QueryDuration = zval.extract()?;

        Some(QueryDuration { _as: f._as.clone() })
    }
}

#[php_impl]
impl QueryDuration {
    /// LONG specifies that the query is expected to return more than 100 records per node.
    pub fn Long() -> Self {
        QueryDuration {
            _as: aero::QueryDuration::Long,
        }
    }

    /// Short specifies that the query is expected to return less than 100 records per node.
    pub fn Short() -> Self {
        QueryDuration {
            _as: aero::QueryDuration::Short,
        }
    }

    /// LongRelaxAP will treat query as a LONG query, but relax read consistency for AP namespaces.
    pub fn LongRelaxAP() -> Self {
        QueryDuration {
            _as: aero::QueryDuration::LongRelaxAP,
        }
    }
}

impl From<&aero::QueryDuration> for QueryDuration {
    fn from(input: &aero::QueryDuration) -> Self {
        QueryDuration { _as: input.clone() }
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  CommitLevel
//
////////////////////////////////////////////////////////////////////////////////////////////

/// CommitLevel indicates the desired consistency guarantee when committing a transaction on the server.
#[php_class]
#[php(name = "Aerospike\\CommitLevel")]
pub struct CommitLevel {
    _as: aero::CommitLevel,
}

impl FromZval<'_> for CommitLevel {
    const TYPE: DataType = DataType::Mixed;

    fn from_zval(zval: &Zval) -> Option<Self> {
        let f: &CommitLevel = zval.extract()?;

        Some(CommitLevel { _as: f._as.clone() })
    }
}

#[php_impl]
impl CommitLevel {
    /// CommitAll indicates the server should wait until successfully committing master and all replicas.
    pub fn Commit_All() -> Self {
        CommitLevel {
            _as: aero::CommitLevel::CommitAll,
        }
    }

    /// CommitMaster indicates the server should wait until successfully committing master only.
    pub fn Commit_Master() -> Self {
        CommitLevel {
            _as: aero::CommitLevel::CommitMaster,
        }
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  ConsistencyLevel
//
////////////////////////////////////////////////////////////////////////////////////////////

/// `ConsistencyLevel` indicates how replicas should be consulted in a read
/// operation to provide the desired consistency guarantee.
#[derive(Debug, Clone, Copy)]
pub enum _ConsistencyLevel {
    ConsistencyOne,
    ConsistencyAll,
}

#[php_class]
#[php(name = "Aerospike\\ConsistencyLevel")]
pub struct ConsistencyLevel {
    v: _ConsistencyLevel,
}

impl FromZval<'_> for ConsistencyLevel {
    const TYPE: DataType = DataType::Mixed;

    fn from_zval(zval: &Zval) -> Option<Self> {
        let f: &ConsistencyLevel = zval.extract()?;

        Some(ConsistencyLevel { v: f.v })
    }
}

#[php_impl]
impl ConsistencyLevel {
    /// ConsistencyOne indicates only a single replica should be consulted in
    /// the read operation.
    pub fn Consistency_One() -> Self {
        ConsistencyLevel {
            v: _ConsistencyLevel::ConsistencyOne,
        }
    }

    /// ConsistencyAll indicates that all replicas should be consulted in
    /// the read operation.
    pub fn Consistency_All() -> Self {
        ConsistencyLevel {
            v: _ConsistencyLevel::ConsistencyAll,
        }
    }
}

impl From<&ConsistencyLevel> for aero::ConsistencyLevel {
    fn from(input: &ConsistencyLevel) -> Self {
        match &input.v {
            _ConsistencyLevel::ConsistencyOne => aero::ConsistencyLevel::ConsistencyOne,
            _ConsistencyLevel::ConsistencyAll => aero::ConsistencyLevel::ConsistencyAll,
        }
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  GenerationPolicy
//
////////////////////////////////////////////////////////////////////////////////////////////

/// `GenerationPolicy` determines how to handle record writes based on record generation.
#[php_class]
#[php(name = "Aerospike\\GenerationPolicy")]
pub struct GenerationPolicy {
    _as: aero::GenerationPolicy,
}

impl FromZval<'_> for GenerationPolicy {
    const TYPE: DataType = DataType::Mixed;

    fn from_zval(zval: &Zval) -> Option<Self> {
        let f: &GenerationPolicy = zval.extract()?;

        Some(GenerationPolicy { _as: f._as.clone() })
    }
}

#[php_impl]
impl GenerationPolicy {
    /// None means: Do not use record generation to restrict writes.
    pub fn None() -> Self {
        GenerationPolicy {
            _as: aero::GenerationPolicy::None,
        }
    }

    /// ExpectGenEqual means: Update/delete record if expected generation is equal to server generation.
    pub fn Expect_Gen_Equal() -> Self {
        GenerationPolicy {
            _as: aero::GenerationPolicy::ExpectGenEqual,
        }
    }

    /// ExpectGenGreater means: Update/delete record if expected generation greater than the server generation.
    pub fn Expect_Gen_Greater() -> Self {
        GenerationPolicy {
            _as: aero::GenerationPolicy::ExpectGenGreater,
        }
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  Expiration
//
////////////////////////////////////////////////////////////////////////////////////////////

const NAMESPACE_DEFAULT: u32 = 0x0000_0000;
const NEVER_EXPIRE: u32 = 0xFFFF_FFFF; // -1 as i32
const DONT_UPDATE: u32 = 0xFFFF_FFFE;

#[php_class]
#[php(name = "Aerospike\\Expiration")]
pub struct Expiration {
    _as: aero::Expiration,
}

impl FromZval<'_> for Expiration {
    const TYPE: DataType = DataType::Mixed;

    fn from_zval(zval: &Zval) -> Option<Self> {
        let f: &Expiration = zval.extract()?;

        Some(Expiration { _as: f._as })
    }
}

#[php_impl]
impl Expiration {
    /// Set the record to expire X seconds from now.  See also `getTtl()`.
    pub fn Seconds(seconds: u32) -> Self {
        Expiration {
            _as: aero::Expiration::Seconds(seconds),
        }
    }

    /// Answers with the expiration's current time to live in units of seconds.
    /// Returns null for any non-Seconds variant.
    pub fn get_ttl(&self) -> Option<u32> {
        match self._as {
            aero::Expiration::Seconds(secs) => Some(secs),
            _ => None,
        }
    }

    /// Set the record's expiry time using the default TTL for the namespace.
    pub fn Namespace_Default() -> Self {
        Expiration {
            _as: aero::Expiration::NamespaceDefault,
        }
    }

    /// Answers true only if the expiration is set to use the namespace default.
    pub fn is_namespace_default(&self) -> bool {
        matches!(self._as, aero::Expiration::NamespaceDefault)
    }

    /// Set the record to never expire.
    pub fn Never() -> Self {
        Expiration {
            _as: aero::Expiration::Never,
        }
    }

    /// Answers true only if the expiration is set to never expire.
    pub fn will_never_expire(&self) -> bool {
        matches!(self._as, aero::Expiration::Never)
    }

    /// Do not change the record's expiry time when updating the record.
    pub fn Dont_Update() -> Self {
        Expiration {
            _as: aero::Expiration::DontUpdate,
        }
    }

    /// True if the expiration is configured to change during a record update.
    pub fn will_update_expiration(&self) -> bool {
        !matches!(self._as, aero::Expiration::DontUpdate)
    }
}

impl From<&Expiration> for u32 {
    fn from(exp: &Expiration) -> u32 {
        u32::from(exp._as)
    }
}

impl From<u32> for Expiration {
    fn from(exp: u32) -> Expiration {
        match exp {
            NAMESPACE_DEFAULT => Expiration::Namespace_Default(),
            NEVER_EXPIRE => Expiration::Never(),
            DONT_UPDATE => Expiration::Dont_Update(),
            secs => Expiration::Seconds(secs),
        }
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  Concurrency
//
////////////////////////////////////////////////////////////////////////////////////////////

/// Specifies whether a command, that needs to be executed on multiple cluster nodes, should be
/// executed sequentially, one node at a time, or in parallel on multiple nodes using the client's
/// thread pool.
#[derive(Debug, Clone, Copy)]
pub enum _Concurrency {
    Sequential,
    Parallel,
    MaxThreads(u32),
}

#[php_class]
#[php(name = "Aerospike\\Concurrency")]
pub struct Concurrency {
    v: _Concurrency,
}

impl FromZval<'_> for Concurrency {
    const TYPE: DataType = DataType::Mixed;

    fn from_zval(zval: &Zval) -> Option<Self> {
        let f: &Concurrency = zval.extract()?;

        Some(Concurrency { v: f.v })
    }
}

#[php_impl]
impl Concurrency {
    /// Issue commands sequentially. This mode has a performance advantage for small to
    /// medium sized batch sizes because requests can be issued in the main transaction thread.
    /// This is the default.
    pub fn Sequential() -> Self {
        Concurrency {
            v: _Concurrency::Sequential,
        }
    }

    /// Issue all commands in parallel threads. This mode has a performance advantage for
    /// extremely large batch sizes because each node can process the request immediately. The
    /// downside is extra threads will need to be created (or takedn from a thread pool).
    pub fn Parallel() -> Self {
        Concurrency {
            v: _Concurrency::Parallel,
        }
    }

    /// Issue up to N commands in parallel threads. When a request completes, a new request
    /// will be issued until all threads are complete. This mode prevents too many parallel threads
    /// being created for large cluster implementations. The downside is extra threads will still
    /// need to be created (or taken from a thread pool).
    ///
    /// E.g. if there are 16 nodes/namespace combinations requested and concurrency is set to
    /// `MaxThreads(8)`, then batch requests will be made for 8 node/namespace combinations in
    /// parallel threads. When a request completes, a new request will be issued until all 16
    /// requests are complete.
    pub fn Max_Threads(threads: u32) -> Self {
        Concurrency {
            v: _Concurrency::MaxThreads(threads),
        }
    }
}

impl From<&Concurrency> for u32 {
    fn from(input: &Concurrency) -> Self {
        match &input.v {
            _Concurrency::Sequential => 1,
            _Concurrency::Parallel => 0,
            _Concurrency::MaxThreads(threads) => *threads,
        }
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  ListOrderType
//
////////////////////////////////////////////////////////////////////////////////////////////

/// Specifies whether a command, that needs to be executed on multiple cluster nodes, should be
/// executed sequentially, one node at a time, or in parallel on multiple nodes using the client's
/// thread pool.
#[php_class]
#[php(name = "Aerospike\\ListOrderType")]
#[derive(Clone, Copy)]
pub struct ListOrderType {
    _as: aero::ListOrderType,
}

impl FromZval<'_> for ListOrderType {
    const TYPE: DataType = DataType::Mixed;

    fn from_zval(zval: &Zval) -> Option<Self> {
        let f: &ListOrderType = zval.extract()?;

        Some(ListOrderType { _as: f._as })
    }
}

#[php_impl]
impl ListOrderType {
    fn flag(&self) -> i32 {
        match self._as {
            aero::ListOrderType::Unordered => 0,
            aero::ListOrderType::Ordered => 1,
        }
    }

    /// ListOrderOrdered signifies that list is Ordered.
    pub fn Ordered() -> Self {
        ListOrderType {
            _as: aero::ListOrderType::Ordered,
        }
    }

    /// ListOrderUnordered signifies that list is not ordered. This is the default.
    pub fn Unordered() -> Self {
        ListOrderType {
            _as: aero::ListOrderType::Unordered,
        }
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  MapOrderType
//
////////////////////////////////////////////////////////////////////////////////////////////

/// Specifies whether a command, that needs to be executed on multiple cluster nodes, should be
/// executed sequentially, one node at a time, or in parallel on multiple nodes using the client's
/// thread pool.
#[php_class]
#[php(name = "Aerospike\\MapOrderType")]
#[derive(Clone, Copy)]
pub struct MapOrderType {
    _as: aero::operations::maps::MapOrder,
}

impl FromZval<'_> for MapOrderType {
    const TYPE: DataType = DataType::Mixed;

    fn from_zval(zval: &Zval) -> Option<Self> {
        let f: &MapOrderType = zval.extract()?;

        Some(MapOrderType { _as: f._as })
    }
}

#[php_impl]
impl MapOrderType {
    fn attr(&self) -> i32 {
        match self._as {
            aero::operations::maps::MapOrder::Unordered => 0,
            aero::operations::maps::MapOrder::KeyOrdered => 1,
            aero::operations::maps::MapOrder::KeyValueOrdered => 3,
        }
    }

    fn flag(&self) -> i32 {
        match self._as {
            aero::operations::maps::MapOrder::Unordered => 0x40,
            aero::operations::maps::MapOrder::KeyOrdered => 0x80,
            aero::operations::maps::MapOrder::KeyValueOrdered => 0xc0,
        }
    }

    /// Map is not ordered. This is the default.
    pub fn Unordered() -> Self {
        MapOrderType {
            _as: aero::operations::maps::MapOrder::Unordered,
        }
    }

    /// Order map by key.
    pub fn Key_Ordered() -> Self {
        MapOrderType {
            _as: aero::operations::maps::MapOrder::KeyOrdered,
        }
    }

    /// Order map by key, then value.
    pub fn Key_Value_Ordered() -> Self {
        MapOrderType {
            _as: aero::operations::maps::MapOrder::KeyValueOrdered,
        }
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  CDTContext
//
////////////////////////////////////////////////////////////////////////////////////////////

/// CDTContext defines Nested CDT context. Identifies the location of nested list/map to apply the operation.
/// for the current level.
/// An array of CTX identifies location of the list/map on multiple
/// levels on nesting.
#[php_class]
#[php(name = "Aerospike\\Context")]
pub struct CDTContext {
    _as: aero::operations::cdt_context::CdtContext,
}

/// `CDTContext` encapsulates parameters for transaction policy attributes
/// used in all database operation calls.
#[php_impl]
impl CDTContext {
    pub fn __construct() -> Self {
        CDTContext {
            _as: aero::operations::cdt_context::CdtContext {
                id: 0,
                flags: 0,
                value: aero::Value::Nil,
            },
        }
    }

    /// CtxListIndex defines Lookup list by index offset.
    /// If the index is negative, the resolved index starts backwards from end of list.
    /// If an index is out of bounds, a parameter error will be returned.
    /// Examples:
    /// 0: First item.
    /// 4: Fifth item.
    /// -1: Last item.
    /// -3: Third to last item.
    pub fn ListIndex(index: i32) -> Self {
        CDTContext {
            _as: aero::operations::cdt_context::ctx_list_index(i64::from(index)),
        }
    }

    /// CtxListIndexCreate list with given type at index offset, given an order and pad.
    pub fn ListIndexCreate(index: i32, order: ListOrderType, pad: bool) -> Self {
        CDTContext {
            _as: aero::operations::cdt_context::ctx_list_index_create(
                i64::from(index),
                order._as,
                pad,
            ),
        }
    }

    /// CtxListRank defines Lookup list by rank.
    /// 0 = smallest value
    /// N = Nth smallest value
    /// -1 = largest value
    pub fn ListRank(rank: i32) -> Self {
        CDTContext {
            _as: aero::operations::cdt_context::ctx_list_rank(i64::from(rank)),
        }
    }

    /// CtxListValue defines Lookup list by value.
    pub fn ListValue(key: PHPValue) -> Self {
        CDTContext {
            _as: aero::operations::cdt_context::ctx_list_value(key.into()),
        }
    }

    /// CtxMapIndex defines Lookup map by index offset.
    /// If the index is negative, the resolved index starts backwards from end of list.
    /// If an index is out of bounds, a parameter error will be returned.
    /// Examples:
    /// 0: First item.
    /// 4: Fifth item.
    /// -1: Last item.
    /// -3: Third to last item.
    pub fn MapIndex(index: i32) -> Self {
        CDTContext {
            _as: aero::operations::cdt_context::ctx_map_index(aero::Value::Int(i64::from(index))),
        }
    }

    /// CtxMapRank defines Lookup map by rank.
    /// 0 = smallest value
    /// N = Nth smallest value
    /// -1 = largest value
    pub fn MapRank(rank: i32) -> Self {
        CDTContext {
            _as: aero::operations::cdt_context::ctx_map_rank(i64::from(rank)),
        }
    }

    /// CtxMapKey defines Lookup map by key.
    pub fn MapKey(key: PHPValue) -> Self {
        CDTContext {
            _as: aero::operations::cdt_context::ctx_map_key(key.into()),
        }
    }

    /// CtxMapKeyCreate creates map with given type at map key.
    pub fn MapKeyCreate(key: PHPValue, order: MapOrderType) -> Self {
        CDTContext {
            _as: aero::operations::cdt_context::ctx_map_key_create(key.into(), order._as),
        }
    }

    /// CtxMapValue defines Lookup map by value.
    pub fn MapValue(key: PHPValue) -> Self {
        CDTContext {
            _as: aero::operations::cdt_context::ctx_map_value(key.into()),
        }
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  ReadPolicy
//
////////////////////////////////////////////////////////////////////////////////////////////

/// `ReadPolicy` encapsulates parameters for transaction policy attributes
/// used in all database operation calls.
///
/// v2 BREAKING: `sleep_multiplier`, `use_compression`, `exit_fast_on_exhausted_connection_pool`,
/// `send_key` and `read_mode_sc` are not supported by the native aerospike-client-rust crate
/// and have been removed. `read_mode_ap` maps to the new `consistency_level` concept.
#[php_class]
#[php(name = "Aerospike\\ReadPolicy")]
#[derive(Default)]
pub struct ReadPolicy {
    _as: aero::ReadPolicy,
}

#[php_impl]
impl ReadPolicy {
    pub fn __construct() -> Self {
        let mut p = ReadPolicy::default();
        if let Some(v) = ini_long_positive(&INI_READ_TIMEOUT) {
            p._as.base_policy.total_timeout = v;
        }
        p
    }

    /// MaxRetries determines the maximum number of retries before aborting the current transaction.
    pub fn get_max_retries(&self) -> u32 {
        self._as.base_policy.max_retries as u32
    }
    pub fn set_max_retries(&mut self, max_retries: u32) {
        self._as.base_policy.max_retries = max_retries as usize;
    }

    /// TotalTimeout specifies total transaction timeout in milliseconds.
    pub fn get_total_timeout(&self) -> u64 {
        u64::from(self._as.base_policy.total_timeout)
    }
    pub fn set_total_timeout(&mut self, timeout_millis: u64) -> PhpResult<()> {
        self._as.base_policy.total_timeout = millis_u64_to_u32(timeout_millis)?;
        Ok(())
    }

    /// SocketTimeout determines network timeout for each attempt in milliseconds.
    pub fn get_socket_timeout(&self) -> u64 {
        u64::from(self._as.base_policy.socket_timeout)
    }
    pub fn set_socket_timeout(&mut self, timeout_millis: u64) -> PhpResult<()> {
        self._as.base_policy.socket_timeout = millis_u64_to_u32(timeout_millis)?;
        Ok(())
    }

    /// ReadTouchTTLPercent determines how record TTL is affected on reads.
    /// 0 = use server default, -1 = don't reset, 1..=100 = percentage. Supported in server v8+.
    pub fn get_read_touch_ttl_percent(&self) -> i32 {
        match self._as.base_policy.read_touch_ttl {
            aero::policy::ReadTouchTTL::Percent(p) => i32::from(p),
            aero::policy::ReadTouchTTL::ServerDefault => 0,
            aero::policy::ReadTouchTTL::DontReset => -1,
        }
    }
    pub fn set_read_touch_ttl_percent(&mut self, percent: i32) {
        self._as.base_policy.read_touch_ttl = match percent {
            0 => aero::policy::ReadTouchTTL::ServerDefault,
            -1 => aero::policy::ReadTouchTTL::DontReset,
            p if (1..=100).contains(&p) => aero::policy::ReadTouchTTL::Percent(p as u8),
            _ => aero::policy::ReadTouchTTL::ServerDefault,
        };
    }

    /// ReadModeAP indicates read policy for AP (availability) namespaces.
    /// Maps to the underlying consistency_level (ConsistencyOne/ConsistencyAll).
    pub fn get_read_mode_ap(&self) -> ReadModeAP {
        ReadModeAP {
            _as: self._as.base_policy.consistency_level.clone(),
        }
    }
    pub fn set_read_mode_ap(&mut self, read_mode_ap: ReadModeAP) {
        self._as.base_policy.consistency_level = read_mode_ap._as;
    }

    /// FilterExpression is the optional Filter Expression. Supported on Server v5.2+
    pub fn get_filter_expression(&self) -> Option<Expression> {
        self._as
            .base_policy
            .filter_expression
            .clone()
            .map(|fe| Expression { _as: fe })
    }
    pub fn set_filter_expression(&mut self, filter_expression: Option<Expression>) {
        self._as.base_policy.filter_expression = filter_expression.map(|fe| fe._as);
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  AdminPolicy
//
////////////////////////////////////////////////////////////////////////////////////////////

/// `AdminPolicy` encapsulates parameters for all admin operations.
#[php_class]
#[php(name = "Aerospike\\AdminPolicy")]
pub struct AdminPolicy {
    _as: aero::AdminPolicy,
}

#[php_impl]
impl AdminPolicy {
    pub fn __construct() -> Self {
        AdminPolicy::default()
    }

    /// User administration command socket timeout (milliseconds). Default: 3000.
    pub fn get_timeout(&self) -> u32 {
        self._as.timeout
    }
    pub fn set_timeout(&mut self, timeout_millis: u32) {
        self._as.timeout = timeout_millis;
    }
}

impl Default for AdminPolicy {
    fn default() -> Self {
        AdminPolicy {
            _as: aero::AdminPolicy { timeout: 3000 },
        }
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  InfoPolicy
//
////////////////////////////////////////////////////////////////////////////////////////////

/// `InfoPolicy` encapsulates parameters for all info-command operations.
/// (Standalone in v2 — aerospike-client-rust does not expose a dedicated info policy.)
#[php_class]
#[php(name = "Aerospike\\InfoPolicy")]
#[derive(Clone, Copy)]
pub struct InfoPolicy {
    pub timeout: u32,
}

#[php_impl]
impl InfoPolicy {
    pub fn __construct() -> Self {
        InfoPolicy::default()
    }
    pub fn get_timeout(&self) -> u32 {
        self.timeout
    }
    pub fn set_timeout(&mut self, timeout_millis: u32) {
        self.timeout = timeout_millis;
    }
}

impl Default for InfoPolicy {
    fn default() -> Self {
        InfoPolicy { timeout: 3000 }
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  WritePolicy
//
////////////////////////////////////////////////////////////////////////////////////////////

/// `WritePolicy` encapsulates parameters for all write operations.
///
/// v2 BREAKING: legacy fields `sleep_multiplier`, `use_compression`,
/// `exit_fast_on_exhausted_connection_pool`, `read_mode_sc` have been removed.
#[php_class]
#[php(name = "Aerospike\\WritePolicy")]
#[derive(Default)]
pub struct WritePolicy {
    _as: aero::WritePolicy,
}

#[php_impl]
impl WritePolicy {
    pub fn __construct() -> Self {
        let mut p = WritePolicy::default();
        if let Some(v) = ini_long_positive(&INI_WRITE_TIMEOUT) {
            p._as.base_policy.total_timeout = v;
        }
        p
    }

    /// RecordExistsAction qualifies how to handle writes where the record already exists.
    pub fn get_record_exists_action(&self) -> RecordExistsAction {
        RecordExistsAction {
            _as: self._as.record_exists_action.clone(),
        }
    }
    pub fn set_record_exists_action(&mut self, record_exists_action: RecordExistsAction) {
        self._as.record_exists_action = record_exists_action._as;
    }

    /// GenerationPolicy qualifies how to handle record writes based on record generation.
    pub fn get_generation_policy(&self) -> GenerationPolicy {
        GenerationPolicy {
            _as: self._as.generation_policy.clone(),
        }
    }
    pub fn set_generation_policy(&mut self, generation_policy: GenerationPolicy) {
        self._as.generation_policy = generation_policy._as;
    }

    /// Desired consistency guarantee when committing a transaction on the server.
    pub fn get_commit_level(&self) -> CommitLevel {
        CommitLevel {
            _as: self._as.commit_level.clone(),
        }
    }
    pub fn set_commit_level(&mut self, commit_level: CommitLevel) {
        self._as.commit_level = commit_level._as;
    }

    /// Generation: expected generation count when generation_policy is set.
    pub fn get_generation(&self) -> u32 {
        self._as.generation
    }
    pub fn set_generation(&mut self, generation: u32) {
        self._as.generation = generation;
    }

    /// Expiration / time-to-live for the record.
    pub fn get_expiration(&self) -> Expiration {
        Expiration {
            _as: self._as.expiration,
        }
    }
    pub fn set_expiration(&mut self, expiration: Expiration) {
        self._as.expiration = expiration._as;
    }

    /// RespondPerEachOp: return a result for every operation in an operate() call.
    pub fn get_respond_per_each_op(&self) -> bool {
        self._as.respond_per_each_op
    }
    pub fn set_respond_per_each_op(&mut self, respond_per_each_op: bool) {
        self._as.respond_per_each_op = respond_per_each_op;
    }

    /// DurableDelete leaves a tombstone for the record on deletion. Enterprise only.
    pub fn get_durable_delete(&self) -> bool {
        self._as.durable_delete
    }
    pub fn set_durable_delete(&mut self, durable_delete: bool) {
        self._as.durable_delete = durable_delete;
    }

    /// SendKey: store the user-defined key with the record on the server.
    pub fn get_send_key(&self) -> bool {
        self._as.send_key
    }
    pub fn set_send_key(&mut self, send_key: bool) {
        self._as.send_key = send_key;
    }

    // ----- base policy attributes -----
    pub fn get_max_retries(&self) -> u32 {
        self._as.base_policy.max_retries as u32
    }
    pub fn set_max_retries(&mut self, max_retries: u32) {
        self._as.base_policy.max_retries = max_retries as usize;
    }
    pub fn get_total_timeout(&self) -> u64 {
        u64::from(self._as.base_policy.total_timeout)
    }
    pub fn set_total_timeout(&mut self, timeout_millis: u64) -> PhpResult<()> {
        self._as.base_policy.total_timeout = millis_u64_to_u32(timeout_millis)?;
        Ok(())
    }
    pub fn get_socket_timeout(&self) -> u64 {
        u64::from(self._as.base_policy.socket_timeout)
    }
    pub fn set_socket_timeout(&mut self, timeout_millis: u64) -> PhpResult<()> {
        self._as.base_policy.socket_timeout = millis_u64_to_u32(timeout_millis)?;
        Ok(())
    }
    pub fn get_read_mode_ap(&self) -> ReadModeAP {
        ReadModeAP {
            _as: self._as.base_policy.consistency_level.clone(),
        }
    }
    pub fn set_read_mode_ap(&mut self, read_mode_ap: ReadModeAP) {
        self._as.base_policy.consistency_level = read_mode_ap._as;
    }
    pub fn get_filter_expression(&self) -> Option<Expression> {
        self._as
            .base_policy
            .filter_expression
            .clone()
            .map(|fe| Expression { _as: fe })
    }
    pub fn set_filter_expression(&mut self, filter_expression: Option<Expression>) {
        self._as.base_policy.filter_expression = filter_expression.map(|fe| fe._as);
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  QueryPolicy
//
////////////////////////////////////////////////////////////////////////////////////////////

/// QueryPolicy encapsulates parameters for policy attributes used in query operations.
///
/// v2 BREAKING: legacy fields `sleep_multiplier`, `send_key`, `use_compression`,
/// `exit_fast_on_exhausted_connection_pool`, `read_mode_sc` have been removed.
#[php_class]
#[php(name = "Aerospike\\QueryPolicy")]
#[derive(Default)]
pub struct QueryPolicy {
    _as: aero::QueryPolicy,
}

#[php_impl]
impl QueryPolicy {
    pub fn __construct() -> Self {
        QueryPolicy::default()
    }

    /// Expected query duration (Long, Short, LongRelaxAP). Server v6.0+.
    pub fn get_expected_duration(&self) -> QueryDuration {
        QueryDuration {
            _as: self._as.expected_duration.clone(),
        }
    }
    pub fn set_expected_duration(&mut self, expected_duration: QueryDuration) {
        self._as.expected_duration = expected_duration._as;
    }

    /// Maximum number of concurrent requests to server nodes.
    pub fn get_max_concurrent_nodes(&self) -> u32 {
        self._as.max_concurrent_nodes as u32
    }
    pub fn set_max_concurrent_nodes(&mut self, max_concurrent_nodes: u32) {
        self._as.max_concurrent_nodes = max_concurrent_nodes as usize;
    }

    /// Number of records to place in queue before blocking.
    pub fn get_record_queue_size(&self) -> u32 {
        self._as.record_queue_size as u32
    }
    pub fn set_record_queue_size(&mut self, record_queue_size: u32) {
        self._as.record_queue_size = record_queue_size as usize;
    }

    // ----- base policy attributes -----
    pub fn get_max_retries(&self) -> u32 {
        self._as.base_policy.max_retries as u32
    }
    pub fn set_max_retries(&mut self, max_retries: u32) {
        self._as.base_policy.max_retries = max_retries as usize;
    }
    pub fn get_total_timeout(&self) -> u64 {
        u64::from(self._as.base_policy.total_timeout)
    }
    pub fn set_total_timeout(&mut self, timeout_millis: u64) -> PhpResult<()> {
        self._as.base_policy.total_timeout = millis_u64_to_u32(timeout_millis)?;
        Ok(())
    }
    pub fn get_socket_timeout(&self) -> u64 {
        u64::from(self._as.base_policy.socket_timeout)
    }
    pub fn set_socket_timeout(&mut self, timeout_millis: u64) -> PhpResult<()> {
        self._as.base_policy.socket_timeout = millis_u64_to_u32(timeout_millis)?;
        Ok(())
    }
    pub fn get_read_mode_ap(&self) -> ReadModeAP {
        ReadModeAP {
            _as: self._as.base_policy.consistency_level.clone(),
        }
    }
    pub fn set_read_mode_ap(&mut self, read_mode_ap: ReadModeAP) {
        self._as.base_policy.consistency_level = read_mode_ap._as;
    }
    pub fn get_filter_expression(&self) -> Option<Expression> {
        self._as
            .base_policy
            .filter_expression
            .clone()
            .map(|fe| Expression { _as: fe })
    }
    pub fn set_filter_expression(&mut self, filter_expression: Option<Expression>) {
        self._as.base_policy.filter_expression = filter_expression.map(|fe| fe._as);
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  ScanPolicy
//
////////////////////////////////////////////////////////////////////////////////////////////

/// `ScanPolicy` encapsulates optional parameters used in scan operations.
///
/// v2 BREAKING: scan was unified into query in aerospike-client-rust v2.
/// `ScanPolicy` is now backed by `aerospike::QueryPolicy`. Legacy fields
/// `sleep_multiplier`, `send_key`, `use_compression`, `exit_fast_on_exhausted_connection_pool`,
/// `read_mode_sc` have been removed.
#[php_class]
#[php(name = "Aerospike\\ScanPolicy")]
pub struct ScanPolicy {
    _as: aero::QueryPolicy,
}

#[php_impl]
impl ScanPolicy {
    pub fn __construct() -> Self {
        ScanPolicy::default()
    }

    /// Number of records to scan per node (0 = no limit).
    pub fn get_max_records(&self) -> u64 {
        self._as.max_records
    }
    pub fn set_max_records(&mut self, max_records: u64) {
        self._as.max_records = max_records;
    }

    /// Maximum number of concurrent requests to server nodes.
    pub fn get_max_concurrent_nodes(&self) -> u32 {
        self._as.max_concurrent_nodes as u32
    }
    pub fn set_max_concurrent_nodes(&mut self, max_concurrent_nodes: u32) {
        self._as.max_concurrent_nodes = max_concurrent_nodes as usize;
    }

    /// Number of records to place in queue before blocking.
    pub fn get_record_queue_size(&self) -> u32 {
        self._as.record_queue_size as u32
    }
    pub fn set_record_queue_size(&mut self, record_queue_size: u32) {
        self._as.record_queue_size = record_queue_size as usize;
    }

    // ----- base policy attributes -----
    pub fn get_max_retries(&self) -> u32 {
        self._as.base_policy.max_retries as u32
    }
    pub fn set_max_retries(&mut self, max_retries: u32) {
        self._as.base_policy.max_retries = max_retries as usize;
    }
    pub fn get_total_timeout(&self) -> u64 {
        u64::from(self._as.base_policy.total_timeout)
    }
    pub fn set_total_timeout(&mut self, timeout_millis: u64) -> PhpResult<()> {
        self._as.base_policy.total_timeout = millis_u64_to_u32(timeout_millis)?;
        Ok(())
    }
    pub fn get_socket_timeout(&self) -> u64 {
        u64::from(self._as.base_policy.socket_timeout)
    }
    pub fn set_socket_timeout(&mut self, timeout_millis: u64) -> PhpResult<()> {
        self._as.base_policy.socket_timeout = millis_u64_to_u32(timeout_millis)?;
        Ok(())
    }
    pub fn get_read_mode_ap(&self) -> ReadModeAP {
        ReadModeAP {
            _as: self._as.base_policy.consistency_level.clone(),
        }
    }
    pub fn set_read_mode_ap(&mut self, read_mode_ap: ReadModeAP) {
        self._as.base_policy.consistency_level = read_mode_ap._as;
    }
    pub fn get_filter_expression(&self) -> Option<Expression> {
        self._as
            .base_policy
            .filter_expression
            .clone()
            .map(|fe| Expression { _as: fe })
    }
    pub fn set_filter_expression(&mut self, filter_expression: Option<Expression>) {
        self._as.base_policy.filter_expression = filter_expression.map(|fe| fe._as);
    }
}

impl Default for ScanPolicy {
    fn default() -> Self {
        let mut qp = aero::QueryPolicy::default();
        qp.base_policy.total_timeout = 0; // scans have no total timeout by default
        ScanPolicy { _as: qp }
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  IndexCollectionType
//
////////////////////////////////////////////////////////////////////////////////////////////

/// IndexCollectionType is the secondary index collection type.
#[php_class]
#[php(name = "Aerospike\\IndexCollectionType")]
#[derive(Clone)]
pub struct IndexCollectionType {
    _as: aero::CollectionIndexType,
}

#[php_impl]
impl IndexCollectionType {
    /// ICT_DEFAULT is the Normal scalar index.
    pub fn Default() -> Self {
        IndexCollectionType {
            _as: aero::CollectionIndexType::Default,
        }
    }

    /// ICT_LIST is Index list elements.
    pub fn List() -> Self {
        IndexCollectionType {
            _as: aero::CollectionIndexType::List,
        }
    }

    /// ICT_MAPKEYS is Index map keys.
    pub fn MapKeys() -> Self {
        IndexCollectionType {
            _as: aero::CollectionIndexType::MapKeys,
        }
    }

    /// ICT_MAPVALUES is Index map values.
    pub fn MapValues() -> Self {
        IndexCollectionType {
            _as: aero::CollectionIndexType::MapValues,
        }
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  ParticleType
//
////////////////////////////////////////////////////////////////////////////////////////////

/// Server particle types. Unsupported types are commented out.
#[php_class]
#[php(name = "Aerospike\\ParticleType")]
#[derive(Clone)]
pub struct ParticleType {
    _as: aero::ParticleType,
}

#[php_impl]
impl ParticleType {
    pub fn Null() -> Self {
        ParticleType {
            _as: aero::ParticleType::NULL,
        }
    }

    pub fn Integer() -> Self {
        ParticleType {
            _as: aero::ParticleType::INTEGER,
        }
    }

    pub fn Float() -> Self {
        ParticleType {
            _as: aero::ParticleType::FLOAT,
        }
    }

    pub fn String() -> Self {
        ParticleType {
            _as: aero::ParticleType::STRING,
        }
    }

    pub fn Blob() -> Self {
        ParticleType {
            _as: aero::ParticleType::BLOB,
        }
    }

    pub fn Digest() -> Self {
        ParticleType {
            _as: aero::ParticleType::DIGEST,
        }
    }

    pub fn Bool() -> Self {
        ParticleType {
            _as: aero::ParticleType::BOOL,
        }
    }

    pub fn Hll() -> Self {
        ParticleType {
            _as: aero::ParticleType::HLL,
        }
    }

    pub fn Map() -> Self {
        ParticleType {
            _as: aero::ParticleType::MAP,
        }
    }

    pub fn List() -> Self {
        ParticleType {
            _as: aero::ParticleType::LIST,
        }
    }

    pub fn Geo_Json() -> Self {
        ParticleType {
            _as: aero::ParticleType::GEOJSON,
        }
    }
}

impl From<ParticleType> for i64 {
    fn from(input: ParticleType) -> Self {
        match &input._as {
            aero::ParticleType::NULL => 0,
            aero::ParticleType::INTEGER => 1,
            aero::ParticleType::FLOAT => 2,
            aero::ParticleType::STRING => 3,
            aero::ParticleType::BLOB => 4,
            aero::ParticleType::DIGEST => 6,
            aero::ParticleType::BOOL => 17,
            aero::ParticleType::HLL => 18,
            aero::ParticleType::MAP => 19,
            aero::ParticleType::LIST => 20,
            aero::ParticleType::LDT => 21,
            aero::ParticleType::GEOJSON => 23,
        }
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  IndexType
//
////////////////////////////////////////////////////////////////////////////////////////////

/// IndexType the type of the secondary index.
#[php_class]
#[php(name = "Aerospike\\IndexType")]
#[derive(Clone)]
pub struct IndexType {
    _as: aero::IndexType,
}

#[php_impl]
impl IndexType {
    /// NUMERIC specifies an index on numeric values.
    pub fn Numeric() -> Self {
        IndexType {
            _as: aero::IndexType::Numeric,
        }
    }

    /// STRING specifies an index on string values.
    pub fn String() -> Self {
        IndexType {
            _as: aero::IndexType::String,
        }
    }

    /// GEO2DSPHERE specifies 2-dimensional spherical geospatial index.
    pub fn Geo2DSphere() -> Self {
        IndexType {
            _as: aero::IndexType::Geo2DSphere,
        }
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  Filter
//
////////////////////////////////////////////////////////////////////////////////////////////

/// Query filter definition. Currently, only one filter is allowed in a Statement, and must be on a
/// bin which has a secondary index defined.
#[php_class]
#[php(name = "Aerospike\\Filter")]
#[derive(Clone)]
pub struct Filter {
    _as: aero::query::Filter,
}

/// Apply an optional PHP CDT context to a freshly-built aero `Filter`.
fn filter_with_ctx(f: aero::query::Filter, ctx: Option<Vec<&CDTContext>>) -> aero::query::Filter {
    match ctx {
        Some(c) if !c.is_empty() => f.context(c.iter().map(|x| x._as.clone()).collect()),
        _ => f,
    }
}

#[php_impl]
impl Filter {
    /// Creates an equality filter for queries. Value can be an integer, string, or blob.
    /// Byte arrays are only supported on server v7+.
    pub fn equal(bin_name: &str, value: PHPValue, ctx: Option<Vec<&CDTContext>>) -> Self {
        let v: aero::Value = value.into();
        Filter {
            _as: filter_with_ctx(aero::query::Filter::equal(bin_name, v), ctx),
        }
    }

    /// Creates a range filter for queries. Only integer ranges are supported.
    pub fn range(
        bin_name: &str,
        begin: PHPValue,
        end: PHPValue,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Self {
        let b: aero::Value = begin.into();
        let e: aero::Value = end.into();
        Filter {
            _as: filter_with_ctx(aero::query::Filter::range(bin_name, b, e), ctx),
        }
    }

    /// Creates a contains filter for queries on a collection index.
    pub fn contains(
        bin_name: &str,
        value: PHPValue,
        cit: Option<&IndexCollectionType>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Self {
        let default = IndexCollectionType::Default();
        let cit = cit.unwrap_or(&default);
        let v: aero::Value = value.into();
        Filter {
            _as: filter_with_ctx(
                aero::query::Filter::contains(bin_name, v, cit._as.clone()),
                ctx,
            ),
        }
    }

    /// Creates a contains-range filter for queries on a collection index. Only integer values
    /// are supported.
    pub fn contains_range(
        bin_name: &str,
        begin: PHPValue,
        end: PHPValue,
        cit: Option<&IndexCollectionType>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Self {
        let default = IndexCollectionType::Default();
        let cit = cit.unwrap_or(&default);
        let b: aero::Value = begin.into();
        let e: aero::Value = end.into();
        Filter {
            _as: filter_with_ctx(
                aero::query::Filter::contains_range(bin_name, b, e, cit._as.clone()),
                ctx,
            ),
        }
    }

    /// Creates a geospatial "within region" filter for query. Argument must be a valid GeoJSON region.
    pub fn within_region(
        bin_name: &str,
        region: &str,
        cit: Option<&IndexCollectionType>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Self {
        let default = IndexCollectionType::Default();
        let cit = cit.unwrap_or(&default);
        Filter {
            _as: filter_with_ctx(
                aero::query::Filter::geo_within_region_cit(bin_name, region, cit._as.clone()),
                ctx,
            ),
        }
    }

    /// Creates a geospatial "within radius" filter for query.
    pub fn within_radius(
        bin_name: &str,
        lat: f64,
        lng: f64,
        radius: f64,
        cit: Option<&IndexCollectionType>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Self {
        let default = IndexCollectionType::Default();
        let cit = cit.unwrap_or(&default);
        Filter {
            _as: filter_with_ctx(
                aero::query::Filter::geo_within_radius_cit(
                    bin_name,
                    lng,
                    lat,
                    radius,
                    cit._as.clone(),
                ),
                ctx,
            ),
        }
    }

    /// Creates a geospatial "regions containing point" filter for query.
    pub fn regions_containing_point(
        bin_name: &str,
        lat: f64,
        lng: f64,
        cit: Option<&IndexCollectionType>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Self {
        let default = IndexCollectionType::Default();
        let cit = cit.unwrap_or(&default);
        let point = format!(r#"{{"type":"Point","coordinates":[{lng:.8},{lat:.8}]}}"#);
        Filter {
            _as: filter_with_ctx(
                aero::query::Filter::geo_contains_cit(bin_name, &point, cit._as.clone()),
                ctx,
            ),
        }
    }
}

impl FromZval<'_> for Filter {
    const TYPE: DataType = DataType::Mixed;

    fn from_zval(zval: &Zval) -> Option<Self> {
        let f: &Filter = zval.extract()?;

        Some(Filter { _as: f._as.clone() })
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  Statement
//
////////////////////////////////////////////////////////////////////////////////////////////

/// Statement encapsulates query statement parameters.
///
/// v2 BREAKING:
/// - `index_name` getter/setter removed. To target a specific secondary index, use the
///   `Filter::equal_by_index` / `Filter::range_by_index` / `Filter::contains_by_index` helpers
///   (not yet wrapped in PHP; will be added if needed).
/// - `return_data` toggle removed. Use `bin_names = Some(vec![])` for header-only reads
///   (maps to `aero::Bins::None`); `bin_names = None` returns all bins (`aero::Bins::All`);
///   non-empty `bin_names` returns the specified bins (`aero::Bins::Some(names)`).
/// - `task_id` removed; the aerospike v2 client manages it internally.
#[php_class]
#[php(name = "Aerospike\\Statement")]
#[derive(Clone)]
pub struct Statement {
    _as: aero::query::Statement,
}

#[php_impl]
impl Statement {
    pub fn __construct(
        namespace: &str,
        set_name: &str,
        filter: Option<Filter>,
        bin_names: Option<Vec<String>>,
    ) -> Self {
        let bins = match bin_names {
            None => aero::Bins::All,
            Some(names) if names.is_empty() => aero::Bins::None,
            Some(names) => aero::Bins::Some(names),
        };
        let mut stmt = aero::query::Statement::new(namespace, set_name, bins);
        if let Some(f) = filter {
            stmt.add_filter(f._as);
        }
        Statement { _as: stmt }
    }

    /// Query index filter (optional). Applied to the secondary index on query.
    /// Query index filters must reference a bin which has a secondary index defined.
    pub fn get_filter(&self) -> Option<Filter> {
        self._as
            .filters
            .as_ref()
            .and_then(|fs| fs.first().cloned())
            .map(|f| Filter { _as: f })
    }
    pub fn set_filter(&mut self, filter: Option<Filter>) {
        self._as.filters = filter.map(|f| vec![f._as]);
    }

    /// Bin names to return (optional). Empty Vec is treated as Bins::None (header-only).
    pub fn get_bin_names(&self) -> Vec<String> {
        match &self._as.bins {
            aero::Bins::Some(names) => names.clone(),
            _ => vec![],
        }
    }
    pub fn set_bin_names(&mut self, bin_names: Vec<String>) {
        self._as.bins = if bin_names.is_empty() {
            aero::Bins::None
        } else {
            aero::Bins::Some(bin_names)
        };
    }

    /// Query namespace.
    pub fn get_namespace(&self) -> String {
        self._as.namespace.clone()
    }
    pub fn set_namespace(&mut self, namespace: String) {
        self._as.namespace = namespace;
    }

    /// Query set name (optional).
    pub fn get_setname(&self) -> String {
        self._as.set_name.clone()
    }
    pub fn set_setname(&mut self, set_name: String) {
        self._as.set_name = set_name;
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  PartitionStatus
//
////////////////////////////////////////////////////////////////////////////////////////////

/// Status of a single partition during a scan/query. Used as a cursor.
///
/// v2 BREAKING: `bval` is now `Option<u64>` on the underlying aero type (was `Option<i64>` in
/// proto). The PHP getter is widened to `Option<i64>` via lossy cast for backward compat —
/// callers should expect non-negative values.
#[php_class]
#[php(name = "Aerospike\\PartitionStatus")]
pub struct PartitionStatus {
    _as: aero::query::PartitionStatus,
}

#[php_impl]
impl PartitionStatus {
    pub fn __construct(id: u32) -> Self {
        PartitionStatus {
            _as: aero::query::PartitionStatus {
                bval: None,
                id: id as u16,
                retry: true,
                digest: None,
                node: None,
                sequence: None,
            },
        }
    }

    /// Record's bval.
    pub fn get_bval(&self) -> Option<i64> {
        self._as.bval.map(|v| v as i64)
    }

    /// Partition id (0..4095).
    pub fn get_partition_id(&self) -> u32 {
        u32::from(self._as.id)
    }

    /// Digest of the last key seen on the server for this partition (empty if none).
    pub fn get_digest(&self) -> Vec<u8> {
        self._as.digest.map(|d| d.to_vec()).unwrap_or_default()
    }

    /// Whether the partition requires a retry.
    pub fn get_retry(&self) -> bool {
        self._as.retry
    }
}

impl FromZval<'_> for PartitionStatus {
    const TYPE: DataType = DataType::Mixed;

    fn from_zval(zval: &Zval) -> Option<Self> {
        let f: &PartitionStatus = zval.extract()?;

        Some(PartitionStatus {
            _as: aero::query::PartitionStatus {
                bval: f._as.bval,
                id: f._as.id,
                retry: f._as.retry,
                digest: f._as.digest,
                node: f._as.node.clone(),
                sequence: f._as.sequence,
            },
        })
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  PartitionFilter
//
////////////////////////////////////////////////////////////////////////////////////////////

/// Partition cursor for scan/query operations. Used to resume reads across calls.
///
/// v2 BREAKING:
/// - `get_partition_status()` and `init_partition_status()` removed. The aerospike v2 client
///   manages `partitions` internally during `client.query(...)`. To resume a query, reuse the
///   same `PartitionFilter` instance; the cursor state is preserved by the underlying
///   `aero::PartitionFilter` (via `AtomicBool` flags and internally-owned partition vec).
#[php_class]
#[php(name = "Aerospike\\PartitionFilter")]
pub struct PartitionFilter {
    _as: Arc<Mutex<aero::PartitionFilter>>,
}

#[php_impl]
impl PartitionFilter {
    pub fn __construct() -> Self {
        Self::all()
    }

    /// Creates a partition filter that reads all the partitions.
    pub fn all() -> Self {
        PartitionFilter {
            _as: Arc::new(Mutex::new(aero::PartitionFilter::all())),
        }
    }

    /// Creates a partition filter by a single partition id (0..4095).
    pub fn partition(id: u32) -> Self {
        PartitionFilter {
            _as: Arc::new(Mutex::new(aero::PartitionFilter::by_id(id as usize))),
        }
    }

    /// Creates a partition filter by a partition range. `begin` is in 0..4095; `count` is in 1..=4096.
    pub fn range(begin: u32, count: u32) -> Self {
        PartitionFilter {
            _as: Arc::new(Mutex::new(aero::PartitionFilter::by_range(
                begin as usize,
                count as usize,
            ))),
        }
    }
}

impl FromZval<'_> for PartitionFilter {
    const TYPE: DataType = DataType::Mixed;

    fn from_zval(zval: &Zval) -> Option<Self> {
        let f: &PartitionFilter = zval.extract()?;

        Some(PartitionFilter { _as: f._as.clone() })
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  Recordset
//
////////////////////////////////////////////////////////////////////////////////////////////

/// Virtual collection of records retrieved through queries and scans.
///
/// Wraps `aero::Recordset`, which manages a bounded queue between the client's background
/// node-reader tasks and the user thread. `next()` blocks until the next record is available
/// or the recordset is closed by the client (via `close()` or completion).
///
/// If the recordset was produced from a `PartitionFilter` (via `Client::scan`/`Client::query`),
/// the post-scan cursor is automatically written back into the original PHP `PartitionFilter`
/// when the stream is exhausted — so the next `scan`/`query` call with the same
/// `PartitionFilter` resumes where this one left off. This is how pagination works on v2.
#[php_class]
#[php(name = "Aerospike\\Recordset")]
#[derive(Default)]
pub struct Recordset {
    /// `None` only when this Recordset is a sentinel default returned together with a
    /// pending `AerospikeException` from `scan()`/`query()`. PHP never observes such a
    /// sentinel because the pending exception takes precedence.
    _as: Option<Arc<aero::Recordset>>,
    /// Original PHP `PartitionFilter` Arc. When the stream is exhausted, we extract the
    /// updated cursor from `aero::Recordset` and write it back here so the user's PHP
    /// `$pf` reflects progress and subsequent scans can resume.
    partition_filter: Option<Arc<Mutex<aero::PartitionFilter>>>,
    /// Whether we've already attempted to sync the partition filter back. The aero recordset
    /// only yields its filter once (it extracts from an internal tracker), so subsequent
    /// `next()` calls returning `None` would otherwise overwrite the filter with `None`.
    pf_synced: bool,
}

#[php_impl]
impl Recordset {
    /// Close the recordset. Background tasks finish at their next safe point.
    pub fn close(&mut self) {
        if let Some(rs) = self._as.as_ref() {
            rs.close();
        }
    }

    /// Returns true if the operation hasn't been finished or cancelled.
    pub fn get_active(&self) -> bool {
        self._as.as_ref().map(|rs| rs.is_active()).unwrap_or(false)
    }

    /// Returns the next record from the queue, blocking until a record arrives or the
    /// recordset closes. Returns `None` when the stream is exhausted; throws an
    /// `AerospikeException` on read failure.
    ///
    /// Uses the canonical `Iterator for &aero::Recordset` implementation which yields the
    /// tokio scheduler between checks via `futures::executor::block_on(yield_now())` —
    /// no 1ms `thread::sleep` busy-wait. On end-of-stream the cursor inside the originating
    /// `PartitionFilter` is updated so paginated scans resume from the last digest.
    pub fn next(&mut self) -> PhpResult<Option<Record>> {
        let Some(rs) = self._as.clone() else {
            return Ok(None);
        };
        let _guard = TOKIO_RT.enter();
        // `Iterator for &Recordset` requires a mutable reference to the `&Recordset` itself.
        let recordset: &aero::Recordset = &rs;
        let mut iter: &aero::Recordset = recordset;
        match Iterator::next(&mut iter) {
            Some(Ok(r)) => Ok(Some(Record { _as: r })),
            Some(Err(e)) => throw_aero_error(&e, None),
            None => {
                self.sync_partition_filter_back();
                Ok(None)
            }
        }
    }
}

impl Recordset {
    /// Copy the post-scan partition cursor from the underlying `aero::Recordset` into the
    /// originating PHP `PartitionFilter` wrapper. Called exactly once on first end-of-stream.
    fn sync_partition_filter_back(&mut self) {
        if self.pf_synced {
            return;
        }
        self.pf_synced = true;
        let Some(pf_arc) = self.partition_filter.as_ref() else {
            return;
        };
        let Some(rs) = self._as.as_ref() else {
            return;
        };
        // `aero::Recordset::partition_filter` is async; we're already inside `TOKIO_RT.enter()`
        // when called from `next()`, but `block_on` requires an explicit handle.
        let updated = TOKIO_RT.block_on(rs.partition_filter());
        if let Some(new_pf) = updated {
            if let Ok(mut guard) = pf_arc.lock() {
                *guard = new_pf;
            }
        }
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  Bin
//
////////////////////////////////////////////////////////////////////////////////////////////

/// Container object for a record bin, comprising a name and a value.
#[php_class]
#[php(name = "Aerospike\\Bin")]
#[derive(Debug)]
pub struct Bin {
    _as: aero::Bin,
}

#[php_impl]
impl Bin {
    pub fn __construct(name: &str, value: &Zval) -> PhpResult<Self> {
        let v_op: Option<PHPValue> = from_zval(value);
        match v_op {
            Some(v) => Ok(Bin {
                _as: aero::Bin::new(name.to_string(), v.into()),
            }),
            _ => Err("Invalid input for argument `value`".to_string().into()),
        }
    }

    /// Bin name.
    pub fn get_name(&self) -> String {
        self._as.name.clone()
    }

    /// Bin value.
    pub fn get_value(&self) -> PHPValue {
        self._as.value.clone().into()
    }

    /// v1 compatibility shim: forwards `$bin->name`, `->value` to the getters.
    pub fn __get(&self, name: &str) -> PhpResult<Zval> {
        let mut zv = Zval::new();
        match name {
            "name" => zv.set_string(&self.get_name(), false)?,
            "value" => self.get_value().set_zval(&mut zv, false)?,
            _ => zv.set_null(),
        }
        Ok(zv)
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  Record
//
////////////////////////////////////////////////////////////////////////////////////////////

/// Container object for a database record.
#[php_class]
#[php(name = "Aerospike\\Record")]
pub struct Record {
    _as: aero::Record,
}

#[php_impl]
impl Record {
    /// v1 compatibility shim: forwards `$record->bins`, `->generation`, `->ttl`,
    /// `->expiration`, `->key` to the corresponding getters. New code should call the
    /// explicit `getX()` methods.
    pub fn __get(&self, name: &str) -> PhpResult<Zval> {
        let mut zv = Zval::new();
        match name {
            "bins" => {
                if let Some(b) = self.get_bins() {
                    b.set_zval(&mut zv, false)?;
                } else {
                    zv.set_null();
                }
            }
            "generation" => match self.get_generation() {
                Some(g) => zv.set_long(g as i64),
                None => zv.set_null(),
            },
            "ttl" => match self.get_ttl() {
                Some(t) => zv.set_long(t as i64),
                None => zv.set_null(),
            },
            "key" => match self.get_key() {
                Some(k) => {
                    let zo: ZBox<ZendObject> = k.into_zend_object()?;
                    zo.set_zval(&mut zv, false)?;
                }
                None => zv.set_null(),
            },
            _ => zv.set_null(),
        }
        Ok(zv)
    }

    /// Bins is the map of requested name/value bins.
    pub fn bin(&self, name: &str) -> Option<PHPValue> {
        self._as.bins.get(name).map(|v| v.clone().into())
    }

    /// Bins is the map of requested name/value bins.
    pub fn get_bins(&self) -> Option<PHPValue> {
        Some(self._as.bins.clone().into())
    }

    /// Generation shows record modification count.
    pub fn get_generation(&self) -> Option<u32> {
        Some(self._as.generation)
    }

    /// Expiration indicates when a record will expire (Time-To-Live).
    /// Returns the remaining TTL in seconds, or `Never` if the record never expires.
    pub fn get_expiration(&self) -> Expiration {
        match self._as.time_to_live() {
            None => Expiration::Never(),
            Some(d) => Expiration::Seconds(d.as_secs() as u32),
        }
    }

    /// Absolute Unix timestamp (in seconds since epoch) when this record will expire.
    /// Returns `null` if the record never expires. v1-compatible.
    ///
    /// For the remaining TTL in seconds, use `getRemainingTtl()`.
    pub fn get_ttl(&self) -> Option<u32> {
        self._as.time_to_live().map(|d| {
            let now = std::time::SystemTime::now()
                .duration_since(std::time::UNIX_EPOCH)
                .map(|n| n.as_secs() as u32)
                .unwrap_or(0);
            now.saturating_add(d.as_secs() as u32)
        })
    }

    /// Remaining TTL in seconds (positive integer), or `null` if the record never expires.
    /// Equivalent to `$this->getExpiration()->getTtl()`.
    pub fn get_remaining_ttl(&self) -> Option<u32> {
        self.get_expiration().get_ttl()
    }

    /// Key is the record's key.
    /// Might be empty, or may only consist of digest value.
    pub fn get_key(&self) -> Option<Key> {
        self._as.key.clone().map(|k| Key { _as: k })
    }
}

impl FromZval<'_> for Record {
    const TYPE: DataType = DataType::Mixed;

    fn from_zval(zval: &Zval) -> Option<Self> {
        let f: &Record = zval.extract()?;

        Some(Record { _as: f._as.clone() })
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  BatchPolicy
//
////////////////////////////////////////////////////////////////////////////////////////////

/// BatchPolicy encapsulates parameters for batch operations.
///
/// v2 BREAKING: legacy fields `sleep_multiplier`, `send_key`, `use_compression`,
/// `exit_fast_on_exhausted_connection_pool`, `read_mode_sc` have been removed.
/// `concurrent_nodes` was renamed to `concurrency` (enum Sequential/Parallel).
/// `allow_partial_results` has been removed (controlled via `respond_all_keys`).
#[php_class]
#[php(name = "Aerospike\\BatchPolicy")]
#[derive(Debug)]
pub struct BatchPolicy {
    _as: aero::BatchPolicy,
}

#[php_impl]
impl BatchPolicy {
    pub fn __construct() -> Self {
        BatchPolicy::default()
    }

    /// Allow batch to be processed immediately in the server's receiving thread.
    pub fn get_allow_inline(&self) -> bool {
        self._as.allow_inline
    }
    pub fn set_allow_inline(&mut self, allow_inline: bool) {
        self._as.allow_inline = allow_inline;
    }

    /// Allow batch to be processed immediately in the server's receiving thread for SSD namespaces.
    pub fn get_allow_inline_ssd(&self) -> bool {
        self._as.allow_inline_ssd
    }
    pub fn set_allow_inline_ssd(&mut self, allow_inline_ssd: bool) {
        self._as.allow_inline_ssd = allow_inline_ssd;
    }

    /// Should all batch keys be attempted regardless of errors.
    pub fn get_respond_all_keys(&self) -> bool {
        self._as.respond_all_keys
    }
    pub fn set_respond_all_keys(&mut self, respond_all_keys: bool) {
        self._as.respond_all_keys = respond_all_keys;
    }

    /// v1 compatibility: set concurrency by node count. aerospike-rust 2.x dropped the
    /// per-thread limit, so values map to `Sequential` (n ≤ 1) or `Parallel` (n > 1).
    /// For explicit control over the typed enum, use `setConcurrency()`.
    pub fn set_concurrent_nodes(&mut self, n: u32) {
        self._as.concurrency = if n <= 1 {
            aero::Concurrency::Sequential
        } else {
            aero::Concurrency::Parallel
        };
    }
    /// v1 compatibility: returns 1 for `Sequential`, 0 for `Parallel` (matching the
    /// historical semantics of `concurrent_nodes`: 1 = serial, 0 = unbounded).
    pub fn get_concurrent_nodes(&self) -> u32 {
        match self._as.concurrency {
            aero::Concurrency::Sequential => 1,
            aero::Concurrency::Parallel => 0,
        }
    }

    /// Set concurrency strategy via the typed `Concurrency` wrapper. Prefer this over
    /// `setConcurrentNodes()` in new code.
    pub fn set_concurrency(&mut self, c: &Concurrency) {
        self._as.concurrency = match c.v {
            _Concurrency::Sequential => aero::Concurrency::Sequential,
            _Concurrency::Parallel | _Concurrency::MaxThreads(_) => aero::Concurrency::Parallel,
        };
    }
    pub fn get_concurrency(&self) -> Concurrency {
        match self._as.concurrency {
            aero::Concurrency::Sequential => Concurrency::Sequential(),
            aero::Concurrency::Parallel => Concurrency::Parallel(),
        }
    }

    // ----- base policy attributes -----
    pub fn get_max_retries(&self) -> u32 {
        self._as.base_policy.max_retries as u32
    }
    pub fn set_max_retries(&mut self, max_retries: u32) {
        self._as.base_policy.max_retries = max_retries as usize;
    }
    pub fn get_total_timeout(&self) -> u64 {
        u64::from(self._as.base_policy.total_timeout)
    }
    pub fn set_total_timeout(&mut self, timeout_millis: u64) -> PhpResult<()> {
        self._as.base_policy.total_timeout = millis_u64_to_u32(timeout_millis)?;
        Ok(())
    }
    pub fn get_socket_timeout(&self) -> u64 {
        u64::from(self._as.base_policy.socket_timeout)
    }
    pub fn set_socket_timeout(&mut self, timeout_millis: u64) -> PhpResult<()> {
        self._as.base_policy.socket_timeout = millis_u64_to_u32(timeout_millis)?;
        Ok(())
    }
    pub fn get_read_mode_ap(&self) -> ReadModeAP {
        ReadModeAP {
            _as: self._as.base_policy.consistency_level.clone(),
        }
    }
    pub fn set_read_mode_ap(&mut self, read_mode_ap: ReadModeAP) {
        self._as.base_policy.consistency_level = read_mode_ap._as;
    }
    pub fn get_filter_expression(&self) -> Option<Expression> {
        self._as
            .filter_expression
            .clone()
            .map(|fe| Expression { _as: fe })
    }
    pub fn set_filter_expression(&mut self, filter_expression: Option<Expression>) {
        self._as.filter_expression = filter_expression.map(|fe| fe._as);
    }
}

impl Default for BatchPolicy {
    fn default() -> Self {
        BatchPolicy {
            _as: aero::BatchPolicy {
                base_policy: aero::policy::BasePolicy::default(),
                concurrency: aero::Concurrency::Sequential,
                allow_inline: true,
                allow_inline_ssd: false,
                respond_all_keys: true,
                filter_expression: None,
                replica: Default::default(),
            },
        }
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  BatchReadPolicy
//
////////////////////////////////////////////////////////////////////////////////////////////

/// BatchReadPolicy attributes used in batch read commands.
#[php_class]
#[php(name = "Aerospike\\BatchReadPolicy")]
#[derive(Default)]
pub struct BatchReadPolicy {
    _as: aero::BatchReadPolicy,
}

#[php_impl]
impl BatchReadPolicy {
    pub fn __construct() -> Self {
        BatchReadPolicy::default()
    }

    /// Read-touch-TTL percent (0=server default, -1=don't reset, 1-100=percentage).
    pub fn get_read_touch_ttl_percent(&self) -> i32 {
        match self._as.read_touch_ttl {
            aero::policy::ReadTouchTTL::Percent(p) => i32::from(p),
            aero::policy::ReadTouchTTL::ServerDefault => 0,
            aero::policy::ReadTouchTTL::DontReset => -1,
        }
    }
    pub fn set_read_touch_ttl_percent(&mut self, percent: i32) {
        self._as.read_touch_ttl = match percent {
            0 => aero::policy::ReadTouchTTL::ServerDefault,
            -1 => aero::policy::ReadTouchTTL::DontReset,
            p if (1..=100).contains(&p) => aero::policy::ReadTouchTTL::Percent(p as u8),
            _ => aero::policy::ReadTouchTTL::ServerDefault,
        };
    }
    pub fn get_filter_expression(&self) -> Option<Expression> {
        self._as
            .filter_expression
            .clone()
            .map(|fe| Expression { _as: fe })
    }
    pub fn set_filter_expression(&mut self, filter_expression: Option<Expression>) {
        self._as.filter_expression = filter_expression.map(|fe| fe._as);
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  BatchWritePolicy
//
////////////////////////////////////////////////////////////////////////////////////////////

/// BatchWritePolicy attributes used in batch write commands.
#[php_class]
#[php(name = "Aerospike\\BatchWritePolicy")]
#[derive(Default)]
pub struct BatchWritePolicy {
    _as: aero::BatchWritePolicy,
}

#[php_impl]
impl BatchWritePolicy {
    pub fn __construct() -> Self {
        BatchWritePolicy::default()
    }
    pub fn get_record_exists_action(&self) -> RecordExistsAction {
        RecordExistsAction {
            _as: self._as.record_exists_action.clone(),
        }
    }
    pub fn set_record_exists_action(&mut self, record_exists_action: RecordExistsAction) {
        self._as.record_exists_action = record_exists_action._as;
    }
    pub fn get_generation_policy(&self) -> GenerationPolicy {
        GenerationPolicy {
            _as: self._as.generation_policy.clone(),
        }
    }
    pub fn set_generation_policy(&mut self, generation_policy: GenerationPolicy) {
        self._as.generation_policy = generation_policy._as;
    }
    pub fn get_commit_level(&self) -> CommitLevel {
        CommitLevel {
            _as: self._as.commit_level.clone(),
        }
    }
    pub fn set_commit_level(&mut self, commit_level: CommitLevel) {
        self._as.commit_level = commit_level._as;
    }
    pub fn get_generation(&self) -> u32 {
        self._as.generation
    }
    pub fn set_generation(&mut self, generation: u32) {
        self._as.generation = generation;
    }
    pub fn get_expiration(&self) -> Expiration {
        Expiration {
            _as: self._as.expiration,
        }
    }
    pub fn set_expiration(&mut self, expiration: Expiration) {
        self._as.expiration = expiration._as;
    }
    pub fn get_send_key(&self) -> bool {
        self._as.send_key
    }
    pub fn set_send_key(&mut self, send_key: bool) {
        self._as.send_key = send_key;
    }
    pub fn get_durable_delete(&self) -> bool {
        self._as.durable_delete
    }
    pub fn set_durable_delete(&mut self, durable_delete: bool) {
        self._as.durable_delete = durable_delete;
    }
    pub fn get_filter_expression(&self) -> Option<Expression> {
        self._as
            .filter_expression
            .clone()
            .map(|fe| Expression { _as: fe })
    }
    pub fn set_filter_expression(&mut self, filter_expression: Option<Expression>) {
        self._as.filter_expression = filter_expression.map(|fe| fe._as);
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  BatchDeletePolicy
//
////////////////////////////////////////////////////////////////////////////////////////////

/// BatchDeletePolicy attributes used in batch delete commands.
#[php_class]
#[php(name = "Aerospike\\BatchDeletePolicy")]
#[derive(Default)]
pub struct BatchDeletePolicy {
    _as: aero::BatchDeletePolicy,
}

#[php_impl]
impl BatchDeletePolicy {
    pub fn __construct() -> Self {
        BatchDeletePolicy::default()
    }
    pub fn get_generation_policy(&self) -> GenerationPolicy {
        GenerationPolicy {
            _as: self._as.generation_policy.clone(),
        }
    }
    pub fn set_generation_policy(&mut self, generation_policy: GenerationPolicy) {
        self._as.generation_policy = generation_policy._as;
    }
    pub fn get_commit_level(&self) -> CommitLevel {
        CommitLevel {
            _as: self._as.commit_level.clone(),
        }
    }
    pub fn set_commit_level(&mut self, commit_level: CommitLevel) {
        self._as.commit_level = commit_level._as;
    }
    pub fn get_generation(&self) -> u32 {
        self._as.generation
    }
    pub fn set_generation(&mut self, generation: u32) {
        self._as.generation = generation;
    }
    pub fn get_send_key(&self) -> bool {
        self._as.send_key
    }
    pub fn set_send_key(&mut self, send_key: bool) {
        self._as.send_key = send_key;
    }
    pub fn get_durable_delete(&self) -> bool {
        self._as.durable_delete
    }
    pub fn set_durable_delete(&mut self, durable_delete: bool) {
        self._as.durable_delete = durable_delete;
    }
    pub fn get_filter_expression(&self) -> Option<Expression> {
        self._as
            .filter_expression
            .clone()
            .map(|fe| Expression { _as: fe })
    }
    pub fn set_filter_expression(&mut self, filter_expression: Option<Expression>) {
        self._as.filter_expression = filter_expression.map(|fe| fe._as);
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  BatchUdfPolicy
//
////////////////////////////////////////////////////////////////////////////////////////////

/// BatchUdfPolicy attributes used in batch UDF commands.
#[php_class]
#[php(name = "Aerospike\\BatchUdfPolicy")]
#[derive(Default)]
pub struct BatchUdfPolicy {
    _as: aero::BatchUDFPolicy,
}

#[php_impl]
impl BatchUdfPolicy {
    pub fn __construct() -> Self {
        BatchUdfPolicy::default()
    }
    pub fn get_commit_level(&self) -> CommitLevel {
        CommitLevel {
            _as: self._as.commit_level.clone(),
        }
    }
    pub fn set_commit_level(&mut self, commit_level: CommitLevel) {
        self._as.commit_level = commit_level._as;
    }
    pub fn get_expiration(&self) -> Expiration {
        Expiration {
            _as: self._as.expiration,
        }
    }
    pub fn set_expiration(&mut self, expiration: Expiration) {
        self._as.expiration = expiration._as;
    }
    pub fn get_send_key(&self) -> bool {
        self._as.send_key
    }
    pub fn set_send_key(&mut self, send_key: bool) {
        self._as.send_key = send_key;
    }
    pub fn get_durable_delete(&self) -> bool {
        self._as.durable_delete
    }
    pub fn set_durable_delete(&mut self, durable_delete: bool) {
        self._as.durable_delete = durable_delete;
    }
    pub fn get_filter_expression(&self) -> Option<Expression> {
        self._as
            .filter_expression
            .clone()
            .map(|fe| Expression { _as: fe })
    }
    pub fn set_filter_expression(&mut self, filter_expression: Option<Expression>) {
        self._as.filter_expression = filter_expression.map(|fe| fe._as);
    }
}

//////////////////////////////////////////////////////////////////////////////////////////
//
//  Operation
//
//////////////////////////////////////////////////////////////////////////////////////////

/// OperationType determines operation type
#[php_class]
#[php(name = "Aerospike\\Operation")]
#[derive(Clone)]
pub struct Operation {
    _as: aero::operations::Operation,
}

#[php_impl]
impl Operation {
    /// read bin database operation. When `bin_name` is `None`, reads all bins.
    pub fn get(bin_name: Option<String>) -> Self {
        let op = match bin_name {
            Some(name) => aero::operations::get_bin(&name),
            None => aero::operations::get(),
        };
        Operation { _as: op }
    }

    /// read record header database operation.
    pub fn get_header() -> Self {
        Operation {
            _as: aero::operations::get_header(),
        }
    }

    /// set database operation.
    pub fn put(bin: &Bin) -> Self {
        Operation {
            _as: aero::operations::put(&bin._as),
        }
    }

    /// string append database operation.
    pub fn append(bin: &Bin) -> Self {
        Operation {
            _as: aero::operations::append(&bin._as),
        }
    }

    /// string prepend database operation.
    pub fn prepend(bin: &Bin) -> Self {
        Operation {
            _as: aero::operations::prepend(&bin._as),
        }
    }

    /// integer add database operation.
    pub fn add(bin: &Bin) -> Self {
        Operation {
            _as: aero::operations::add(&bin._as),
        }
    }

    /// touch record database operation.
    pub fn touch() -> Self {
        Operation {
            _as: aero::operations::touch(),
        }
    }

    /// delete record database operation.
    pub fn delete() -> Self {
        Operation {
            _as: aero::operations::delete(),
        }
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  BatchRecord
//
////////////////////////////////////////////////////////////////////////////////////////////

/// Encapsulates a Batch key and the record result populated after a batch command completes.
///
/// Constructed only by the client when reading batch results back from the server. Field
/// shape mirrors `aero::BatchRecord`.
#[php_class]
#[php(name = "Aerospike\\BatchRecord")]
#[derive(Debug, Clone)]
pub struct BatchRecord {
    _as: aero::BatchRecord,
}

#[php_impl]
impl BatchRecord {
    /// Record's key.
    pub fn get_key(&self) -> Option<Key> {
        Some(Key {
            _as: self._as.key.clone(),
        })
    }

    /// Record result. `None` when the record was not found or an error occurred. See ResultCode.
    pub fn get_record(&self) -> Option<Record> {
        self._as.record.clone().map(|r| Record { _as: r })
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  BatchRead
//
////////////////////////////////////////////////////////////////////////////////////////////

/// BatchRead specifies the Key and bin names used in batch read commands
/// where variable bins are needed for each key.
///
/// Maps to `aero::BatchOperation::Read`. `bins` semantics:
/// - `None` → header only (`Bins::None`)
/// - `Some([])` → read all bins (`Bins::All`)
/// - `Some(names)` → read specified bins (`Bins::Some(names)`)
#[php_class]
#[php(name = "Aerospike\\BatchRead")]
#[derive(Debug, Clone)]
pub struct BatchRead {
    _as: aero::BatchOperation,
}

#[php_impl]
impl BatchRead {
    pub fn __construct(policy: &BatchReadPolicy, key: &Key, bins: Option<Vec<String>>) -> Self {
        let bins = match bins {
            None => aero::Bins::None,
            Some(names) if names.is_empty() => aero::Bins::All,
            Some(names) => aero::Bins::Some(names),
        };
        BatchRead {
            _as: aero::BatchOperation::read(&policy._as, key._as.clone(), bins),
        }
    }

    /// Specifies the read-only operations to perform for the key. Mutually exclusive with `bins`.
    /// A bin name can be emulated with `Operation::get(Some("bin"))`. Supported by server v5.6.0+.
    pub fn ops(policy: &BatchReadPolicy, key: &Key, ops: Vec<&Operation>) -> Self {
        let aero_ops: Vec<aero::operations::Operation> =
            ops.iter().map(|o| o._as.clone()).collect();
        BatchRead {
            _as: aero::BatchOperation::read_ops(&policy._as, key._as.clone(), aero_ops),
        }
    }

    /// Read record header only (no bins).
    pub fn header(policy: &BatchReadPolicy, key: &Key) -> Self {
        BatchRead {
            _as: aero::BatchOperation::read(&policy._as, key._as.clone(), aero::Bins::None),
        }
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  BatchWrite
//
////////////////////////////////////////////////////////////////////////////////////////////

/// BatchWrite encapsulates a batch key and read/write operations with write policy.
/// Maps to `aero::BatchOperation::Write`.
#[php_class]
#[php(name = "Aerospike\\BatchWrite")]
#[derive(Debug, Clone)]
pub struct BatchWrite {
    _as: aero::BatchOperation,
}

#[php_impl]
impl BatchWrite {
    pub fn __construct(policy: &BatchWritePolicy, key: &Key, ops: Vec<&Operation>) -> Self {
        let aero_ops: Vec<aero::operations::Operation> =
            ops.iter().map(|o| o._as.clone()).collect();
        BatchWrite {
            _as: aero::BatchOperation::write(&policy._as, key._as.clone(), aero_ops),
        }
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  BatchDelete
//
////////////////////////////////////////////////////////////////////////////////////////////

/// BatchDelete encapsulates a batch delete operation. Maps to `aero::BatchOperation::Delete`.
#[php_class]
#[php(name = "Aerospike\\BatchDelete")]
#[derive(Debug, Clone)]
pub struct BatchDelete {
    _as: aero::BatchOperation,
}

#[php_impl]
impl BatchDelete {
    pub fn __construct(policy: &BatchDeletePolicy, key: &Key) -> Self {
        BatchDelete {
            _as: aero::BatchOperation::delete(&policy._as, key._as.clone()),
        }
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  BatchUdf
//
////////////////////////////////////////////////////////////////////////////////////////////

/// BatchUDF encapsulates a batch user-defined-function operation.
/// Maps to `aero::BatchOperation::UDF`.
#[php_class]
#[php(name = "Aerospike\\BatchUdf")]
#[derive(Debug, Clone)]
pub struct BatchUdf {
    _as: aero::BatchOperation,
}

#[php_impl]
impl BatchUdf {
    pub fn __construct(
        policy: &BatchUdfPolicy,
        key: &Key,
        package_name: String,
        function_name: String,
        function_args: Vec<PHPValue>,
    ) -> Self {
        let args: Option<Vec<aero::Value>> = if function_args.is_empty() {
            None
        } else {
            Some(function_args.into_iter().map(Into::into).collect())
        };
        BatchUdf {
            _as: aero::BatchOperation::udf(
                &policy._as,
                key._as.clone(),
                &package_name,
                &function_name,
                args,
            ),
        }
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  UdfLanguage
//
////////////////////////////////////////////////////////////////////////////////////////////

/// `UdfLanguage` determines how to handle record writes based on record generation.
#[php_class]
#[php(name = "Aerospike\\UdfLanguage")]
pub struct UdfLanguage {
    _as: aero::UDFLang,
}

impl FromZval<'_> for UdfLanguage {
    const TYPE: DataType = DataType::Mixed;

    fn from_zval(zval: &Zval) -> Option<Self> {
        // UDFLang has only Lua and is not Clone; we just verify the zval is a UdfLanguage.
        let _ = zval.extract::<&UdfLanguage>()?;
        Some(UdfLanguage {
            _as: aero::UDFLang::Lua,
        })
    }
}

#[php_impl]
impl UdfLanguage {
    /// lua language.
    pub fn Lua() -> Self {
        UdfLanguage {
            _as: aero::UDFLang::Lua,
        }
    }
}

impl From<i32> for UdfLanguage {
    fn from(input: i32) -> Self {
        match input {
            0 => Self::Lua(),
            _ => unreachable!(),
        }
    }
}

impl From<UdfLanguage> for i32 {
    fn from(input: UdfLanguage) -> Self {
        match input._as {
            aero::UDFLang::Lua => 0,
        }
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  UdfMeta
//
////////////////////////////////////////////////////////////////////////////////////////////

/// Represents UDF (User-Defined Function) metadata for Aerospike.
/// Standalone struct — aerospike-core v2 does not expose a UdfMeta type directly.
/// UDF listing will be implemented later via the Info command.
#[php_class]
#[php(name = "Aerospike\\UdfMeta")]
#[derive(Debug, PartialEq, Clone)]
pub struct UdfMeta {
    pub package_name: String,
    pub hash: String,
    /// Language string, e.g. "lua".
    pub language: String,
}

#[php_impl]
impl UdfMeta {
    /// v1 compatibility shim: forwards `$udf->packageName`, `->hash`, `->language` to
    /// the corresponding getters.
    pub fn __get(&self, name: &str) -> PhpResult<Zval> {
        let mut zv = Zval::new();
        match name {
            "packageName" | "package_name" => zv.set_string(&self.get_package_name(), false)?,
            "hash" => zv.set_string(&self.get_hash(), false)?,
            "language" => {
                let lang = self.get_language();
                let zo: ZBox<ZendObject> = lang.into_zend_object()?;
                zo.set_zval(&mut zv, false)?;
            }
            _ => zv.set_null(),
        }
        Ok(zv)
    }

    /// Getter method to retrieve the package name of the UDF.
    pub fn get_package_name(&self) -> String {
        self.package_name.clone()
    }

    /// Getter method to retrieve the hash of the UDF.
    pub fn get_hash(&self) -> String {
        self.hash.clone()
    }

    /// Getter method to retrieve the language of the UDF.
    /// v1-compatible: returns a `UdfLanguage` enum. Today aero::UDFLang has only `Lua`,
    /// so the parsed language string is always mapped to `UdfLanguage::Lua()`.
    pub fn get_language(&self) -> UdfLanguage {
        UdfLanguage::Lua()
    }
}

impl FromZval<'_> for UdfMeta {
    const TYPE: DataType = DataType::Mixed;

    fn from_zval(zval: &Zval) -> Option<Self> {
        let f: &UdfMeta = zval.extract()?;

        Some(f.clone())
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  UserRole
//
////////////////////////////////////////////////////////////////////////////////////////////

/// UserRole contains information about a user and their assigned roles.
/// Wraps `aerospike::User` (aerospike-core v2).
///
/// v2 NOTE: `read_info` and `write_info` are `Vec<u32>` in aerospike-core (not u64).
/// The PHP getters return `Vec<u64>` for backward compatibility (widening cast).
/// `conns_in_use` is `u32` in aerospike-core; the PHP getter returns `u64` for
/// backward compatibility.
#[php_class]
#[php(name = "Aerospike\\UserRole")]
#[derive(Debug, PartialEq, Clone)]
pub struct UserRole {
    _as: aero::User,
}

#[php_impl]
impl UserRole {
    /// User name.
    /// NOTE: aerospike-core v2 stores the user name in the `user` field (not `name`).
    pub fn get_user(&self) -> String {
        self._as.user.clone()
    }

    /// Roles is a list of assigned roles.
    pub fn get_roles(&self) -> Vec<String> {
        self._as.roles.clone()
    }

    /// ReadInfo is the list of read statistics. List may be nil.
    /// Current statistics by offset are:
    ///
    /// 0: read quota in records per second
    /// 1: single record read transaction rate (TPS)
    /// 2: read scan/query record per second rate (RPS)
    /// 3: number of limitless read scans/queries
    ///
    /// Future server releases may add additional statistics.
    pub fn get_read_info(&self) -> Vec<u64> {
        self._as.read_info.iter().map(|&v| v as u64).collect()
    }

    /// WriteInfo is the list of write statistics. List may be nil.
    /// Current statistics by offset are:
    ///
    /// 0: write quota in records per second
    /// 1: single record write transaction rate (TPS)
    /// 2: write scan/query record per second rate (RPS)
    /// 3: number of limitless write scans/queries
    ///
    /// Future server releases may add additional statistics.
    pub fn get_write_info(&self) -> Vec<u64> {
        self._as.write_info.iter().map(|&v| v as u64).collect()
    }

    /// ConnsInUse is the number of currently open connections for the user.
    pub fn get_conns_in_use(&self) -> u64 {
        self._as.conns_in_use as u64
    }
}

impl From<aero::User> for UserRole {
    fn from(input: aero::User) -> Self {
        UserRole { _as: input }
    }
}

impl FromZval<'_> for UserRole {
    const TYPE: DataType = DataType::Mixed;

    fn from_zval(zval: &Zval) -> Option<Self> {
        let f: &UserRole = zval.extract()?;

        Some(f.clone())
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  Role
//
////////////////////////////////////////////////////////////////////////////////////////////

/// Role allows granular access to database entities for users.
/// Wraps `aerospike::Role` (aerospike-core v2).
///
/// v2 NOTE: `read_quota` and `write_quota` are `u32` in aerospike-core.
/// The PHP getters return `u64` for backward compatibility (widening cast).
/// v2 BREAKING: the old `write_quota()` getter (missing `get_` prefix) is renamed to
/// `get_write_quota()` for consistency with all other getters.
#[php_class]
#[php(name = "Aerospike\\Role")]
#[derive(Debug, PartialEq, Clone)]
pub struct Role {
    _as: aero::Role,
}

#[php_impl]
impl Role {
    /// Name is role name.
    pub fn get_name(&self) -> String {
        self._as.name.clone()
    }

    /// Privileges is the list of assigned privileges.
    pub fn get_privileges(&self) -> Vec<Privilege> {
        self._as
            .privileges
            .iter()
            .map(|v| Privilege { _as: v.clone() })
            .collect()
    }

    /// Allowlist is the list of allowable IP addresses.
    pub fn get_allowlist(&self) -> Vec<String> {
        self._as.allowlist.clone()
    }

    /// ReadQuota is the maximum reads per second limit for the role.
    pub fn get_read_quota(&self) -> u64 {
        self._as.read_quota as u64
    }

    /// WriteQuota is the maximum writes per second limit for the role.
    /// v2 BREAKING: renamed from `write_quota()` (no `get_` prefix) to `get_write_quota()`.
    pub fn get_write_quota(&self) -> u64 {
        self._as.write_quota as u64
    }
}

impl From<aero::Role> for Role {
    fn from(input: aero::Role) -> Self {
        Role { _as: input }
    }
}

impl FromZval<'_> for Role {
    const TYPE: DataType = DataType::Mixed;

    fn from_zval(zval: &Zval) -> Option<Self> {
        let f: &Role = zval.extract()?;

        Some(f.clone())
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  Privilege
//
////////////////////////////////////////////////////////////////////////////////////////////

/// Privilege determines user access granularity.
/// Wraps `aerospike::Privilege` (aerospike-core v2).
///
/// v2 BREAKING: proto had a string `name` field for the privilege code; aerospike-core
/// uses a typed `PrivilegeCode` enum in the `code` field.  The `get_name()` getter now
/// derives the string representation from `PrivilegeCode` via `String::from(&code)` so
/// the PHP API surface is preserved.
///
/// `namespace` and `set_name` are `Option<String>` in aerospike-core.  The getters
/// return an empty string when `None` for backward compatibility.
#[php_class]
#[php(name = "Aerospike\\Privilege")]
#[derive(Debug, PartialEq, Clone)]
pub struct Privilege {
    _as: aero::Privilege,
}

#[php_impl]
impl Privilege {
    /// Returns the string name of the privilege code (e.g. "read", "read-write").
    /// Derived from `PrivilegeCode` — replaces proto's string `name` field.
    pub fn get_name(&self) -> String {
        String::from(&self._as.code)
    }

    /// Returns the namespace scope, or empty string if unscoped.
    pub fn get_namespace(&self) -> String {
        self._as.namespace.clone().unwrap_or_default()
    }

    /// Returns the set name scope, or empty string if unscoped.
    pub fn get_setname(&self) -> String {
        self._as.set_name.clone().unwrap_or_default()
    }

    /// UserAdmin allows to manages users and their roles.
    pub fn user_admin() -> String {
        "user-admin".into()
    }

    /// SysAdmin allows to manage indexes, user defined functions and server configuration.
    pub fn sys_admin() -> String {
        "sys-admin".into()
    }

    /// DataAdmin allows to manage indicies and user defined functions.
    pub fn data_admin() -> String {
        "data-admin".into()
    }

    /// UDFAdmin allows to manage user defined functions.
    pub fn udf_admin() -> String {
        "udf-admin".into()
    }

    /// SIndexAdmin allows to manage indicies.
    pub fn sindex_admin() -> String {
        "sindex-admin".into()
    }

    /// ReadWriteUDF allows read, write and UDF transactions with the database.
    pub fn read_write_udf() -> String {
        "read-write-udf".into()
    }

    /// ReadWrite allows read and write transactions with the database.
    pub fn read_write() -> String {
        "read-write".into()
    }

    /// Read allows read transactions with the database.
    pub fn read() -> String {
        "read".into()
    }

    /// Write allows write transactions with the database.
    pub fn write() -> String {
        "write".into()
    }

    /// Truncate allow issuing truncate commands.
    pub fn truncate() -> String {
        "truncate".into()
    }
}

impl FromZval<'_> for Privilege {
    const TYPE: DataType = DataType::Mixed;

    fn from_zval(zval: &Zval) -> Option<Self> {
        let f: &Privilege = zval.extract()?;

        Some(f.clone())
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  CdtListReturnType
//
////////////////////////////////////////////////////////////////////////////////////////////

/// ListReturnType determines the returned values in CDT List operations.
#[php_class]
#[php(name = "Aerospike\\ListReturnType")]
#[derive(Debug, PartialEq, Clone)]
pub struct CdtListReturnType {
    // Stored as i32 bitmask to support the Inverted() combinator (bitwise OR with 0x10000).
    // Values are taken from aero::ListReturnType discriminants.
    _as: i32,
}

#[php_impl]
impl CdtListReturnType {
    /// ListReturnTypeNone will not return a result.
    pub fn None() -> Self {
        Self {
            _as: aero::ListReturnType::None as i32,
        }
    }

    /// ListReturnTypeIndex will return index offset order.
    /// 0 = first key
    /// N = Nth key
    /// -1 = last key
    pub fn Index() -> Self {
        Self {
            _as: aero::ListReturnType::Index as i32,
        }
    }

    /// ListReturnTypeReverseIndex will return reverse index offset order.
    /// 0 = last key
    /// -1 = first key
    pub fn Reverse_index() -> Self {
        Self {
            _as: aero::ListReturnType::ReverseIndex as i32,
        }
    }

    /// ListReturnTypeRank will return value order.
    /// 0 = smallest value
    /// N = Nth smallest value
    /// -1 = largest value
    pub fn Rank() -> Self {
        Self {
            _as: aero::ListReturnType::Rank as i32,
        }
    }

    /// ListReturnTypeReverseRank will return reverse value order.
    /// 0 = largest value
    /// N = Nth largest value
    /// -1 = smallest value
    pub fn Reverse_rank() -> Self {
        Self {
            _as: aero::ListReturnType::ReverseRank as i32,
        }
    }

    /// ListReturnTypeCount will return count of items selected.
    pub fn Count() -> Self {
        Self {
            _as: aero::ListReturnType::Count as i32,
        }
    }

    /// ListReturnTypeValues will return value for single key read and value list for range read.
    /// Note: proto named this variant `Value`; aero equivalent is `Values` (discriminant 7).
    pub fn Value() -> Self {
        Self {
            _as: aero::ListReturnType::Values as i32,
        }
    }

    /// ListReturnTypeExists returns true if count > 0.
    pub fn Exists() -> Self {
        Self {
            _as: aero::ListReturnType::Exists as i32,
        }
    }

    /// ListReturnTypeInverted will invert meaning of list command and return values.  For example:
    /// ListOperation.getByIndexRange(binName, index, count, ListReturnType.INDEX | ListReturnType.INVERTED)
    /// With the INVERTED flag enabled, the items outside of the specified index range will be returned.
    /// The meaning of the list command can also be inverted.  For example:
    /// ListOperation.removeByIndexRange(binName, index, count, ListReturnType.INDEX | ListReturnType.INVERTED);
    /// With the INVERTED flag enabled, the items outside of the specified index range will be removed and returned.
    pub fn Inverted(&self) -> Self {
        Self {
            _as: self._as | aero::ListReturnType::Inverted as i32,
        }
    }
}

impl FromZval<'_> for CdtListReturnType {
    const TYPE: DataType = DataType::Mixed;

    fn from_zval(zval: &Zval) -> Option<Self> {
        let f: &CdtListReturnType = zval.extract()?;

        Some(CdtListReturnType { _as: f._as })
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  CdtListWriteFlags
//
////////////////////////////////////////////////////////////////////////////////////////////

/// ListWriteFlags determines write flags for CDT lists.
#[php_class]
#[php(name = "Aerospike\\ListWriteFlags")]
#[derive(Debug, Clone, Copy)]
pub struct CdtListWriteFlags {
    _as: aero::ListWriteFlags,
}

#[php_impl]
impl CdtListWriteFlags {
    /// ListWriteFlagsDefault is the default behavior: allow duplicate values and insertions at any index.
    pub fn Default() -> Self {
        Self {
            _as: aero::ListWriteFlags::Default,
        }
    }

    /// ListWriteFlagsAddUnique means: only add unique values.
    pub fn Add_Unique() -> Self {
        Self {
            _as: aero::ListWriteFlags::AddUnique,
        }
    }

    /// ListWriteFlagsInsertBounded means: enforce list boundaries when inserting. Do not allow values
    /// to be inserted at an index outside the current list boundaries.
    pub fn Insert_Bounded() -> Self {
        Self {
            _as: aero::ListWriteFlags::InsertBounded,
        }
    }

    /// ListWriteFlagsNoFail means: do not raise error if a list item fails due to write flag constraints.
    pub fn No_Fail() -> Self {
        Self {
            _as: aero::ListWriteFlags::NoFail,
        }
    }

    /// ListWriteFlagsPartial means: allow other valid list items to be committed if a list item fails due to
    /// write flag constraints.
    pub fn Partial() -> Self {
        Self {
            _as: aero::ListWriteFlags::Partial,
        }
    }
}

impl FromZval<'_> for CdtListWriteFlags {
    const TYPE: DataType = DataType::Mixed;

    fn from_zval(zval: &Zval) -> Option<Self> {
        let f: &CdtListWriteFlags = zval.extract()?;

        Some(CdtListWriteFlags { _as: f._as })
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  CdtListSortFlags
//
////////////////////////////////////////////////////////////////////////////////////////////

/// ListSortFlags determines sort flags for CDT list operations.
#[php_class]
#[php(name = "Aerospike\\ListSortFlags")]
#[derive(Debug, Clone, Copy)]
pub struct CdtListSortFlags {
    _as: aero::ListSortFlags,
}

#[php_impl]
impl CdtListSortFlags {
    /// ListSortFlagsDefault is the default sort flag for CDT lists, and sorts in ascending order.
    pub fn Default() -> Self {
        Self {
            _as: aero::ListSortFlags::Default,
        }
    }

    /// ListSortFlagsDescending will sort the contents of the list in descending order.
    pub fn Descending() -> Self {
        Self {
            _as: aero::ListSortFlags::Descending,
        }
    }

    /// ListSortFlagsDropDuplicates will drop duplicate values in the results of the CDT list operation.
    pub fn Drop_Duplicates() -> Self {
        Self {
            _as: aero::ListSortFlags::DropDuplicates,
        }
    }
}

impl FromZval<'_> for CdtListSortFlags {
    const TYPE: DataType = DataType::Mixed;

    fn from_zval(zval: &Zval) -> Option<Self> {
        let f: &CdtListSortFlags = zval.extract()?;

        Some(CdtListSortFlags { _as: f._as })
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  CdtListPolicy
//
////////////////////////////////////////////////////////////////////////////////////////////

/// ListPolicy directives when creating a list and writing list items.
#[php_class]
#[php(name = "Aerospike\\ListPolicy")]
#[derive(Debug, Clone, Copy, Default)]
pub struct CdtListPolicy {
    _as: aero::ListPolicy,
}

#[php_impl]
impl CdtListPolicy {
    /// NewListPolicy creates a policy with directives when creating a list and writing list items.
    /// Flags are ListWriteFlags. You can specify multiple by passing multiple values in the array;
    /// they are combined with a bitwise OR.
    pub fn __construct(order: ListOrderType, flags: Option<Vec<CdtListWriteFlags>>) -> Self {
        let flags_bitmask: u8 = flags
            .map(|flags| flags.iter().fold(0u8, |acc, f| acc | f._as as u8))
            .unwrap_or(0);

        CdtListPolicy {
            _as: aero::ListPolicy {
                attributes: order._as,
                flags: flags_bitmask,
            },
        }
    }
}

impl FromZval<'_> for CdtListPolicy {
    const TYPE: DataType = DataType::Mixed;

    fn from_zval(zval: &Zval) -> Option<Self> {
        let f: &CdtListPolicy = zval.extract()?;

        Some(CdtListPolicy { _as: f._as })
    }
}

///////////////////////////////////////////////////////////////////////////////////////////
//
//  CdtListOperation
//
////////////////////////////////////////////////////////////////////////////////////////////

/// Local newtype wrapping a raw `i32` return-type bitmask so it can be passed to
/// `aero::operations::lists::*` builders which require `ToListReturnTypeBitmask`.
/// PHP `ListReturnType` stores an `i32` because it supports the `Inverted()` flag
/// (bitwise OR with `0x10000`).
struct ListReturn(i32);

impl aero::operations::lists::ToListReturnTypeBitmask for ListReturn {
    fn to_bitmask(self) -> i64 {
        i64::from(self.0)
    }
}

fn list_return(return_type: Option<CdtListReturnType>) -> ListReturn {
    ListReturn(
        return_type
            .map(|rt| rt._as)
            .unwrap_or(aero::ListReturnType::Values as i32),
    )
}

/// Apply a CDT context (from PHP) to an already-built aero `Operation` via the
/// builder method `.context(Vec<CdtContext>)`.
fn with_ctx(
    op: aero::operations::Operation,
    ctx: Option<Vec<&CDTContext>>,
) -> aero::operations::Operation {
    match ctx {
        Some(c) if !c.is_empty() => op.context(c.iter().map(|x| x._as.clone()).collect()),
        _ => op,
    }
}

/// Coerce a `Vec<PHPValue>` to `Vec<aero::Value>` (cheap; consumes the input).
fn php_values_to_aero(values: Vec<PHPValue>) -> Vec<aero::Value> {
    values.into_iter().map(Into::into).collect()
}

/// List operations support negative indexing.  If the index is negative, the
/// resolved index starts backwards from end of list. If an index is out of bounds,
/// a parameter error will be returned. If a range is partially out of bounds, the
/// valid part of the range will be returned. Index/Range examples:
///
/// Index/Range examples:
///
///    Index 0: First item in list.
///    Index 4: Fifth item in list.
///    Index -1: Last item in list.
///    Index -3: Third to last item in list.
///    Index 1 Count 2: Second and third items in list.
///    Index -3 Count 3: Last three items in list.
///    Index -5 Count 4: Range between fifth to last item to second to last item inclusive.
///
#[php_class]
#[php(name = "Aerospike\\ListOp")]
#[derive(Clone)]
pub struct CdtListOperation {
    _as: aero::operations::Operation,
}

impl FromZval<'_> for CdtListOperation {
    const TYPE: DataType = DataType::Mixed;

    fn from_zval(zval: &Zval) -> Option<Self> {
        let f: &CdtListOperation = zval.extract()?;

        Some(CdtListOperation { _as: f._as.clone() })
    }
}

#[php_impl]
impl CdtListOperation {
    /// ListCreateOp creates list create operation.
    /// Server creates list at given context level. The context is allowed to be beyond list
    /// boundaries only if pad is set to true. When `index` is true, the list is created with a
    /// persisted index (and the `pad` argument is ignored — aero's `create_with_index` does not
    /// support padding).
    pub fn create(
        bin_name: String,
        order: ListOrderType,
        pad: bool,
        index: Option<bool>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = if index.unwrap_or(false) {
            aero::operations::lists::create_with_index(&bin_name, order._as)
        } else {
            aero::operations::lists::create(&bin_name, order._as, pad)
        };
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// ListSetOrderOp creates a set list order operation.
    /// Server sets list order. Server returns nil.
    pub fn set_order(
        bin_name: String,
        order: ListOrderType,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        Operation {
            _as: with_ctx(
                aero::operations::lists::set_order(&bin_name, order._as),
                ctx,
            ),
        }
    }

    /// ListAppendOp creates a list append operation.
    /// Server appends values to end of list bin.
    /// Server returns list size on bin name.
    /// Panics if `values` is empty.
    pub fn append(
        policy: &CdtListPolicy,
        bin_name: String,
        values: Vec<PHPValue>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = aero::operations::lists::append_items(
            &policy._as,
            &bin_name,
            php_values_to_aero(values),
        );
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// ListInsertOp creates a list insert operation.
    /// Server inserts values starting at specified index of list bin.
    /// Server returns list size on bin name.
    /// Panics if `values` is empty.
    pub fn insert(
        policy: &CdtListPolicy,
        bin_name: String,
        index: i64,
        values: Vec<PHPValue>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = aero::operations::lists::insert_items(
            &policy._as,
            &bin_name,
            index,
            php_values_to_aero(values),
        );
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// ListPopOp creates list pop operation.
    /// Server returns item at specified index and removes item from list bin.
    pub fn pop(bin_name: String, index: i64, ctx: Option<Vec<&CDTContext>>) -> Operation {
        Operation {
            _as: with_ctx(aero::operations::lists::pop(&bin_name, index), ctx),
        }
    }

    /// ListPopRangeOp creates a list pop range operation.
    /// Server returns items starting at specified index and removes items from list bin.
    pub fn pop_range(
        bin_name: String,
        index: i64,
        count: i64,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        Operation {
            _as: with_ctx(
                aero::operations::lists::pop_range(&bin_name, index, count),
                ctx,
            ),
        }
    }

    /// ListPopRangeFromOp creates a list pop range operation.
    /// Server returns items starting at specified index to the end of list and removes items from list bin.
    pub fn pop_range_from(
        bin_name: String,
        index: i64,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        Operation {
            _as: with_ctx(
                aero::operations::lists::pop_range_from(&bin_name, index),
                ctx,
            ),
        }
    }

    /// ListRemoveByValueListOp creates list remove by value operation.
    /// Server removes items identified by values and returns removed data specified by returnType.
    pub fn remove_values(
        bin_name: String,
        values: Vec<PHPValue>,
        return_type: Option<CdtListReturnType>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = aero::operations::lists::remove_by_value_list(
            &bin_name,
            php_values_to_aero(values),
            list_return(return_type),
        );
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// ListRemoveByValueRangeOp creates a list remove operation.
    /// Server removes list items identified by value range (valueBegin inclusive, valueEnd exclusive).
    /// If valueBegin is nil, the range is less than valueEnd.
    /// If valueEnd is nil, the range is greater than equal to valueBegin.
    /// Server returns removed data specified by returnType.
    pub fn remove_by_value_range(
        bin_name: String,
        begin: PHPValue,
        end: Option<PHPValue>,
        return_type: Option<CdtListReturnType>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = aero::operations::lists::remove_by_value_range(
            &bin_name,
            list_return(return_type),
            begin.into(),
            end.map(Into::into).unwrap_or(aero::Value::Nil),
        );
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// ListRemoveByValueRelativeRankRangeOp creates a list remove by value relative to rank range operation.
    /// Server removes list items nearest to value and greater by relative rank.
    /// Server returns removed data specified by returnType.
    ///
    /// Examples for ordered list [0,4,5,9,11,15]:
    ///
    ///	(value,rank) = [removed items]
    ///	(5,0) = [5,9,11,15]
    ///	(5,1) = [9,11,15]
    ///	(5,-1) = [4,5,9,11,15]
    ///	(3,0) = [4,5,9,11,15]
    ///	(3,3) = [11,15]
    ///	(3,-3) = [0,4,5,9,11,15]
    pub fn remove_by_value_relative_rank_range(
        bin_name: String,
        value: PHPValue,
        rank: i64,
        return_type: Option<CdtListReturnType>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = aero::operations::lists::remove_by_value_relative_rank_range(
            &bin_name,
            list_return(return_type),
            value.into(),
            rank,
        );
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// ListRemoveByValueRelativeRankRangeCountOp creates a list remove by value relative to rank range operation.
    /// Server removes list items nearest to value and greater by relative rank with a count limit.
    /// Server returns removed data specified by returnType.
    /// Examples for ordered list [0,4,5,9,11,15]:
    ///
    ///	(value,rank,count) = [removed items]
    ///	(5,0,2) = [5,9]
    ///	(5,1,1) = [9]
    ///	(5,-1,2) = [4,5]
    ///	(3,0,1) = [4]
    ///	(3,3,7) = [11,15]
    ///	(3,-3,2) = []
    pub fn remove_by_value_relative_rank_range_count(
        bin_name: String,
        value: PHPValue,
        rank: i64,
        count: i64,
        return_type: Option<CdtListReturnType>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = aero::operations::lists::remove_by_value_relative_rank_range_count(
            &bin_name,
            list_return(return_type),
            value.into(),
            rank,
            count,
        );
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// ListRemoveRangeOp creates a list remove range operation.
    /// Server removes "count" items starting at specified index from list bin.
    /// Server returns number of items removed.
    pub fn remove_range(
        bin_name: String,
        index: i64,
        count: i64,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        Operation {
            _as: with_ctx(
                aero::operations::lists::remove_range(&bin_name, index, count),
                ctx,
            ),
        }
    }

    /// ListRemoveRangeFromOp creates a list remove range operation.
    /// Server removes all items starting at specified index to the end of list.
    /// Server returns number of items removed.
    pub fn remove_range_from(
        bin_name: String,
        index: i64,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        Operation {
            _as: with_ctx(
                aero::operations::lists::remove_range_from(&bin_name, index),
                ctx,
            ),
        }
    }

    /// ListSetOp creates a list set operation.
    /// Server sets item value at specified index in list bin.
    /// Server does not return a result by default.
    pub fn set(
        bin_name: String,
        index: i64,
        value: PHPValue,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        Operation {
            _as: with_ctx(
                aero::operations::lists::set(&bin_name, index, value.into()),
                ctx,
            ),
        }
    }

    /// ListTrimOp creates a list trim operation.
    /// Server removes items in list bin that do not fall into range specified by index
    /// and count range. If the range is out of bounds, then all items will be removed.
    /// Server returns number of elements that were removed.
    pub fn trim(
        bin_name: String,
        index: i64,
        count: i64,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        Operation {
            _as: with_ctx(aero::operations::lists::trim(&bin_name, index, count), ctx),
        }
    }

    /// ListClearOp creates a list clear operation.
    /// Server removes all items in list bin.
    /// Server does not return a result by default.
    pub fn clear(bin_name: String, ctx: Option<Vec<&CDTContext>>) -> Operation {
        Operation {
            _as: with_ctx(aero::operations::lists::clear(&bin_name), ctx),
        }
    }

    /// ListIncrementOp creates a list increment operation.
    /// Server increments list[index] by value.
    /// Server returns list[index] after incrementing.
    ///
    /// v2 BREAKING: aerospike-client-rust v2 only supports integer increments. Float
    /// increments accepted by the proto version are no longer supported.
    pub fn increment(
        bin_name: String,
        index: i64,
        value: i64,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let policy = aero::ListPolicy::default();
        Operation {
            _as: with_ctx(
                aero::operations::lists::increment(&policy, &bin_name, index, value),
                ctx,
            ),
        }
    }

    /// ListSizeOp creates a list size operation.
    /// Server returns size of list on bin name.
    pub fn size(bin_name: String, ctx: Option<Vec<&CDTContext>>) -> Operation {
        Operation {
            _as: with_ctx(aero::operations::lists::size(&bin_name), ctx),
        }
    }

    /// ListSortOp creates list sort operation.
    /// Server sorts list according to sortFlags.
    /// Server does not return a result by default.
    pub fn sort(
        bin_name: String,
        sort_flags: &CdtListSortFlags,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        Operation {
            _as: with_ctx(
                aero::operations::lists::sort(&bin_name, sort_flags._as),
                ctx,
            ),
        }
    }

    /// ListRemoveByIndexOp creates a list remove operation.
    /// Server removes list item identified by index and returns removed data specified by returnType.
    pub fn remove_by_index(
        bin_name: String,
        index: i64,
        return_type: Option<CdtListReturnType>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op =
            aero::operations::lists::remove_by_index(&bin_name, index, list_return(return_type));
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// ListRemoveByIndexRangeOp creates a list remove operation.
    /// Server removes list items starting at specified index to the end of list and returns removed
    /// data specified by returnType.
    pub fn remove_by_index_range(
        bin_name: String,
        index: i64,
        return_type: Option<CdtListReturnType>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = aero::operations::lists::remove_by_index_range(
            &bin_name,
            index,
            list_return(return_type),
        );
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// ListRemoveByIndexRangeCountOp creates a list remove operation.
    /// Server removes "count" list items starting at specified index and returns removed data specified by returnType.
    pub fn remove_by_index_range_count(
        bin_name: String,
        index: i64,
        count: i64,
        return_type: Option<CdtListReturnType>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = aero::operations::lists::remove_by_index_range_count(
            &bin_name,
            index,
            count,
            list_return(return_type),
        );
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// ListRemoveByRankOp creates a list remove operation.
    /// Server removes list item identified by rank and returns removed data specified by returnType.
    pub fn remove_by_rank(
        bin_name: String,
        rank: i64,
        return_type: Option<CdtListReturnType>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = aero::operations::lists::remove_by_rank(&bin_name, rank, list_return(return_type));
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// ListRemoveByRankRangeOp creates a list remove operation.
    /// Server removes list items starting at specified rank to the last ranked item and returns removed
    /// data specified by returnType.
    pub fn remove_by_rank_range(
        bin_name: String,
        rank: i64,
        return_type: Option<CdtListReturnType>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = aero::operations::lists::remove_by_rank_range(
            &bin_name,
            rank,
            list_return(return_type),
        );
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// ListRemoveByRankRangeCountOp creates a list remove operation.
    /// Server removes "count" list items starting at specified rank and returns removed data specified by returnType.
    pub fn remove_by_rank_range_count(
        bin_name: String,
        rank: i64,
        count: i64,
        return_type: Option<CdtListReturnType>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = aero::operations::lists::remove_by_rank_range_count(
            &bin_name,
            rank,
            count,
            list_return(return_type),
        );
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// ListGetByValueListOp creates a list get by value operation.
    /// Server selects list items identified by values and returns selected data specified by returnType.
    pub fn get_by_values(
        bin_name: String,
        values: Vec<PHPValue>,
        return_type: Option<CdtListReturnType>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = aero::operations::lists::get_by_value_list(
            &bin_name,
            php_values_to_aero(values),
            list_return(return_type),
        );
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// ListGetByValueRangeOp creates a list get by value range operation.
    /// Server selects list items identified by value range (valueBegin inclusive, valueEnd exclusive)
    /// If valueBegin is nil, the range is less than valueEnd.
    /// If valueEnd is nil, the range is greater than equal to valueBegin.
    /// Server returns selected data specified by returnType.
    pub fn get_by_value_range(
        bin_name: String,
        begin: PHPValue,
        end: Option<PHPValue>,
        return_type: Option<CdtListReturnType>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = aero::operations::lists::get_by_value_range(
            &bin_name,
            begin.into(),
            end.map(Into::into).unwrap_or(aero::Value::Nil),
            list_return(return_type),
        );
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// ListGetByIndexOp creates list get by index operation.
    /// Server selects list item identified by index and returns selected data specified by returnType.
    pub fn get_by_index(
        bin_name: String,
        index: i64,
        return_type: Option<CdtListReturnType>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = aero::operations::lists::get_by_index(&bin_name, index, list_return(return_type));
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// ListGetByIndexRangeOp creates list get by index range operation.
    /// Server selects list items starting at specified index to the end of list and returns selected
    /// data specified by returnType.
    pub fn get_by_index_range(
        bin_name: String,
        index: i64,
        return_type: Option<CdtListReturnType>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op =
            aero::operations::lists::get_by_index_range(&bin_name, index, list_return(return_type));
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// ListGetByIndexRangeCountOp creates list get by index range operation.
    /// Server selects "count" list items starting at specified index and returns selected data specified
    /// by returnType.
    pub fn get_by_index_range_count(
        bin_name: String,
        index: i64,
        count: i64,
        return_type: Option<CdtListReturnType>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = aero::operations::lists::get_by_index_range_count(
            &bin_name,
            index,
            count,
            list_return(return_type),
        );
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// ListGetByRankOp creates a list get by rank operation.
    /// Server selects list item identified by rank and returns selected data specified by returnType.
    pub fn get_by_rank(
        bin_name: String,
        rank: i64,
        return_type: Option<CdtListReturnType>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = aero::operations::lists::get_by_rank(&bin_name, rank, list_return(return_type));
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// ListGetByRankRangeOp creates a list get by rank range operation.
    /// Server selects list items starting at specified rank to the last ranked item and returns selected
    /// data specified by returnType.
    pub fn get_by_rank_range(
        bin_name: String,
        rank: i64,
        return_type: Option<CdtListReturnType>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op =
            aero::operations::lists::get_by_rank_range(&bin_name, rank, list_return(return_type));
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// ListGetByRankRangeCountOp creates a list get by rank range operation.
    /// Server selects "count" list items starting at specified rank and returns selected data specified by returnType.
    pub fn get_by_rank_range_count(
        bin_name: String,
        rank: i64,
        count: i64,
        return_type: Option<CdtListReturnType>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = aero::operations::lists::get_by_rank_range_count(
            &bin_name,
            rank,
            count,
            list_return(return_type),
        );
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// ListGetByValueRelativeRankRangeOp creates a list get by value relative to rank range operation.
    /// Server selects list items nearest to value and greater by relative rank.
    /// Server returns selected data specified by returnType.
    ///
    /// Examples for ordered list [0,4,5,9,11,15]:
    ///
    ///	(value,rank) = [selected items]
    ///	(5,0) = [5,9,11,15]
    ///	(5,1) = [9,11,15]
    ///	(5,-1) = [4,5,9,11,15]
    ///	(3,0) = [4,5,9,11,15]
    ///	(3,3) = [11,15]
    ///	(3,-3) = [0,4,5,9,11,15]
    pub fn get_by_value_relative_rank_range(
        bin_name: String,
        value: PHPValue,
        rank: i64,
        return_type: Option<CdtListReturnType>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = aero::operations::lists::get_by_value_relative_rank_range(
            &bin_name,
            value.into(),
            rank,
            list_return(return_type),
        );
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// ListGetByValueRelativeRankRangeCountOp creates a list get by value relative to rank range operation.
    /// Server selects list items nearest to value and greater by relative rank with a count limit.
    /// Server returns selected data specified by returnType.
    ///
    /// Examples for ordered list [0,4,5,9,11,15]:
    ///
    ///	(value,rank,count) = [selected items]
    ///	(5,0,2) = [5,9]
    ///	(5,1,1) = [9]
    ///	(5,-1,2) = [4,5]
    ///	(3,0,1) = [4]
    ///	(3,3,7) = [11,15]
    ///	(3,-3,2) = []
    pub fn get_by_value_relative_rank_range_count(
        bin_name: String,
        value: PHPValue,
        rank: i64,
        count: i64,
        return_type: Option<CdtListReturnType>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = aero::operations::lists::get_by_value_relative_rank_range_count(
            &bin_name,
            value.into(),
            rank,
            count,
            list_return(return_type),
        );
        Operation {
            _as: with_ctx(op, ctx),
        }
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  CdtMapReturnType
//
////////////////////////////////////////////////////////////////////////////////////////////

/// MapReturnType defines the map return type.
/// Type of data to return when selecting or removing items from the map.
#[php_class]
#[php(name = "Aerospike\\MapReturnType")]
#[derive(Clone, Copy)]
pub struct CdtMapReturnType {
    _as: aero::MapReturnType,
}

#[php_impl]
impl CdtMapReturnType {
    /// NONE will not return a result.
    pub fn None() -> Self {
        Self {
            _as: aero::MapReturnType::None,
        }
    }

    /// INDEX will return key index order.
    ///
    /// 0 = first key
    /// N = Nth key
    /// -1 = last key
    pub fn Index() -> Self {
        Self {
            _as: aero::MapReturnType::Index,
        }
    }

    /// REVERSE_INDEX will return reverse key order.
    ///
    /// 0 = last key
    /// -1 = first key
    pub fn Reverse_Index() -> Self {
        Self {
            _as: aero::MapReturnType::ReverseIndex,
        }
    }

    /// RANK will return value order.
    ///
    /// 0 = smallest value
    /// N = Nth smallest value
    /// -1 = largest value
    pub fn Rank() -> Self {
        Self {
            _as: aero::MapReturnType::Rank,
        }
    }

    /// REVERSE_RANK will return reverse value order.
    ///
    /// 0 = largest value
    /// N = Nth largest value
    /// -1 = smallest value
    pub fn Reverse_Rank() -> Self {
        Self {
            _as: aero::MapReturnType::ReverseRank,
        }
    }

    /// COUNT will return count of items selected.
    pub fn Count() -> Self {
        Self {
            _as: aero::MapReturnType::Count,
        }
    }

    /// KEY will return key for single key read and key list for range read.
    pub fn Key() -> Self {
        Self {
            _as: aero::MapReturnType::Key,
        }
    }

    /// VALUE will return value for single key read and value list for range read.
    pub fn Value() -> Self {
        Self {
            _as: aero::MapReturnType::Value,
        }
    }

    /// KEY_VALUE will return key/value items. The possible return types are:
    ///
    /// Value::HashMap : Returned for unordered maps
    /// Value::KeyValueList : Returned for range results where range order needs to be preserved.
    pub fn Key_Value() -> Self {
        Self {
            _as: aero::MapReturnType::KeyValue,
        }
    }

    /// EXISTS returns true if count > 0.
    pub fn Exists() -> Self {
        Self {
            _as: aero::MapReturnType::Exists,
        }
    }

    /// UNORDERED_MAP returns an unordered map.
    pub fn Unordered_Map() -> Self {
        Self {
            _as: aero::MapReturnType::UnorderedMap,
        }
    }

    /// ORDERED_MAP returns an ordered map.
    pub fn Ordered_Map() -> Self {
        Self {
            _as: aero::MapReturnType::OrderedMap,
        }
    }

    /// INVERTED will invert meaning of map command and return values. For example:
    /// MapRemoveByKeyRange(binName, keyBegin, keyEnd, MapReturnType.KEY | MapReturnType.INVERTED)
    /// With the INVERTED flag enabled, the keys outside of the specified key range will be removed and returned.
    pub fn Inverted() -> Self {
        Self {
            _as: aero::MapReturnType::Inverted,
        }
    }
}

impl FromZval<'_> for CdtMapReturnType {
    const TYPE: DataType = DataType::Mixed;

    fn from_zval(zval: &Zval) -> Option<Self> {
        let f: &CdtMapReturnType = zval.extract()?;

        Some(CdtMapReturnType { _as: f._as })
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  CdtMapWriteMode
//
////////////////////////////////////////////////////////////////////////////////////////////

/// MapWriteMode should only be used for server versions < 4.3.
/// MapWriteFlags are recommended for server versions >= 4.3.
#[php_class]
#[php(name = "Aerospike\\MapWriteMode")]
#[derive(Clone, Copy)]
pub struct CdtMapWriteMode {
    _as: aero::MapWriteMode,
}

#[php_impl]
impl CdtMapWriteMode {
    /// If the key already exists, the item will be overwritten.
    /// If the key does not exist, a new item will be created.
    pub fn Update() -> Self {
        Self {
            _as: aero::MapWriteMode::Update,
        }
    }

    /// If the key already exists, the item will be overwritten.
    /// If the key does not exist, the write will fail.
    pub fn Update_Only() -> Self {
        Self {
            _as: aero::MapWriteMode::UpdateOnly,
        }
    }

    /// If the key already exists, the write will fail.
    /// If the key does not exist, a new item will be created.
    pub fn Create_Only() -> Self {
        Self {
            _as: aero::MapWriteMode::CreateOnly,
        }
    }
}

impl FromZval<'_> for CdtMapWriteMode {
    const TYPE: DataType = DataType::Mixed;

    fn from_zval(zval: &Zval) -> Option<Self> {
        let f: &CdtMapWriteMode = zval.extract()?;

        Some(CdtMapWriteMode { _as: f._as })
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  CdtMapWriteFlags
//
////////////////////////////////////////////////////////////////////////////////////////////

/// Map write bit flags.
/// Requires server versions >= 4.3.
///
/// NOTE: aero::MapWriteFlags is a module of u8 constants, not an enum.
/// This wrapper holds the raw u8 flag value so callers can OR flags together.
#[php_class]
#[php(name = "Aerospike\\MapWriteFlags")]
#[derive(Clone, Copy)]
pub struct CdtMapWriteFlags {
    _as: u8,
}

#[php_impl]
impl CdtMapWriteFlags {
    /// Default. Allow create or update.
    pub fn Default() -> Self {
        Self {
            _as: aero::MapWriteFlags::DEFAULT,
        }
    }

    /// If the key already exists, the item will be denied.
    /// If the key does not exist, a new item will be created.
    pub fn Create_Only() -> Self {
        Self {
            _as: aero::MapWriteFlags::CREATE_ONLY,
        }
    }

    /// If the key already exists, the item will be overwritten.
    /// If the key does not exist, the item will be denied.
    pub fn Update_Only() -> Self {
        Self {
            _as: aero::MapWriteFlags::UPDATE_ONLY,
        }
    }

    /// Do not raise error if a map item is denied due to write flag constraints.
    pub fn No_Fail() -> Self {
        Self {
            _as: aero::MapWriteFlags::NO_FAIL,
        }
    }

    /// Allow other valid map items to be committed if a map item is denied due to
    /// write flag constraints.
    pub fn Partial() -> Self {
        Self {
            _as: aero::MapWriteFlags::PARTIAL,
        }
    }
}

impl FromZval<'_> for CdtMapWriteFlags {
    const TYPE: DataType = DataType::Mixed;

    fn from_zval(zval: &Zval) -> Option<Self> {
        let f: &CdtMapWriteFlags = zval.extract()?;

        Some(CdtMapWriteFlags { _as: f._as })
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  CdtMapPolicy
//
////////////////////////////////////////////////////////////////////////////////////////////

/// MapPolicy directives when creating a map and writing map items.
#[php_class]
#[php(name = "Aerospike\\MapPolicy")]
#[derive(Clone, Copy)]
pub struct CdtMapPolicy {
    _as: aero::MapPolicy,
}

#[php_impl]
impl CdtMapPolicy {
    /// Creates a MapPolicy with optional write flags (server >= 4.3) or defaults to
    /// `MapWriteMode::Update` when no flags are supplied (servers < 4.3).
    pub fn __construct(
        order: &MapOrderType,
        flags: Option<Vec<&CdtMapWriteFlags>>,
        persist_index: Option<bool>,
    ) -> Self {
        let combined_flags: u8 = flags
            .unwrap_or_default()
            .into_iter()
            .fold(aero::MapWriteFlags::DEFAULT, |acc, f| acc | f._as);

        let mut policy = if combined_flags == aero::MapWriteFlags::DEFAULT {
            aero::MapPolicy::new(order._as, aero::MapWriteMode::Update)
        } else {
            aero::MapPolicy::new_with_flags(order._as, combined_flags)
        };
        policy.persist_index = persist_index.unwrap_or(false);

        Self { _as: policy }
    }
}

impl FromZval<'_> for CdtMapPolicy {
    const TYPE: DataType = DataType::Mixed;

    fn from_zval(zval: &Zval) -> Option<Self> {
        let f: &CdtMapPolicy = zval.extract()?;

        Some(CdtMapPolicy { _as: f._as })
    }
}

impl Default for CdtMapPolicy {
    fn default() -> Self {
        CdtMapPolicy {
            _as: aero::MapPolicy::new(
                aero::operations::maps::MapOrder::Unordered,
                aero::MapWriteMode::Update,
            ),
        }
    }
}

///////////////////////////////////////////////////////////////////////////////////////////
//
//  CdtMapOperation
//
////////////////////////////////////////////////////////////////////////////////////////////

/// Unique key map bin operations. Create map operations used by the client operate command.
/// The default unique key map is unordered.
///
/// All maps maintain an index and a rank.  The index is the item offset from the start of the map,
/// for both unordered and ordered maps.  The rank is the sorted index of the value component.
/// Map supports negative indexing for index and rank.
///
/// Index examples:
///
///  Index 0: First item in map.
///  Index 4: Fifth item in map.
///  Index -1: Last item in map.
///  Index -3: Third to last item in map.
///  Index 1 Count 2: Second and third items in map.
///  Index -3 Count 3: Last three items in map.
///  Index -5 Count 4: Range between fifth to last item to second to last item inclusive.
///
///
/// Rank examples:
///
///  Rank 0: Item with lowest value rank in map.
///  Rank 4: Fifth lowest ranked item in map.
///  Rank -1: Item with highest ranked value in map.
///  Rank -3: Item with third highest ranked value in map.
///  Rank 1 Count 2: Second and third lowest ranked items in map.
///  Rank -3 Count 3: Top three ranked items in map.
///
///
/// Nested CDT operations are supported by optional CTX context arguments.  Examples:
///
///  bin = {key1:{key11:9,key12:4}, key2:{key21:3,key22:5}}
///  Set map value to 11 for map key "key21" inside of map key "key2".
///  MapOperation.put(MapPolicy.Default, "bin", StringValue("key21"), IntegerValue(11), CtxMapKey(StringValue("key2")))
///  bin result = {key1:{key11:9,key12:4},key2:{key21:11,key22:5}}
///
///  bin : {key1:{key11:{key111:1},key12:{key121:5}}, key2:{key21:{"key211":7}}}
///  Set map value to 11 in map key "key121" for highest ranked map ("key12") inside of map key "key1".
///  MapPutOp(DefaultMapPolicy(), "bin", StringValue("key121"), IntegerValue(11), CtxMapKey(StringValue("key1")), CtxMapRank(-1))
///  bin result = {key1:{key11:{key111:1},key12:{key121:11}}, key2:{key21:{"key211":7}}}

#[php_class]
#[php(name = "Aerospike\\MapOp")]
#[derive(Clone)]
pub struct CdtMapOperation {
    _as: aero::operations::Operation,
}

impl FromZval<'_> for CdtMapOperation {
    const TYPE: DataType = DataType::Mixed;

    fn from_zval(zval: &Zval) -> Option<Self> {
        let f: &CdtMapOperation = zval.extract()?;

        Some(CdtMapOperation { _as: f._as.clone() })
    }
}

/// Default map return type used when the PHP caller passes `null`.
fn map_return(return_type: Option<CdtMapReturnType>) -> aero::MapReturnType {
    return_type
        .map(|rt| rt._as)
        .unwrap_or(aero::MapReturnType::KeyValue)
}

/// Convert a `Vec<&CDTContext>` to the owned `Vec<aero::CdtContext>` form
/// expected by aero builders that accept ctx as a function argument.
fn ctx_to_aero(ctx: Option<Vec<&CDTContext>>) -> Vec<aero::operations::cdt_context::CdtContext> {
    ctx.map(|c| c.iter().map(|x| x._as.clone()).collect())
        .unwrap_or_default()
}

#[php_impl]
impl CdtMapOperation {
    /// MapCreateOp creates a map create operation.
    /// Server creates map at given context level.
    ///
    /// v2 BREAKING: When `with_index` is `true`, the operation falls back to
    /// `aero::operations::maps::create_with_index` which does NOT accept a CDT context.
    /// Callers that used `with_index=true` together with `ctx` should drop `ctx` or split
    /// the call into a separate `set_policy` operation.
    pub fn create(
        bin_name: String,
        order: &MapOrderType,
        with_index: Option<bool>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = if with_index.unwrap_or(false) {
            aero::operations::maps::create_with_index(&bin_name, order._as)
        } else {
            aero::operations::maps::create(&bin_name, order._as, ctx_to_aero(ctx))
        };
        Operation { _as: op }
    }

    /// MapSetPolicyOp creates set map policy operation.
    /// Server sets map policy attributes. Server returns nil.
    ///
    /// The required map policy attributes can be changed after the map is created.
    pub fn set_policy(
        policy: &CdtMapPolicy,
        bin_name: String,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        Operation {
            _as: aero::operations::maps::set_policy(&policy._as, &bin_name, ctx_to_aero(ctx)),
        }
    }

    /// MapSizeOp creates map size operation.
    /// Server returns size of map.
    pub fn size(bin_name: String, ctx: Option<Vec<&CDTContext>>) -> Operation {
        Operation {
            _as: with_ctx(aero::operations::maps::size(&bin_name), ctx),
        }
    }

    /// MapPutOp creates map put-items operation.
    /// Server writes each key/value item to the map bin and returns the map size.
    /// Returns `None` if `map` is not a PHP associative array (HashMap).
    pub fn put(
        policy: &CdtMapPolicy,
        bin_name: String,
        map: PHPValue,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Option<Operation> {
        let aero_map: HashMap<aero::Value, aero::Value> = match map {
            PHPValue::HashMap(h) => h.into_iter().map(|(k, v)| (k.into(), v.into())).collect(),
            PHPValue::Json(h) => h
                .into_iter()
                .map(|(k, v)| (aero::Value::String(k), v.into()))
                .collect(),
            _ => return None,
        };
        let op = aero::operations::maps::put_items(&policy._as, &bin_name, aero_map);
        Some(Operation {
            _as: with_ctx(op, ctx),
        })
    }

    /// MapIncrementOp creates map increment operation.
    /// Server increments values by `incr` for the item identified by `key` and returns final
    /// result. Valid only for numbers.
    pub fn increment(
        policy: &CdtMapPolicy,
        bin_name: String,
        key: PHPValue,
        incr: PHPValue,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = aero::operations::maps::increment_value(
            &policy._as,
            &bin_name,
            key.into(),
            incr.into(),
        );
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// MapDecrementOp creates map decrement operation.
    /// Server decrements values by `decr` for the item identified by `key` and returns final
    /// result. Valid only for numbers.
    pub fn decrement(
        policy: &CdtMapPolicy,
        bin_name: String,
        key: PHPValue,
        decr: PHPValue,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = aero::operations::maps::decrement_value(
            &policy._as,
            &bin_name,
            key.into(),
            decr.into(),
        );
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// MapClearOp creates map clear operation.
    /// Server removes all items in map. Server returns nil.
    pub fn clear(bin_name: String, ctx: Option<Vec<&CDTContext>>) -> Operation {
        Operation {
            _as: with_ctx(aero::operations::maps::clear(&bin_name), ctx),
        }
    }

    /// MapRemoveByKeyListOp creates map remove operation.
    /// Server removes map items identified by keys and returns removed data specified by returnType.
    pub fn remove_by_keys(
        bin_name: String,
        keys: Vec<PHPValue>,
        return_type: Option<CdtMapReturnType>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = aero::operations::maps::remove_by_key_list(
            &bin_name,
            php_values_to_aero(keys),
            map_return(return_type),
        );
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// MapRemoveByKeyRangeOp creates map remove operation.
    /// Server removes map items identified by key range (keyBegin inclusive, keyEnd exclusive).
    /// If keyBegin is nil, the range is less than keyEnd.
    /// If keyEnd is nil, the range is greater than equal to keyBegin.
    ///
    /// `policy` is accepted for PHP API stability and currently has no effect.
    pub fn remove_by_key_range(
        _policy: &CdtMapPolicy,
        bin_name: String,
        begin: PHPValue,
        end: PHPValue,
        return_type: Option<CdtMapReturnType>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = aero::operations::maps::remove_by_key_range(
            &bin_name,
            begin.into(),
            end.into(),
            map_return(return_type),
        );
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// MapRemoveByValueListOp creates map remove operation.
    /// Server removes map items identified by values and returns removed data specified by returnType.
    pub fn remove_by_values(
        _policy: &CdtMapPolicy,
        bin_name: String,
        values: Vec<PHPValue>,
        return_type: Option<CdtMapReturnType>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = aero::operations::maps::remove_by_value_list(
            &bin_name,
            php_values_to_aero(values),
            map_return(return_type),
        );
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// MapRemoveByValueRangeOp creates map remove operation.
    /// Server removes map items identified by value range (valueBegin inclusive, valueEnd exclusive).
    pub fn remove_by_value_range(
        _policy: &CdtMapPolicy,
        bin_name: String,
        begin: PHPValue,
        end: PHPValue,
        return_type: Option<CdtMapReturnType>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = aero::operations::maps::remove_by_value_range(
            &bin_name,
            begin.into(),
            end.into(),
            map_return(return_type),
        );
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// MapRemoveByValueRelativeRankRangeOp creates a map remove by value relative to rank range operation.
    /// Server removes map items nearest to value and greater by relative rank.
    pub fn remove_by_value_relative_rank_range(
        _policy: &CdtMapPolicy,
        bin_name: String,
        value: PHPValue,
        rank: i64,
        return_type: Option<CdtMapReturnType>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = aero::operations::maps::remove_by_value_relative_rank_range(
            &bin_name,
            value.into(),
            rank,
            map_return(return_type),
        );
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// MapRemoveByValueRelativeRankRangeCountOp creates a map remove by value relative to rank range operation.
    /// Server removes map items nearest to value and greater by relative rank with a count limit.
    pub fn remove_by_value_relative_rank_range_count(
        _policy: &CdtMapPolicy,
        bin_name: String,
        value: PHPValue,
        rank: i64,
        count: i64,
        return_type: Option<CdtMapReturnType>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = aero::operations::maps::remove_by_value_relative_rank_range_count(
            &bin_name,
            value.into(),
            rank,
            count,
            map_return(return_type),
        );
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// MapRemoveByIndexOp creates map remove operation.
    /// Server removes map item identified by index and returns removed data specified by returnType.
    pub fn remove_by_index(
        _policy: &CdtMapPolicy,
        bin_name: String,
        index: i64,
        return_type: Option<CdtMapReturnType>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = aero::operations::maps::remove_by_index(&bin_name, index, map_return(return_type));
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// MapRemoveByIndexRangeOp creates map remove operation.
    /// Server removes map items starting at specified index to the end of map.
    pub fn remove_by_index_range(
        _policy: &CdtMapPolicy,
        bin_name: String,
        index: i64,
        return_type: Option<CdtMapReturnType>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = aero::operations::maps::remove_by_index_range_from(
            &bin_name,
            index,
            map_return(return_type),
        );
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// MapRemoveByIndexRangeCountOp creates map remove operation.
    /// Server removes "count" map items starting at specified index.
    pub fn remove_by_index_range_count(
        _policy: &CdtMapPolicy,
        bin_name: String,
        index: i64,
        count: i64,
        return_type: Option<CdtMapReturnType>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = aero::operations::maps::remove_by_index_range(
            &bin_name,
            index,
            count,
            map_return(return_type),
        );
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// MapRemoveByRankOp creates map remove operation.
    /// Server removes map item identified by rank and returns removed data specified by returnType.
    pub fn remove_by_rank(
        _policy: &CdtMapPolicy,
        bin_name: String,
        rank: i64,
        return_type: Option<CdtMapReturnType>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = aero::operations::maps::remove_by_rank(&bin_name, rank, map_return(return_type));
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// MapRemoveByRankRangeOp creates map remove operation.
    /// Server removes map items starting at specified rank to the last ranked item.
    pub fn remove_by_rank_range(
        _policy: &CdtMapPolicy,
        bin_name: String,
        rank: i64,
        return_type: Option<CdtMapReturnType>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = aero::operations::maps::remove_by_rank_range_from(
            &bin_name,
            rank,
            map_return(return_type),
        );
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// MapRemoveByRankRangeCountOp creates map remove operation.
    /// Server removes "count" map items starting at specified rank.
    pub fn remove_by_rank_range_count(
        _policy: &CdtMapPolicy,
        bin_name: String,
        rank: i64,
        count: i64,
        return_type: Option<CdtMapReturnType>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = aero::operations::maps::remove_by_rank_range(
            &bin_name,
            rank,
            count,
            map_return(return_type),
        );
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// MapRemoveByKeyRelativeIndexRangeOp creates a map remove by key relative to index range operation.
    pub fn remove_by_key_relative_index_range(
        _policy: &CdtMapPolicy,
        bin_name: String,
        key: PHPValue,
        index: i64,
        return_type: Option<CdtMapReturnType>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = aero::operations::maps::remove_by_key_relative_index_range(
            &bin_name,
            key.into(),
            index,
            map_return(return_type),
        );
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// MapRemoveByKeyRelativeIndexRangeCountOp creates map remove by key relative to index range operation.
    pub fn remove_by_key_relative_index_range_count(
        _policy: &CdtMapPolicy,
        bin_name: String,
        key: PHPValue,
        index: i64,
        count: i64,
        return_type: Option<CdtMapReturnType>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = aero::operations::maps::remove_by_key_relative_index_range_count(
            &bin_name,
            key.into(),
            index,
            count,
            map_return(return_type),
        );
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// MapGetByKeyListOp creates a map get by key list operation. Should be used with BatchRead.
    pub fn get_by_keys(
        _policy: &CdtMapPolicy,
        bin_name: String,
        keys: Vec<PHPValue>,
        return_type: Option<CdtMapReturnType>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = aero::operations::maps::get_by_key_list(
            &bin_name,
            php_values_to_aero(keys),
            map_return(return_type),
        );
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// MapGetByKeyRangeOp creates map get by key range operation.
    /// Should be used with BatchRead.
    pub fn get_by_key_range(
        _policy: &CdtMapPolicy,
        bin_name: String,
        begin: PHPValue,
        end: PHPValue,
        return_type: Option<CdtMapReturnType>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = aero::operations::maps::get_by_key_range(
            &bin_name,
            begin.into(),
            end.into(),
            map_return(return_type),
        );
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// MapGetByKeyRelativeIndexRangeOp creates a map get by key relative to index range operation.
    pub fn get_by_key_relative_index_range(
        _policy: &CdtMapPolicy,
        bin_name: String,
        key: PHPValue,
        index: i64,
        return_type: Option<CdtMapReturnType>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = aero::operations::maps::get_by_key_relative_index_range(
            &bin_name,
            key.into(),
            index,
            map_return(return_type),
        );
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// MapGetByKeyRelativeIndexRangeCountOp creates a map get by key relative to index range operation.
    pub fn get_by_key_relative_index_range_count(
        _policy: &CdtMapPolicy,
        bin_name: String,
        key: PHPValue,
        index: i64,
        count: i64,
        return_type: Option<CdtMapReturnType>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = aero::operations::maps::get_by_key_relative_index_range_count(
            &bin_name,
            key.into(),
            index,
            count,
            map_return(return_type),
        );
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// MapGetByValueListOp creates a map get by value list operation. Should be used with BatchRead.
    pub fn get_by_values(
        _policy: &CdtMapPolicy,
        bin_name: String,
        values: Vec<PHPValue>,
        return_type: Option<CdtMapReturnType>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = aero::operations::maps::get_by_value_list(
            &bin_name,
            php_values_to_aero(values),
            map_return(return_type),
        );
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// MapGetByValueRangeOp creates map get by value range operation. Should be used with BatchRead.
    pub fn get_by_value_range(
        _policy: &CdtMapPolicy,
        bin_name: String,
        begin: PHPValue,
        end: PHPValue,
        return_type: Option<CdtMapReturnType>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = aero::operations::maps::get_by_value_range(
            &bin_name,
            begin.into(),
            end.into(),
            map_return(return_type),
        );
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// MapGetByValueRelativeRankRangeOp creates a map get by value relative to rank range operation.
    pub fn get_by_value_relative_rank_range(
        _policy: &CdtMapPolicy,
        bin_name: String,
        value: PHPValue,
        rank: i64,
        return_type: Option<CdtMapReturnType>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = aero::operations::maps::get_by_value_relative_rank_range(
            &bin_name,
            value.into(),
            rank,
            map_return(return_type),
        );
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// MapGetByValueRelativeRankRangeCountOp creates a map get by value relative to rank range operation.
    pub fn get_by_value_relative_rank_range_count(
        _policy: &CdtMapPolicy,
        bin_name: String,
        value: PHPValue,
        rank: i64,
        count: i64,
        return_type: Option<CdtMapReturnType>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = aero::operations::maps::get_by_value_relative_rank_range_count(
            &bin_name,
            value.into(),
            rank,
            count,
            map_return(return_type),
        );
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// MapGetByIndexOp creates map get by index operation. Should be used with BatchRead.
    pub fn get_by_index(
        _policy: &CdtMapPolicy,
        bin_name: String,
        index: i64,
        return_type: Option<CdtMapReturnType>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = aero::operations::maps::get_by_index(&bin_name, index, map_return(return_type));
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// MapGetByIndexRangeOp creates map get by index range operation.
    ///
    /// v2 BREAKING: aerospike-client-rust v2 expects an `i64` index, not a `PHPValue` begin/end
    /// pair. The previous proto-based signature was incompatible with the wire protocol and is
    /// replaced by `(index: i64)`. Server selects map items starting at the specified index to
    /// the end of the map. Should be used with BatchRead.
    pub fn get_by_index_range(
        _policy: &CdtMapPolicy,
        bin_name: String,
        index: i64,
        return_type: Option<CdtMapReturnType>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = aero::operations::maps::get_by_index_range_from(
            &bin_name,
            index,
            map_return(return_type),
        );
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// MapGetByIndexRangeCountOp creates map get by index range operation.
    ///
    /// v2 BREAKING: the previous `rank` parameter (a copy-paste artifact from
    /// `get_by_rank_range_count`) is removed. New signature is `(index, count)`.
    pub fn get_by_index_range_count(
        _policy: &CdtMapPolicy,
        bin_name: String,
        index: i64,
        count: i64,
        return_type: Option<CdtMapReturnType>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = aero::operations::maps::get_by_index_range(
            &bin_name,
            index,
            count,
            map_return(return_type),
        );
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// MapGetByRankOp creates map get by rank operation. Should be used with BatchRead.
    pub fn get_by_rank(
        _policy: &CdtMapPolicy,
        bin_name: String,
        rank: i64,
        return_type: Option<CdtMapReturnType>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = aero::operations::maps::get_by_rank(&bin_name, rank, map_return(return_type));
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// MapGetByRankRangeOp creates map get by rank range operation.
    ///
    /// v2 BREAKING: signature changed from `(begin, end: PHPValue)` to `(rank: i64)`.
    /// Server selects map items starting at the specified rank to the last ranked item.
    pub fn get_by_rank_range(
        _policy: &CdtMapPolicy,
        bin_name: String,
        rank: i64,
        return_type: Option<CdtMapReturnType>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = aero::operations::maps::get_by_rank_range_from(
            &bin_name,
            rank,
            map_return(return_type),
        );
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// MapGetByRankRangeCountOp creates map get by rank range operation.
    ///
    /// v2 BREAKING: the previous `range` parameter (copy-paste artifact) is removed.
    /// New signature is `(rank, count)`.
    pub fn get_by_rank_range_count(
        _policy: &CdtMapPolicy,
        bin_name: String,
        rank: i64,
        count: i64,
        return_type: Option<CdtMapReturnType>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = aero::operations::maps::get_by_rank_range(
            &bin_name,
            rank,
            count,
            map_return(return_type),
        );
        Operation {
            _as: with_ctx(op, ctx),
        }
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  CdtHllWriteFlags
//
////////////////////////////////////////////////////////////////////////////////////////////

/// HLLWriteFlags specifies the HLL write operation flags.
#[php_class]
#[php(name = "Aerospike\\HllWriteFlags")]
#[derive(Debug, Clone)]
pub struct CdtHllWriteFlags {
    _as: aero::operations::hll::HLLWriteFlags,
}

#[php_impl]
impl CdtHllWriteFlags {
    /// HLLWriteFlagsDefault is Default. Allow create or update.
    pub fn Default() -> Self {
        Self {
            _as: aero::operations::hll::HLLWriteFlags::Default,
        }
    }

    /// HLLWriteFlagsCreateOnly behaves like the following:
    /// If the bin already exists, the operation will be denied.
    /// If the bin does not exist, a new bin will be created.
    pub fn Create_Only() -> Self {
        Self {
            _as: aero::operations::hll::HLLWriteFlags::CreateOnly,
        }
    }

    /// HLLWriteFlagsUpdateOnly behaves like the following:
    /// If the bin already exists, the bin will be overwritten.
    /// If the bin does not exist, the operation will be denied.
    pub fn Update_Only() -> Self {
        Self {
            _as: aero::operations::hll::HLLWriteFlags::UpdateOnly,
        }
    }

    /// HLLWriteFlagsNoFail does not raise error if operation is denied.
    pub fn No_Fail() -> Self {
        Self {
            _as: aero::operations::hll::HLLWriteFlags::NoFail,
        }
    }

    /// HLLWriteFlagsAllowFold allows the resulting set to be the minimum of provided index bits.
    /// Also, allow the usage of less precise HLL algorithms when minHash bits
    /// of all participating sets do not match.
    pub fn Allow_Fold() -> Self {
        Self {
            _as: aero::operations::hll::HLLWriteFlags::AllowFold,
        }
    }
}

impl FromZval<'_> for CdtHllWriteFlags {
    const TYPE: DataType = DataType::Mixed;

    fn from_zval(zval: &Zval) -> Option<Self> {
        let f: &CdtHllWriteFlags = zval.extract()?;

        Some(CdtHllWriteFlags { _as: f._as })
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  CdtHllPolicy
//
////////////////////////////////////////////////////////////////////////////////////////////

/// HLLPolicy determines the HyperLogLog operation policy.
#[php_class]
#[php(name = "Aerospike\\HllPolicy")]
#[derive(Debug, Clone, Default)]
pub struct CdtHllPolicy {
    _as: aero::operations::hll::HLLPolicy,
}

#[php_impl]
impl CdtHllPolicy {
    /// new HLLPolicy uses specified optional HLLWriteFlags when performing HLL operations.
    pub fn __construct(flags: Option<CdtHllWriteFlags>) -> Self {
        let write_flags = flags
            .map(|f| f._as)
            .unwrap_or(aero::operations::hll::HLLWriteFlags::Default);

        // DefaultHLLPolicy uses the default policy when performing HLL operations.
        CdtHllPolicy {
            _as: aero::operations::hll::HLLPolicy::new(write_flags),
        }
    }
}

impl FromZval<'_> for CdtHllPolicy {
    const TYPE: DataType = DataType::Mixed;

    fn from_zval(zval: &Zval) -> Option<Self> {
        let f: &CdtHllPolicy = zval.extract()?;

        Some(CdtHllPolicy { _as: f._as })
    }
}

///////////////////////////////////////////////////////////////////////////////////////////
//
//  CdtHllOperation
//
////////////////////////////////////////////////////////////////////////////////////////////

/// HyperLogLog (HLL) operations.
/// Requires server versions >= 4.9.
///
/// HyperLogLog operations on HLL items nested in lists/maps are not currently
/// supported by the server.
#[php_class]
#[php(name = "Aerospike\\HllOp")]
#[derive(Clone)]
pub struct CdtHllOperation {
    _as: aero::operations::Operation,
}

impl FromZval<'_> for CdtHllOperation {
    const TYPE: DataType = DataType::Mixed;

    fn from_zval(zval: &Zval) -> Option<Self> {
        let f: &CdtHllOperation = zval.extract()?;

        Some(CdtHllOperation { _as: f._as.clone() })
    }
}

#[php_impl]
impl CdtHllOperation {
    /// HLLInitOp creates HLL init operation with minhash bits.
    /// Server creates a new HLL or resets an existing HLL.
    /// Server does not return a value.
    ///
    /// policy            write policy, use DefaultHLLPolicy for default
    /// binName           name of bin
    /// indexBitCount     number of index bits. Must be between 4 and 16 inclusive. Pass -1 for default.
    /// minHashBitCount   number of min hash bits. Must be between 4 and 58 inclusive. Pass -1 for default.
    /// indexBitCount + minHashBitCount must be <= 64.
    pub fn init(
        policy: &CdtHllPolicy,
        bin_name: String,
        index_bit_count: i64,
        min_hash_bit_count: i64,
    ) -> Operation {
        Operation {
            _as: aero::operations::hll::init_with_min_hash(
                &policy._as,
                &bin_name,
                index_bit_count,
                min_hash_bit_count,
            ),
        }
    }

    /// HLLAddOp creates HLL add operation with minhash bits.
    /// Server adds values to HLL set. If HLL bin does not exist, use indexBitCount and minHashBitCount
    /// to create HLL bin. Server returns number of entries that caused HLL to update a register.
    pub fn add(
        policy: &CdtHllPolicy,
        bin_name: String,
        list: Vec<PHPValue>,
        index_bit_count: i64,
        min_hash_bit_count: i64,
    ) -> Operation {
        Operation {
            _as: aero::operations::hll::add_with_index_and_min_hash(
                &policy._as,
                &bin_name,
                php_values_to_aero(list),
                index_bit_count,
                min_hash_bit_count,
            ),
        }
    }

    /// HLLSetUnionOp creates HLL set union operation.
    /// Server sets union of specified HLL objects with HLL bin.
    /// Returns `None` if any element of `list` is not an HLL value.
    pub fn set_union(
        policy: &CdtHllPolicy,
        bin_name: String,
        list: Vec<PHPValue>,
    ) -> Option<Operation> {
        if !assert_hll_list(&list) {
            return None;
        }
        Some(Operation {
            _as: aero::operations::hll::set_union(&policy._as, &bin_name, php_values_to_aero(list)),
        })
    }

    /// HLLRefreshCountOp creates HLL refresh operation.
    /// Server updates the cached count (if stale) and returns the count.
    pub fn refresh_count(bin_name: String) -> Option<Operation> {
        Some(Operation {
            _as: aero::operations::hll::refresh_count(&bin_name),
        })
    }

    /// HLLFoldOp creates HLL fold operation. Server folds indexBitCount to the specified value.
    /// This can only be applied when minHashBitCount on the HLL bin is 0.
    pub fn fold(bin_name: String, index_bit_count: i64) -> Option<Operation> {
        Some(Operation {
            _as: aero::operations::hll::fold(&bin_name, index_bit_count),
        })
    }

    /// HLLGetCountOp creates HLL getCount operation.
    /// Server returns estimated number of elements in the HLL bin.
    pub fn get_count(bin_name: String) -> Option<Operation> {
        Some(Operation {
            _as: aero::operations::hll::get_count(&bin_name),
        })
    }

    /// HLLGetUnionOp creates HLL getUnion operation.
    /// Server returns an HLL object that is the union of all specified HLL objects in the list
    /// with the HLL bin. Returns `None` if any element of `list` is not an HLL value.
    pub fn get_union(bin_name: String, list: Vec<PHPValue>) -> Option<Operation> {
        if !assert_hll_list(&list) {
            return None;
        }
        Some(Operation {
            _as: aero::operations::hll::get_union(&bin_name, php_values_to_aero(list)),
        })
    }

    /// HLLGetUnionCountOp creates HLL getUnionCount operation.
    /// Server returns estimated number of elements that would be contained by the union of these
    /// HLL objects.
    pub fn get_union_count(bin_name: String, list: Vec<PHPValue>) -> Option<Operation> {
        if !assert_hll_list(&list) {
            return None;
        }
        Some(Operation {
            _as: aero::operations::hll::get_union_count(&bin_name, php_values_to_aero(list)),
        })
    }

    /// HLLGetIntersectCountOp creates HLL getIntersectCount operation.
    /// Server returns estimated number of elements that would be contained by the intersection of
    /// these HLL objects.
    pub fn get_intersect_count(bin_name: String, list: Vec<PHPValue>) -> Option<Operation> {
        if !assert_hll_list(&list) {
            return None;
        }
        Some(Operation {
            _as: aero::operations::hll::get_intersect_count(&bin_name, php_values_to_aero(list)),
        })
    }

    /// HLLGetSimilarityOp creates HLL getSimilarity operation.
    /// Server returns estimated similarity of these HLL objects. Return type is a double.
    pub fn get_similarity(bin_name: String, list: Vec<PHPValue>) -> Option<Operation> {
        if !assert_hll_list(&list) {
            return None;
        }
        Some(Operation {
            _as: aero::operations::hll::get_similarity(&bin_name, php_values_to_aero(list)),
        })
    }

    /// HLLDescribeOp creates HLL describe operation.
    /// Server returns indexBitCount and minHashBitCount used to create HLL bin in a list of longs.
    /// The list size is 2.
    pub fn describe(bin_name: String) -> Operation {
        Operation {
            _as: aero::operations::hll::describe(&bin_name),
        }
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  CdtBitwiseWriteFlags
//
////////////////////////////////////////////////////////////////////////////////////////////

/// BitWriteFlags specify bitwise operation policy write flags.
#[php_class]
#[php(name = "Aerospike\\BitwiseWriteFlags")]
#[derive(Debug, Clone)]
pub struct CdtBitwiseWriteFlags {
    _as: aero::operations::bitwise::BitwiseWriteFlags,
}

#[php_impl]
impl CdtBitwiseWriteFlags {
    /// BitWriteFlagsDefault allows create or update.
    pub fn Default() -> Self {
        Self {
            _as: aero::operations::bitwise::BitwiseWriteFlags::Default,
        }
    }

    /// BitWriteFlagsCreateOnly specifies that:
    /// If the bin already exists, the operation will be denied.
    /// If the bin does not exist, a new bin will be created.
    pub fn Create_Only() -> Self {
        Self {
            _as: aero::operations::bitwise::BitwiseWriteFlags::CreateOnly,
        }
    }

    /// BitWriteFlagsUpdateOnly specifies that:
    /// If the bin already exists, the bin will be overwritten.
    /// If the bin does not exist, the operation will be denied.
    pub fn Update_Only() -> Self {
        Self {
            _as: aero::operations::bitwise::BitwiseWriteFlags::UpdateOnly,
        }
    }

    /// BitWriteFlagsNoFail specifies not to raise error if operation is denied.
    pub fn No_Fail() -> Self {
        Self {
            _as: aero::operations::bitwise::BitwiseWriteFlags::NoFail,
        }
    }

    /// BitWriteFlagsPartial allows other valid operations to be committed if this operations is
    /// denied due to flag constraints.
    pub fn Partial() -> Self {
        Self {
            _as: aero::operations::bitwise::BitwiseWriteFlags::Partial,
        }
    }
}

impl FromZval<'_> for CdtBitwiseWriteFlags {
    const TYPE: DataType = DataType::Mixed;

    fn from_zval(zval: &Zval) -> Option<Self> {
        let f: &CdtBitwiseWriteFlags = zval.extract()?;

        Some(CdtBitwiseWriteFlags { _as: f._as.clone() })
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  CdtBitwiseResizeFlags
//
////////////////////////////////////////////////////////////////////////////////////////////

/// BitResizeFlags specifies the bitwise operation flags for resize.
#[php_class]
#[php(name = "Aerospike\\BitwiseResizeFlags")]
#[derive(Debug, Clone)]
pub struct CdtBitwiseResizeFlags {
    _as: aero::operations::bitwise::BitwiseResizeFlags,
}

#[php_impl]
impl CdtBitwiseResizeFlags {
    /// BitResizeFlagsDefault specifies the default flag.
    pub fn Default() -> Self {
        Self {
            _as: aero::operations::bitwise::BitwiseResizeFlags::Default,
        }
    }

    /// BitResizeFlagsFromFront Adds/removes bytes from the beginning instead of the end.
    pub fn From_Front() -> Self {
        Self {
            _as: aero::operations::bitwise::BitwiseResizeFlags::FromFront,
        }
    }

    /// BitResizeFlagsGrowOnly will only allow the []byte size to increase.
    pub fn Grow_Only() -> Self {
        Self {
            _as: aero::operations::bitwise::BitwiseResizeFlags::GrowOnly,
        }
    }

    /// BitResizeFlagsShrinkOnly will only allow the []byte size to decrease.
    pub fn Shrink_Only() -> Self {
        Self {
            _as: aero::operations::bitwise::BitwiseResizeFlags::ShrinkOnly,
        }
    }
}

impl FromZval<'_> for CdtBitwiseResizeFlags {
    const TYPE: DataType = DataType::Mixed;

    fn from_zval(zval: &Zval) -> Option<Self> {
        let f: &CdtBitwiseResizeFlags = zval.extract()?;

        Some(CdtBitwiseResizeFlags { _as: f._as.clone() })
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  CdtBitwiseOverflowAction
//
////////////////////////////////////////////////////////////////////////////////////////////

/// BitOverflowAction specifies the action to take when bitwise add/subtract results in overflow/underflow.
/// Note: the backing aero type is `BitwiseOverflowActions` (plural).
#[php_class]
#[php(name = "Aerospike\\BitwiseOverflowAction")]
#[derive(Debug, Clone)]
pub struct CdtBitwiseOverflowAction {
    _as: aero::operations::bitwise::BitwiseOverflowActions,
}

#[php_impl]
impl CdtBitwiseOverflowAction {
    /// BitOverflowActionFail specifies to fail operation with error.
    pub fn Fail() -> Self {
        Self {
            _as: aero::operations::bitwise::BitwiseOverflowActions::Fail,
        }
    }

    /// BitOverflowActionSaturate specifies that in add/subtract overflows/underflows, set to max/min value.
    /// Example: MAXINT + 1 = MAXINT
    pub fn Saturate() -> Self {
        Self {
            _as: aero::operations::bitwise::BitwiseOverflowActions::Saturate,
        }
    }

    /// BitOverflowActionWrap specifies that in add/subtract overflows/underflows, wrap the value.
    /// Example: MAXINT + 1 = -1
    pub fn Wrap() -> Self {
        Self {
            _as: aero::operations::bitwise::BitwiseOverflowActions::Wrap,
        }
    }
}

impl FromZval<'_> for CdtBitwiseOverflowAction {
    const TYPE: DataType = DataType::Mixed;

    fn from_zval(zval: &Zval) -> Option<Self> {
        let f: &CdtBitwiseOverflowAction = zval.extract()?;

        Some(CdtBitwiseOverflowAction { _as: f._as.clone() })
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  CdtBitwisePolicy
//
////////////////////////////////////////////////////////////////////////////////////////////

/// BitPolicy determines the Bit operation policy.
/// Note: the backing aero type is `BitPolicy` (not BitwisePolicy).
#[php_class]
#[php(name = "Aerospike\\BitwisePolicy")]
#[derive(Debug, Clone, Default)]
pub struct CdtBitwisePolicy {
    _as: aero::operations::bitwise::BitPolicy,
}

#[php_impl]
impl CdtBitwisePolicy {
    /// new BitwisePolicy(flags) will return a BitPolicy with provided write flags.
    pub fn __construct(flags: Option<CdtBitwiseWriteFlags>) -> Self {
        let flag_byte = flags
            .map(|f| f._as as u8)
            .unwrap_or(aero::operations::bitwise::BitwiseWriteFlags::Default as u8);

        CdtBitwisePolicy {
            _as: aero::operations::bitwise::BitPolicy::new(flag_byte),
        }
    }
}

impl FromZval<'_> for CdtBitwisePolicy {
    const TYPE: DataType = DataType::Mixed;

    fn from_zval(zval: &Zval) -> Option<Self> {
        let f: &CdtBitwisePolicy = zval.extract()?;

        Some(CdtBitwisePolicy { _as: f._as })
    }
}

///////////////////////////////////////////////////////////////////////////////////////////
//
//  CdtBitwiseOperation
//
////////////////////////////////////////////////////////////////////////////////////////////

/// Bit operations. Create bit operations used by client operate command.
/// Offset orientation is left-to-right.  Negative offsets are supported.
/// If the offset is negative, the offset starts backwards from end of the bitmap.
/// If an offset is out of bounds, a parameter error will be returned.
///
///	Nested CDT operations are supported by optional CTX context arguments.  Example:
///	bin = [[0b00000001, 0b01000010],[0b01011010]]
///	Resize first bitmap (in a list of bitmaps) to 3 bytes.
///	BitOperation.resize("bin", 3, BitResizeFlags.DEFAULT, CTX.listIndex(0))
///	bin result = [[0b00000001, 0b01000010, 0b00000000],[0b01011010]]
#[php_class]
#[php(name = "Aerospike\\BitwiseOp")]
#[derive(Clone)]
pub struct CdtBitwiseOperation {
    _as: aero::operations::Operation,
}

impl FromZval<'_> for CdtBitwiseOperation {
    const TYPE: DataType = DataType::Mixed;

    fn from_zval(zval: &Zval) -> Option<Self> {
        let f: &CdtBitwiseOperation = zval.extract()?;

        Some(CdtBitwiseOperation { _as: f._as.clone() })
    }
}

#[php_impl]
impl CdtBitwiseOperation {
    /// BitResizeOp creates byte "resize" operation. Server resizes []byte to byteSize
    /// according to resizeFlags. Server does not return a value.
    pub fn resize(
        policy: &CdtBitwisePolicy,
        bin_name: String,
        byte_size: i64,
        resize_flags: Option<CdtBitwiseResizeFlags>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = aero::operations::bitwise::resize(
            &bin_name,
            byte_size,
            resize_flags.map(|rf| rf._as),
            &policy._as,
        );
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// BitInsertOp creates byte "insert" operation. Server inserts value bytes into []byte bin
    /// at byteOffset. Server does not return a value.
    pub fn insert(
        policy: &CdtBitwisePolicy,
        bin_name: String,
        byte_offset: i64,
        value: Vec<u8>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = aero::operations::bitwise::insert(
            &bin_name,
            byte_offset,
            aero::Value::Blob(value),
            &policy._as,
        );
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// BitRemoveOp creates byte "remove" operation. Server removes bytes from []byte bin at
    /// byteOffset for byteSize. Server does not return a value.
    pub fn remove(
        policy: &CdtBitwisePolicy,
        bin_name: String,
        byte_offset: i64,
        byte_size: i64,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = aero::operations::bitwise::remove(&bin_name, byte_offset, byte_size, &policy._as);
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// BitSetOp creates bit "set" operation. Server sets value on []byte bin at bitOffset for
    /// bitSize. Server does not return a value.
    pub fn set(
        policy: &CdtBitwisePolicy,
        bin_name: String,
        bit_offset: i64,
        bit_size: i64,
        value: Vec<u8>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = aero::operations::bitwise::set(
            &bin_name,
            bit_offset,
            bit_size,
            aero::Value::Blob(value),
            &policy._as,
        );
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// BitOrOp creates bit "or" operation.
    pub fn or(
        policy: &CdtBitwisePolicy,
        bin_name: String,
        bit_offset: i64,
        bit_size: i64,
        value: Vec<u8>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = aero::operations::bitwise::or(
            &bin_name,
            bit_offset,
            bit_size,
            aero::Value::Blob(value),
            &policy._as,
        );
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// BitXorOp creates bit "exclusive or" operation.
    pub fn xor(
        policy: &CdtBitwisePolicy,
        bin_name: String,
        bit_offset: i64,
        bit_size: i64,
        value: Vec<u8>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = aero::operations::bitwise::xor(
            &bin_name,
            bit_offset,
            bit_size,
            aero::Value::Blob(value),
            &policy._as,
        );
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// BitAndOp creates bit "and" operation.
    pub fn and(
        policy: &CdtBitwisePolicy,
        bin_name: String,
        bit_offset: i64,
        bit_size: i64,
        value: Vec<u8>,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = aero::operations::bitwise::and(
            &bin_name,
            bit_offset,
            bit_size,
            aero::Value::Blob(value),
            &policy._as,
        );
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// BitNotOp creates bit "not" operation. Server negates []byte bin starting at bitOffset
    /// for bitSize.
    pub fn not(
        policy: &CdtBitwisePolicy,
        bin_name: String,
        bit_offset: i64,
        bit_size: i64,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = aero::operations::bitwise::not(&bin_name, bit_offset, bit_size, &policy._as);
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// BitLShiftOp creates bit "left shift" operation.
    pub fn lshift(
        policy: &CdtBitwisePolicy,
        bin_name: String,
        bit_offset: i64,
        bit_size: i64,
        shift: i64,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op =
            aero::operations::bitwise::lshift(&bin_name, bit_offset, bit_size, shift, &policy._as);
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// BitRShiftOp creates bit "right shift" operation.
    pub fn rshift(
        policy: &CdtBitwisePolicy,
        bin_name: String,
        bit_offset: i64,
        bit_size: i64,
        shift: i64,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op =
            aero::operations::bitwise::rshift(&bin_name, bit_offset, bit_size, shift, &policy._as);
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// BitAddOp creates bit "add" operation. Server adds value to []byte bin starting at
    /// bitOffset for bitSize. bitSize must be <= 64.
    pub fn add(
        policy: &CdtBitwisePolicy,
        bin_name: String,
        bit_offset: i64,
        bit_size: i64,
        value: i64,
        signed: bool,
        action: CdtBitwiseOverflowAction,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = aero::operations::bitwise::add(
            &bin_name,
            bit_offset,
            bit_size,
            value,
            signed,
            action._as.clone(),
            &policy._as,
        );
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// BitSubtractOp creates bit "subtract" operation.
    pub fn subtract(
        policy: &CdtBitwisePolicy,
        bin_name: String,
        bit_offset: i64,
        bit_size: i64,
        value: i64,
        signed: bool,
        action: CdtBitwiseOverflowAction,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op = aero::operations::bitwise::subtract(
            &bin_name,
            bit_offset,
            bit_size,
            value,
            signed,
            action._as.clone(),
            &policy._as,
        );
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// BitSetIntOp creates bit "setInt" operation. Server sets value to []byte bin starting at
    /// bitOffset for bitSize. Size must be <= 64.
    pub fn set_int(
        policy: &CdtBitwisePolicy,
        bin_name: String,
        bit_offset: i64,
        bit_size: i64,
        value: i64,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        let op =
            aero::operations::bitwise::set_int(&bin_name, bit_offset, bit_size, value, &policy._as);
        Operation {
            _as: with_ctx(op, ctx),
        }
    }

    /// BitGetOp creates bit "get" operation. Server returns bits from []byte bin starting at
    /// bitOffset for bitSize.
    pub fn get(
        bin_name: String,
        bit_offset: i64,
        bit_size: i64,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        Operation {
            _as: with_ctx(
                aero::operations::bitwise::get(&bin_name, bit_offset, bit_size),
                ctx,
            ),
        }
    }

    /// BitCountOp creates bit "count" operation. Server returns count of set bits from []byte
    /// bin starting at bitOffset for bitSize.
    pub fn count(
        bin_name: String,
        bit_offset: i64,
        bit_size: i64,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        Operation {
            _as: with_ctx(
                aero::operations::bitwise::count(&bin_name, bit_offset, bit_size),
                ctx,
            ),
        }
    }

    /// BitLScanOp creates bit "left scan" operation. Server returns offset of the first
    /// specified value bit in []byte bin starting at bitOffset for bitSize.
    pub fn lscan(
        bin_name: String,
        bit_offset: i64,
        bit_size: i64,
        value: bool,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        Operation {
            _as: with_ctx(
                aero::operations::bitwise::lscan(&bin_name, bit_offset, bit_size, value),
                ctx,
            ),
        }
    }

    /// BitRScanOp creates bit "right scan" operation. Server returns offset of the last
    /// specified value bit in []byte bin starting at bitOffset for bitSize.
    pub fn rscan(
        bin_name: String,
        bit_offset: i64,
        bit_size: i64,
        value: bool,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        Operation {
            _as: with_ctx(
                aero::operations::bitwise::rscan(&bin_name, bit_offset, bit_size, value),
                ctx,
            ),
        }
    }

    /// BitGetIntOp creates bit "get integer" operation. Server returns integer from []byte bin
    /// starting at bitOffset for bitSize. Signed indicates if bits should be treated as a
    /// signed number.
    pub fn get_int(
        bin_name: String,
        bit_offset: i64,
        bit_size: i64,
        signed: bool,
        ctx: Option<Vec<&CDTContext>>,
    ) -> Operation {
        Operation {
            _as: with_ctx(
                aero::operations::bitwise::get_int(&bin_name, bit_offset, bit_size, signed),
                ctx,
            ),
        }
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  ClientPolicy
//
////////////////////////////////////////////////////////////////////////////////////////////

/// Build a rustls `ClientConfig` for the Aerospike client from PEM files on disk.
///
/// Returns a fully-configured `tokio_rustls::rustls::ClientConfig`:
/// - When `ca_file` is `None`, Mozilla's webpki-roots bundle is the trust store.
/// - When `cert_file` + `key_file` are both `Some`, mutual TLS is configured.
/// - Providing exactly one of `cert_file`/`key_file` is a usage error.
fn build_tls_config(
    ca_file: Option<&str>,
    cert_file: Option<&str>,
    key_file: Option<&str>,
) -> PhpResult<tokio_rustls::rustls::ClientConfig> {
    use std::fs::File;
    use std::io::BufReader;
    use tokio_rustls::rustls::pki_types::CertificateDer;
    use tokio_rustls::rustls::{ClientConfig, RootCertStore};

    let mut roots = RootCertStore::empty();
    match ca_file {
        Some(path) => {
            let file = File::open(path).map_err(|e| {
                PhpException::default(format!("TLS: cannot open ca_file '{path}': {e}"))
            })?;
            let mut reader = BufReader::new(file);
            let mut added = 0usize;
            for cert in rustls_pemfile::certs(&mut reader) {
                let cert = cert.map_err(|e| {
                    PhpException::default(format!("TLS: failed to parse ca_file '{path}': {e}"))
                })?;
                roots.add(cert).map_err(|e| {
                    PhpException::default(format!("TLS: rejected ca_file cert '{path}': {e}"))
                })?;
                added += 1;
            }
            if added == 0 {
                return Err(PhpException::default(format!(
                    "TLS: ca_file '{path}' contained no PEM certificates"
                )));
            }
        }
        None => {
            // Mozilla's CA bundle for the common case (public TLS endpoints).
            roots.extend(webpki_roots::TLS_SERVER_ROOTS.iter().cloned());
        }
    }

    let builder = ClientConfig::builder().with_root_certificates(roots);

    let cfg = match (cert_file, key_file) {
        (Some(cert_path), Some(key_path)) => {
            // Client certificate chain.
            let cert_file = File::open(cert_path).map_err(|e| {
                PhpException::default(format!("TLS: cannot open cert_file '{cert_path}': {e}"))
            })?;
            let mut cert_reader = BufReader::new(cert_file);
            let mut chain: Vec<CertificateDer<'static>> = Vec::new();
            for cert in rustls_pemfile::certs(&mut cert_reader) {
                let cert = cert.map_err(|e| {
                    PhpException::default(format!(
                        "TLS: failed to parse cert_file '{cert_path}': {e}"
                    ))
                })?;
                chain.push(cert);
            }
            if chain.is_empty() {
                return Err(PhpException::default(format!(
                    "TLS: cert_file '{cert_path}' contained no PEM certificates"
                )));
            }

            // Private key — accept PKCS#8, PKCS#1, or SEC1.
            let key_file = File::open(key_path).map_err(|e| {
                PhpException::default(format!("TLS: cannot open key_file '{key_path}': {e}"))
            })?;
            let mut key_reader = BufReader::new(key_file);
            let key = rustls_pemfile::private_key(&mut key_reader)
                .map_err(|e| {
                    PhpException::default(format!("TLS: failed to read key_file '{key_path}': {e}"))
                })?
                .ok_or_else(|| {
                    PhpException::default(format!(
                        "TLS: key_file '{key_path}' contained no parseable private key"
                    ))
                })?;

            builder
                .with_client_auth_cert(chain, key)
                .map_err(|e| PhpException::default(format!("TLS: invalid client cert/key: {e}")))?
        }
        (None, None) => builder.with_no_client_auth(),
        (Some(_), None) | (None, Some(_)) => {
            return Err(PhpException::default(
                "TLS: cert_file and key_file must be provided together (or both omitted)"
                    .to_string(),
            ));
        }
    };

    Ok(cfg)
}

/// `ClientPolicy` encapsulates parameters for creating a new `Client`. Pass an optional
/// `ClientPolicy` to `Client::connect(hosts, ?policy)` to control authentication, connection
/// pooling, cluster tending, IP translation, and TLS.
///
/// TLS is opt-in via `set_tls(ca_file, cert_file, key_file, server_name)`. With TLS disabled
/// (the default), connections are clear-text.
#[php_class]
#[php(name = "Aerospike\\ClientPolicy")]
#[derive(Clone, Default)]
pub struct ClientPolicy {
    _as: aero::ClientPolicy,
    /// SipHash13 of (ca_bytes, cert_bytes, key_bytes, server_name) computed when `set_tls()`
    /// succeeds. `0` when TLS is disabled. Mixed into `fingerprint()` so two policies with
    /// the same hosts but different mTLS material do not share a cached Client (this is what
    /// breaks cert rotation and lets two LDAP users with different certs reuse each other's
    /// pool).
    tls_fingerprint: u64,
}

impl FromZval<'_> for ClientPolicy {
    const TYPE: DataType = DataType::Mixed;

    fn from_zval(zval: &Zval) -> Option<Self> {
        let f: &ClientPolicy = zval.extract()?;
        Some(ClientPolicy {
            _as: f._as.clone(),
            tls_fingerprint: f.tls_fingerprint,
        })
    }
}

/// Hashes the raw bytes of CA / client cert / client key files plus the server name to
/// produce a stable per-process fingerprint of the TLS material. Used by `ClientPolicy`
/// so cert rotation (or two distinct identities sharing a hosts string) invalidates the
/// cached `Arc<Client>` instead of silently reusing the previous one.
fn tls_material_fingerprint(
    ca: Option<&str>,
    cert: Option<&str>,
    key: Option<&str>,
    server_name: Option<&str>,
) -> u64 {
    use std::collections::hash_map::DefaultHasher;
    use std::hash::{Hash, Hasher};

    let mut h = DefaultHasher::new();
    "tls_v1".hash(&mut h);
    ca.is_some().hash(&mut h);
    cert.is_some().hash(&mut h);
    key.is_some().hash(&mut h);
    server_name.hash(&mut h);

    for path in [ca, cert, key].into_iter().flatten() {
        match std::fs::read(path) {
            Ok(bytes) => {
                bytes.len().hash(&mut h);
                bytes.hash(&mut h);
            }
            // If the file is unreadable, `build_tls_config` will surface the IO error to
            // the caller. Mix the path itself so a missing-file fingerprint still differs
            // from a clear-text one.
            Err(_) => path.hash(&mut h),
        }
    }

    h.finish()
}

#[php_impl]
impl ClientPolicy {
    pub fn __construct() -> Self {
        let mut p = ClientPolicy::default();
        if let Some(v) = ini_long_positive(&INI_TEND_INTERVAL) {
            p._as.tend_interval = v;
        }
        if let Some(v) = ini_long_positive(&INI_CONNECT_TIMEOUT) {
            p._as.timeout = v;
        }
        p
    }

    /// Configure internal authentication. The server stores a hashed password; the client never
    /// sends the password in clear over the wire. This is the recommended default when running
    /// against a security-enabled cluster.
    pub fn set_auth(&mut self, user: String, password: String) {
        self._as.auth_mode = aero::AuthMode::Internal(user, password);
    }

    /// Configure external authentication (LDAP). The password is sent in clear at login,
    /// so `Client::connect()` will throw an `AerospikeException` unless `set_tls(...)`
    /// has also been called on this policy. The check fires at connect time rather than
    /// here because the order of `set_auth_external` / `set_tls` calls is up to the user.
    pub fn set_auth_external(&mut self, user: String, password: String) {
        self._as.auth_mode = aero::AuthMode::External(user, password);
    }

    /// Configure PKI authentication. Requires server v5.7+ and TLS configured with a client
    /// certificate via `set_tls(ca_file, Some(cert_file), Some(key_file), None)`. No
    /// user/password is required — identity is derived from the certificate.
    pub fn set_auth_pki(&mut self) {
        self._as.auth_mode = aero::AuthMode::PKI;
    }

    /// Disable authentication. The default for an unsecured cluster.
    pub fn set_auth_none(&mut self) {
        self._as.auth_mode = aero::AuthMode::None;
    }

    /// Enable TLS for cluster connections.
    ///
    /// * `ca_file` — path to a PEM file with one or more trusted root certificates. When
    ///   `None`, Mozilla's webpki-roots bundle is used as the trust anchor set.
    /// * `cert_file` / `key_file` — when both are provided, configure mutual TLS using a
    ///   client certificate chain (PEM) and a private key (PEM, PKCS#8 / PKCS#1 / SEC1).
    ///   Provide both or neither; mixing one with the other is rejected.
    /// * `server_name` — currently unused (rustls validates the SNI/peer name supplied by
    ///   the aerospike client during connect). Accepted for forward compatibility.
    ///
    /// Throws an `AerospikeException` if a file is missing, contains no parseable
    /// certificates, or the key cannot be loaded. After this call the file contents are
    /// hashed into `fingerprint()` so cached clients are not reused across cert rotation
    /// or distinct mTLS identities.
    pub fn set_tls(
        &mut self,
        ca_file: Option<String>,
        cert_file: Option<String>,
        key_file: Option<String>,
        server_name: Option<String>,
    ) -> PhpResult<()> {
        let cfg = build_tls_config(
            ca_file.as_deref(),
            cert_file.as_deref(),
            key_file.as_deref(),
        )?;
        self.tls_fingerprint = tls_material_fingerprint(
            ca_file.as_deref(),
            cert_file.as_deref(),
            key_file.as_deref(),
            server_name.as_deref(),
        );
        self._as.tls_config = Some(cfg);
        Ok(())
    }

    /// Disable TLS, reverting the policy to clear-text connections.
    pub fn set_tls_none(&mut self) {
        self._as.tls_config = None;
        self.tls_fingerprint = 0;
    }

    /// Returns true if a TLS configuration is attached to this policy.
    pub fn get_tls_enabled(&self) -> bool {
        self._as.tls_config.is_some()
    }

    /// Username for internal or external authentication, if set.
    pub fn get_user(&self) -> Option<String> {
        match &self._as.auth_mode {
            aero::AuthMode::Internal(u, _) | aero::AuthMode::External(u, _) => Some(u.clone()),
            _ => None,
        }
    }

    /// Authentication mode as a lowercase string: "none", "internal", "external", or "pki".
    pub fn get_auth_mode(&self) -> String {
        match &self._as.auth_mode {
            aero::AuthMode::None => "none",
            aero::AuthMode::Internal(_, _) => "internal",
            aero::AuthMode::External(_, _) => "external",
            aero::AuthMode::PKI => "pki",
        }
        .into()
    }

    /// Expected cluster name. If set, server nodes must return this name during cluster tending
    /// or they are excluded from the cluster view.
    pub fn get_cluster_name(&self) -> Option<String> {
        self._as.cluster_name.clone()
    }
    pub fn set_cluster_name(&mut self, name: Option<String>) {
        self._as.cluster_name = name;
    }

    /// Initial host connection timeout in milliseconds.
    pub fn get_timeout(&self) -> u32 {
        self._as.timeout
    }
    pub fn set_timeout(&mut self, timeout_millis: u32) {
        self._as.timeout = timeout_millis;
    }

    /// Connection idle timeout in milliseconds. Connections idle longer than this are closed
    /// and discarded from the pool.
    pub fn get_idle_timeout(&self) -> u32 {
        self._as.idle_timeout
    }
    pub fn set_idle_timeout(&mut self, timeout_millis: u32) {
        self._as.idle_timeout = timeout_millis;
    }

    /// Minimum number of connections preallocated per server node.
    pub fn get_min_conns_per_node(&self) -> u32 {
        self._as.min_conns_per_node as u32
    }
    pub fn set_min_conns_per_node(&mut self, n: u32) {
        self._as.min_conns_per_node = n as usize;
    }

    /// Maximum number of synchronous connections allowed per server node.
    pub fn get_max_conns_per_node(&self) -> u32 {
        self._as.max_conns_per_node as u32
    }
    pub fn set_max_conns_per_node(&mut self, n: u32) {
        self._as.max_conns_per_node = n as usize;
    }

    /// Number of connection pools per server node. Higher values reduce contention on
    /// many-core machines at the cost of more open sockets.
    pub fn get_conn_pools_per_node(&self) -> u32 {
        u32::from(self._as.conn_pools_per_node)
    }
    pub fn set_conn_pools_per_node(&mut self, n: u32) {
        self._as.conn_pools_per_node = n.min(255) as u8;
    }

    /// Throw an exception if the initial host connection fails. Default `true`.
    pub fn get_fail_if_not_connected(&self) -> bool {
        self._as.fail_if_not_connected
    }
    pub fn set_fail_if_not_connected(&mut self, fail: bool) {
        self._as.fail_if_not_connected = fail;
    }

    /// Interval (ms) between cluster-tend checks. Minimum is 10 ms; default is 1000 ms
    /// (inherited from `aerospike-client-rust`, aligned with the Java and Go clients).
    ///
    /// **Tuning guidance**: every PHP process runs its own tend loop, so the steady-state
    /// info-protocol RPS on each cluster node scales as `processes × nodes × (1 / interval)`.
    /// For prefork deployments (php-fpm, mod_php) with many concurrent workers, raise the
    /// interval to keep that fan-out manageable:
    ///
    /// | Deployment                                          | Recommended `tend_interval` |
    /// | ---                                                 | ---                          |
    /// | CLI tools, daemons, RoadRunner / FrankenPHP / Swoole | 1000 ms (default)            |
    /// | php-fpm with 10–50 workers per pod                  | 2000–5000 ms                 |
    /// | php-fpm with 100+ workers per pod                   | 5000–10000 ms                |
    ///
    /// Tradeoff: longer intervals slow detection of topology changes (node add/remove,
    /// rebalance). Failover on data-path errors is handled separately by retry policies
    /// and is unaffected.
    pub fn get_tend_interval(&self) -> u32 {
        self._as.tend_interval
    }
    pub fn set_tend_interval(&mut self, millis: u32) {
        self._as.tend_interval = millis;
    }

    /// Use `services-alternate` in cluster tending instead of `services`. Required when the
    /// client and server are on different sides of NAT/firewall. Mutually exclusive with `ip_map`.
    pub fn get_use_services_alternate(&self) -> bool {
        self._as.use_services_alternate
    }
    pub fn set_use_services_alternate(&mut self, alt: bool) {
        self._as.use_services_alternate = alt;
    }

    /// IP translation map: server-reported IP → real IP the client should dial. Empty map
    /// disables translation. Mutually exclusive with `use_services_alternate`.
    pub fn get_ip_map(&self) -> HashMap<String, String> {
        self._as.ip_map.clone().unwrap_or_default()
    }
    pub fn set_ip_map(&mut self, map: HashMap<String, String>) {
        self._as.ip_map = if map.is_empty() { None } else { Some(map) };
    }

    /// Optional application identifier. Used by the server to correlate client operations with
    /// server-side metrics. Defaults to the auth user when unset.
    pub fn get_application_id(&self) -> Option<String> {
        self._as.application_id.clone()
    }
    pub fn set_application_id(&mut self, id: Option<String>) {
        self._as.application_id = id;
    }

    /// Returns a deterministic fingerprint for this policy used to key the per-process
    /// client cache. Two policies with the same fingerprint produce equivalent clients and
    /// may share the cached instance. The password is hashed (never printed in clear) so
    /// password rotation invalidates the cached client without leaking the secret.
    ///
    /// All fields that influence cluster connectivity or behavior are mixed in — a change
    /// to any of them (e.g. `ip_map`, `tend_interval`, TLS config presence) produces a
    /// different cache key so the second `Client::connect` call gets a fresh client
    /// instead of silently reusing a stale one.
    pub fn fingerprint(&self) -> String {
        use std::collections::hash_map::DefaultHasher;
        let mut h = DefaultHasher::new();

        let auth_mode = self.get_auth_mode();
        auth_mode.hash(&mut h);

        if let Some(user) = self.get_user() {
            user.hash(&mut h);
        }
        if let aero::AuthMode::Internal(_, p) | aero::AuthMode::External(_, p) = &self._as.auth_mode
        {
            p.as_str().hash(&mut h);
        }

        self._as.cluster_name.hash(&mut h);
        self._as.timeout.hash(&mut h);
        self._as.idle_timeout.hash(&mut h);
        self._as.min_conns_per_node.hash(&mut h);
        self._as.max_conns_per_node.hash(&mut h);
        self._as.conn_pools_per_node.hash(&mut h);
        self._as.use_services_alternate.hash(&mut h);
        self._as.fail_if_not_connected.hash(&mut h);
        self._as.tend_interval.hash(&mut h);
        self._as.buffer_reclaim_threshold.hash(&mut h);
        self._as.application_id.hash(&mut h);

        // HashMap iteration is unordered: hash a sorted copy so the fingerprint is stable.
        if let Some(ref m) = self._as.ip_map {
            let mut entries: Vec<(&String, &String)> = m.iter().collect();
            entries.sort();
            entries.hash(&mut h);
        }

        // rack_ids is a HashSet — sort for deterministic hashing.
        if let Some(ref rs) = self._as.rack_ids {
            let mut entries: Vec<usize> = rs.iter().copied().collect();
            entries.sort_unstable();
            entries.hash(&mut h);
        }

        // TLS config does not implement Hash, so we keep a separately-computed digest of
        // the CA / client cert / key bytes (populated by `set_tls`). This guarantees a
        // distinct fingerprint per identity, breaking the previous bug where two policies
        // with different mTLS material but the same `hosts` would share the cached client.
        self._as.tls_config.is_some().hash(&mut h);
        self.tls_fingerprint.hash(&mut h);

        format!("{:016x}", h.finish())
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  Client
//
////////////////////////////////////////////////////////////////////////////////////////////

/// Build an `aero::AdminPolicy` from a `total_timeout` (milliseconds). Used to forward
/// the caller-supplied timeout into operations that internally take `AdminPolicy`
/// (truncate, index create/drop, UDF register/remove/list).
fn admin_policy_with_timeout(timeout_ms: u32) -> aero::AdminPolicy {
    let mut ap = aero::AdminPolicy::default();
    if timeout_ms > 0 {
        ap.timeout = timeout_ms;
    }
    ap
}

/// Helper: convert PHP bin-name list to aero::Bins selector.
/// `None` or empty list → `Bins::All` (all bins), non-empty list → `Bins::Some(names)`.
fn php_bins_to_aero(bins: Option<Vec<String>>) -> aero::Bins {
    match bins {
        None => aero::Bins::All,
        Some(ref v) if v.is_empty() => aero::Bins::All,
        Some(names) => aero::Bins::Some(names),
    }
}

#[php_class]
#[php(name = "Aerospike\\Client")]
pub struct Client {
    client: Arc<aero::Client>,
    hosts: String,
    /// Retained for diagnostics; cache eviction is keyed off the cache map itself.
    #[allow(dead_code)]
    policy_fingerprint: String,
}

impl Drop for Client {
    fn drop(&mut self) {
        trace!("Dropping client: {}, ptr: {:p}", self.hosts, &self);
    }
}

/// Client encapsulates an Aerospike cluster.
/// All database operations are available against this object.
#[php_impl]
impl Client {
    /// Connect to the Aerospike database cluster.
    ///
    /// v2 BREAKING: takes a hosts string ("host:port,...") instead of a Unix socket path.
    ///
    /// # Arguments
    ///
    /// * `hosts` - Comma-separated list of host:port pairs, e.g. "127.0.0.1:3000"
    /// * `policy` - Optional client policy controlling auth, pool sizes, timeouts, etc.
    pub fn connect(hosts: &str, policy: Option<&ClientPolicy>) -> PhpResult<Zval> {
        let fp = policy.map(|p| p.fingerprint()).unwrap_or_default();
        let cache_key = format!("{hosts}|{fp}");

        // Single critical section: lookup + insert under the same lock guards against the
        // ZTS race where two threads simultaneously miss the cache and each build a fresh
        // client (with its own connection pool). The first writer wins; subsequent waiters
        // observe the cached entry and skip the expensive `aero::Client::new`.
        {
            let mut clients = CLIENTS
                .lock()
                .map_err(|_| PhpException::default("client cache mutex poisoned".into()))?;

            if let Some(entry) = clients.get(&cache_key) {
                trace!("Found Aerospike Client object for {hosts}");
                return zval_from_entry(entry);
            }

            trace!("Creating a new Aerospike Client object for {hosts}");
            let aero_policy = policy.map(|p| p._as.clone()).unwrap_or_default();

            // External (LDAP) auth sends the password in clear at login — refuse to connect
            // if TLS is not configured. We enforce this here (rather than in
            // `set_auth_external`) so the user can set TLS and auth in either order.
            if matches!(aero_policy.auth_mode, aero::AuthMode::External(_, _))
                && aero_policy.tls_config.is_none()
            {
                return throw_msg(
                    "AuthMode::External requires TLS — call ClientPolicy::setTls() before connect()",
                    Zval::new(),
                );
            }

            let c = {
                let _guard = TOKIO_RT.enter();
                match aero::Client::new(&aero_policy, &hosts) {
                    Ok(c) => Arc::new(c),
                    // Preserve the structured AerospikeException (code + in_doubt) instead
                    // of flattening to a string with `e.to_string()`.
                    Err(e) => return throw_aero_error(&e, Zval::new()),
                }
            };

            let entry = ClientEntry {
                client: c,
                hosts: hosts.to_string(),
                policy_fingerprint: fp,
            };
            let inserted = clients.entry(cache_key.clone()).or_insert(entry);
            zval_from_entry(inserted)
        }
    }

    /// Returns the hosts string this client was connected to.
    pub fn get_hosts(&self) -> String {
        self.hosts.clone()
    }

    /// v1 compatibility shim: forwards `$client->hosts` to `getHosts()`.
    pub fn __get(&self, name: &str) -> PhpResult<Zval> {
        let mut zv = Zval::new();
        match name {
            "hosts" => zv.set_string(&self.get_hosts(), false)?,
            _ => zv.set_null(),
        }
        Ok(zv)
    }

    /// Write record bin(s). The policy specifies the transaction timeout, record expiration and
    /// how the transaction is handled when the record already exists.
    pub fn put(&self, policy: &WritePolicy, key: &Key, bins: Vec<&Bin>) -> PhpResult<()> {
        let aero_bins: Vec<aero::Bin> = bins.iter().map(|b| b._as.clone()).collect();
        let _guard = TOKIO_RT.enter();
        match self.client.put(&policy._as, &key._as, &aero_bins) {
            Ok(()) => Ok(()),
            Err(e) => throw_aero_error(&e, ()),
        }
    }

    /// Read record for the specified key. Depending on the bins value provided, all record bins,
    /// only selected record bins or only the record headers will be returned.
    pub fn get(
        &self,
        policy: &ReadPolicy,
        key: &Key,
        bins: Option<Vec<String>>,
    ) -> PhpResult<Option<Record>> {
        let aero_bins = php_bins_to_aero(bins);
        let _guard = TOKIO_RT.enter();
        match self.client.get(&policy._as, &key._as, aero_bins) {
            Ok(record) => Ok(Some(Record { _as: record })),
            Err(aero::Error::ServerError(aero::ResultCode::KeyNotFoundError, ..)) => Ok(None),
            Err(e) => throw_aero_error(&e, None),
        }
    }

    /// Read record header (generation, expiration) only. No bins are returned.
    pub fn get_header(&self, policy: &ReadPolicy, key: &Key) -> PhpResult<Option<Record>> {
        let _guard = TOKIO_RT.enter();
        match self.client.get(&policy._as, &key._as, aero::Bins::None) {
            Ok(record) => Ok(Some(Record { _as: record })),
            Err(aero::Error::ServerError(aero::ResultCode::KeyNotFoundError, ..)) => Ok(None),
            Err(e) => throw_aero_error(&e, None),
        }
    }

    /// Add integer bin values to existing record bin values.
    pub fn add(&self, policy: &WritePolicy, key: &Key, bins: Vec<&Bin>) -> PhpResult<()> {
        let aero_bins: Vec<aero::Bin> = bins.iter().map(|b| b._as.clone()).collect();
        let _guard = TOKIO_RT.enter();
        match self.client.add(&policy._as, &key._as, &aero_bins) {
            Ok(()) => Ok(()),
            Err(e) => throw_aero_error(&e, ()),
        }
    }

    /// Append bin string values to existing record bin values.
    pub fn append(&self, policy: &WritePolicy, key: &Key, bins: Vec<&Bin>) -> PhpResult<()> {
        let aero_bins: Vec<aero::Bin> = bins.iter().map(|b| b._as.clone()).collect();
        let _guard = TOKIO_RT.enter();
        match self.client.append(&policy._as, &key._as, &aero_bins) {
            Ok(()) => Ok(()),
            Err(e) => throw_aero_error(&e, ()),
        }
    }

    /// Prepend bin string values to existing record bin values.
    pub fn prepend(&self, policy: &WritePolicy, key: &Key, bins: Vec<&Bin>) -> PhpResult<()> {
        let aero_bins: Vec<aero::Bin> = bins.iter().map(|b| b._as.clone()).collect();
        let _guard = TOKIO_RT.enter();
        match self.client.prepend(&policy._as, &key._as, &aero_bins) {
            Ok(()) => Ok(()),
            Err(e) => throw_aero_error(&e, ()),
        }
    }

    /// Delete record for specified key. Returns `true` if the record existed before deletion.
    pub fn delete(&self, policy: &WritePolicy, key: &Key) -> PhpResult<bool> {
        let _guard = TOKIO_RT.enter();
        match self.client.delete(&policy._as, &key._as) {
            Ok(existed) => Ok(existed),
            Err(e) => throw_aero_error(&e, false),
        }
    }

    /// Reset record's time to expiration using the policy's expiration.
    pub fn touch(&self, policy: &WritePolicy, key: &Key) -> PhpResult<()> {
        let _guard = TOKIO_RT.enter();
        match self.client.touch(&policy._as, &key._as) {
            Ok(()) => Ok(()),
            Err(e) => throw_aero_error(&e, ()),
        }
    }

    /// Determine if a record key exists.
    pub fn exists(&self, policy: &ReadPolicy, key: &Key) -> PhpResult<bool> {
        let _guard = TOKIO_RT.enter();
        match self.client.exists(&policy._as, &key._as) {
            Ok(exists) => Ok(exists),
            Err(e) => throw_aero_error(&e, false),
        }
    }

    /// Apply a list of `Operation` instances to a single record atomically on the server.
    ///
    /// Use this for CDT list/map/bitwise/HLL operations and for combined read/write/touch
    /// flows on one key. For multi-key batches use `batch()` with `BatchWrite`/`BatchRead`.
    ///
    /// Returns the record assembled from operation results (some ops return nothing, some
    /// return a value into the named bin), or `None` when the operations produced no
    /// readable output and the underlying record was not present.
    pub fn operate(
        &self,
        policy: &WritePolicy,
        key: &Key,
        ops: Vec<&Operation>,
    ) -> PhpResult<Option<Record>> {
        let aero_ops: Vec<aero::operations::Operation> =
            ops.iter().map(|o| o._as.clone()).collect();
        let _guard = TOKIO_RT.enter();
        match self.client.operate(&policy._as, &key._as, &aero_ops) {
            Ok(record) => Ok(Some(Record { _as: record })),
            Err(aero::Error::ServerError(aero::ResultCode::KeyNotFoundError, ..)) => Ok(None),
            Err(e) => throw_aero_error(&e, None),
        }
    }

    /// Execute read/write operations on multiple records in one batch call.
    /// Each element in `cmds` must be a BatchRead, BatchWrite, BatchDelete, or BatchUdf object.
    /// Requires server version 6.0+.
    pub fn batch(&self, policy: &BatchPolicy, cmds: Vec<&Zval>) -> PhpResult<Vec<BatchRecord>> {
        let mut batch_ops = Vec::<aero::BatchOperation>::with_capacity(cmds.len());
        for v in &cmds {
            if let Some(br) = v.extract::<&BatchRead>() {
                batch_ops.push(br._as.clone());
            } else if let Some(bw) = v.extract::<&BatchWrite>() {
                batch_ops.push(bw._as.clone());
            } else if let Some(bd) = v.extract::<&BatchDelete>() {
                batch_ops.push(bd._as.clone());
            } else if let Some(bu) = v.extract::<&BatchUdf>() {
                batch_ops.push(bu._as.clone());
            } else {
                return throw_msg("Invalid Batch command", vec![]);
            }
        }
        let _guard = TOKIO_RT.enter();
        match self.client.batch(&policy._as, &batch_ops) {
            Ok(results) => Ok(results
                .into_iter()
                .map(|br| BatchRecord { _as: br })
                .collect()),
            Err(e) => throw_aero_error(&e, vec![]),
        }
    }

    /// Remove all records in the specified namespace/set efficiently.
    pub fn truncate(
        &self,
        policy: &InfoPolicy,
        namespace: &str,
        set_name: &str,
        before_nanos: Option<i64>,
    ) -> PhpResult<()> {
        let admin = admin_policy_with_timeout(policy.timeout);
        let _guard = TOKIO_RT.enter();
        match self
            .client
            .truncate(&admin, namespace, set_name, before_nanos.unwrap_or(0))
        {
            Ok(()) => Ok(()),
            Err(e) => throw_aero_error(&e, ()),
        }
    }

    /// Read all records in the specified namespace and set. In v2, scan is implemented
    /// as a query with no secondary-index filters.
    ///
    /// The `partition_filter` doubles as a cursor — when the returned `Recordset` is
    /// exhausted, its post-scan state is written back into the original `PartitionFilter`
    /// so a subsequent `scan()` with the same instance resumes from the next digest. Use
    /// `ScanPolicy::setMaxRecords(...)` to bound each page.
    pub fn scan(
        &self,
        policy: &ScanPolicy,
        partition_filter: PartitionFilter,
        namespace: &str,
        set_name: &str,
        bins: Option<Vec<String>>,
    ) -> PhpResult<Recordset> {
        let aero_bins = php_bins_to_aero(bins);
        let stmt = aero::Statement::new(namespace, set_name, aero_bins);
        let pf_arc = partition_filter._as.clone();
        let pf = pf_arc
            .lock()
            .map_err(|_| PhpException::default("PartitionFilter mutex poisoned".into()))?
            .clone();
        let _guard = TOKIO_RT.enter();
        match self.client.query(&policy._as, pf, stmt) {
            Ok(arc_rs) => Ok(Recordset {
                _as: Some(arc_rs),
                partition_filter: Some(pf_arc),
                pf_synced: false,
            }),
            Err(e) => throw_aero_error(&e, Recordset::default()),
        }
    }

    /// Execute a query on all server nodes and return a record iterator. See `scan()` for
    /// pagination semantics around `PartitionFilter`.
    pub fn query(
        &self,
        policy: &QueryPolicy,
        partition_filter: PartitionFilter,
        statement: &mut Statement,
    ) -> PhpResult<Recordset> {
        let stmt = statement._as.clone();
        let pf_arc = partition_filter._as.clone();
        let pf = pf_arc
            .lock()
            .map_err(|_| PhpException::default("PartitionFilter mutex poisoned".into()))?
            .clone();
        let _guard = TOKIO_RT.enter();
        match self.client.query(&policy._as, pf, stmt) {
            Ok(arc_rs) => Ok(Recordset {
                _as: Some(arc_rs),
                partition_filter: Some(pf_arc),
                pf_synced: false,
            }),
            Err(e) => throw_aero_error(&e, Recordset::default()),
        }
    }

    /// Create a secondary index on a bin.
    ///
    /// v2 BREAKING: `ctx` is currently ignored; the underlying aerospike crate does not yet
    /// expose ctx-aware index creation through `create_index_on_bin`.
    pub fn create_index(
        &self,
        policy: &WritePolicy,
        namespace: &str,
        set_name: &str,
        bin_name: &str,
        index_name: &str,
        index_type: &IndexType,
        cit: Option<&IndexCollectionType>,
        _ctx: Option<Vec<&CDTContext>>,
    ) -> PhpResult<()> {
        let admin = admin_policy_with_timeout(policy._as.base_policy.total_timeout);
        let cit_val = cit
            .map(|c| c._as.clone())
            .unwrap_or(aero::CollectionIndexType::Default);
        let task = match TOKIO_RT.block_on(self.client.create_index_on_bin(
            &admin,
            namespace,
            set_name,
            bin_name,
            index_name,
            index_type._as.clone(),
            cit_val,
            None,
        )) {
            Ok(t) => t,
            Err(e) => return throw_aero_error(&e, ()),
        };
        if let Err(e) = TOKIO_RT.block_on(AeroTask::wait_till_complete(&task, None)) {
            return throw_aero_error(&e, ());
        }
        Ok(())
    }

    /// Delete a secondary index.
    pub fn drop_index(
        &self,
        policy: &WritePolicy,
        namespace: &str,
        set_name: &str,
        index_name: &str,
    ) -> PhpResult<()> {
        let admin = admin_policy_with_timeout(policy._as.base_policy.total_timeout);
        let task = {
            let _guard = TOKIO_RT.enter();
            match self
                .client
                .drop_index(&admin, namespace, set_name, index_name)
            {
                Ok(t) => t,
                Err(e) => return throw_aero_error(&e, ()),
            }
        };
        if let Err(e) = TOKIO_RT.block_on(AeroTask::wait_till_complete(&task, None)) {
            return throw_aero_error(&e, ());
        }
        Ok(())
    }

    /// RegisterUDF registers a package containing user defined functions with server.
    pub fn register_udf(
        &self,
        policy: &WritePolicy,
        udf_body: &str,
        package_name: &str,
        language: Option<UdfLanguage>,
    ) -> PhpResult<()> {
        let admin = admin_policy_with_timeout(policy._as.base_policy.total_timeout);
        let lang = language.map(|l| l._as).unwrap_or(aero::UDFLang::Lua);
        let task = {
            let _guard = TOKIO_RT.enter();
            match self
                .client
                .register_udf(&admin, udf_body.as_bytes(), package_name, lang)
            {
                Ok(t) => t,
                Err(e) => return throw_aero_error(&e, ()),
            }
        };
        if let Err(e) = TOKIO_RT.block_on(AeroTask::wait_till_complete(&task, None)) {
            return throw_aero_error(&e, ());
        }
        Ok(())
    }

    pub fn drop_udf(&self, policy: &WritePolicy, package_name: &str) -> PhpResult<()> {
        let admin = admin_policy_with_timeout(policy._as.base_policy.total_timeout);
        let task = {
            let _guard = TOKIO_RT.enter();
            match self.client.remove_udf(&admin, package_name) {
                Ok(t) => t,
                Err(e) => return throw_aero_error(&e, ()),
            }
        };
        if let Err(e) = TOKIO_RT.block_on(AeroTask::wait_till_complete(&task, None)) {
            return throw_aero_error(&e, ());
        }
        Ok(())
    }

    pub fn list_udf(&self, policy: &ReadPolicy) -> PhpResult<Vec<UdfMeta>> {
        let admin = admin_policy_with_timeout(policy._as.base_policy.total_timeout);
        let nodes = self.client.nodes();
        let node = match nodes.first() {
            Some(n) => n.clone(),
            None => return Ok(vec![]),
        };
        let result = match TOKIO_RT.block_on(node.info(&admin, &["udf-list"])) {
            Ok(r) => r,
            Err(e) => return throw_aero_error(&e, vec![]),
        };
        let raw = result.get("udf-list").map(String::as_str).unwrap_or("");
        let mut udfs = Vec::new();
        for entry in raw.split(';') {
            let entry = entry.trim();
            if entry.is_empty() {
                continue;
            }
            let mut filename = String::new();
            let mut hash = String::new();
            let mut language = String::new();
            for field in entry.split(',') {
                if let Some((k, v)) = field.split_once('=') {
                    match k {
                        "filename" => filename = v.to_string(),
                        "hash" => hash = v.to_string(),
                        "type" => language = v.to_lowercase(),
                        _ => {}
                    }
                }
            }
            if !filename.is_empty() {
                let package_name = filename
                    .strip_suffix(".lua")
                    .unwrap_or(&filename)
                    .to_string();
                udfs.push(UdfMeta {
                    package_name,
                    hash,
                    language,
                });
            }
        }
        Ok(udfs)
    }

    /// Returns the server build version string for each node in the cluster.
    /// The returned HashMap maps node name (host:port) to version string (e.g. "7.0.0.1").
    pub fn server_version(&self) -> PhpResult<HashMap<String, String>> {
        let nodes = self.client.nodes();
        let mut versions = std::collections::HashMap::new();
        for node in &nodes {
            match TOKIO_RT.block_on(node.info(&aero::AdminPolicy::default(), &["build"])) {
                Ok(result) => {
                    let ver = result.get("build").cloned().unwrap_or_default();
                    versions.insert(node.name().to_string(), ver);
                }
                Err(_) => {
                    versions.insert(node.name().to_string(), String::new());
                }
            }
        }
        Ok(versions)
    }

    pub fn udf_execute(
        &self,
        policy: &WritePolicy,
        key: &Key,
        package_name: String,
        function_name: String,
        args: Vec<PHPValue>,
    ) -> PhpResult<PHPValue> {
        let aero_args: Vec<aero::Value> = args.into_iter().map(aero::Value::from).collect();
        let args_ref: Option<&[aero::Value]> = if aero_args.is_empty() {
            None
        } else {
            Some(&aero_args)
        };
        let _guard = TOKIO_RT.enter();
        match self.client.execute_udf(
            &policy._as,
            &key._as,
            &package_name,
            &function_name,
            args_ref,
        ) {
            Ok(Some(v)) => Ok(PHPValue::from(v)),
            Ok(None) => Ok(PHPValue::Nil),
            Err(e) => throw_aero_error(&e, PHPValue::Nil),
        }
    }

    //-------------------------------------------------------
    // User administration
    //-------------------------------------------------------

    pub fn create_user(
        &self,
        policy: &AdminPolicy,
        user: String,
        password: String,
        roles: Vec<String>,
    ) -> PhpResult<()> {
        let roles_ref: Vec<&str> = roles.iter().map(String::as_str).collect();
        if let Err(e) =
            TOKIO_RT.block_on(
                self.client
                    .create_user(&policy._as, &user, &password, &roles_ref),
            )
        {
            return throw_aero_error(&e, ());
        }
        Ok(())
    }

    pub fn drop_user(&self, policy: &AdminPolicy, user: String) -> PhpResult<()> {
        if let Err(e) = TOKIO_RT.block_on(self.client.drop_user(&policy._as, &user)) {
            return throw_aero_error(&e, ());
        }
        Ok(())
    }

    pub fn change_password(
        &self,
        policy: &AdminPolicy,
        user: String,
        password: String,
    ) -> PhpResult<()> {
        if let Err(e) =
            TOKIO_RT.block_on(self.client.change_password(&policy._as, &user, &password))
        {
            return throw_aero_error(&e, ());
        }
        Ok(())
    }

    pub fn grant_roles(
        &self,
        policy: &AdminPolicy,
        user: String,
        roles: Vec<String>,
    ) -> PhpResult<()> {
        let roles_ref: Vec<&str> = roles.iter().map(String::as_str).collect();
        if let Err(e) = TOKIO_RT.block_on(self.client.grant_roles(&policy._as, &user, &roles_ref)) {
            return throw_aero_error(&e, ());
        }
        Ok(())
    }

    pub fn revoke_roles(
        &self,
        policy: &AdminPolicy,
        user: String,
        roles: Vec<String>,
    ) -> PhpResult<()> {
        let roles_ref: Vec<&str> = roles.iter().map(String::as_str).collect();
        if let Err(e) = TOKIO_RT.block_on(self.client.revoke_roles(&policy._as, &user, &roles_ref))
        {
            return throw_aero_error(&e, ());
        }
        Ok(())
    }

    pub fn query_users(
        &self,
        policy: &AdminPolicy,
        user: Option<String>,
    ) -> PhpResult<Vec<UserRole>> {
        match TOKIO_RT.block_on(self.client.query_users(&policy._as, user.as_deref())) {
            Ok(users) => Ok(users.into_iter().map(UserRole::from).collect()),
            Err(e) => throw_aero_error(&e, vec![]),
        }
    }

    pub fn query_roles(
        &self,
        policy: &AdminPolicy,
        role_name: Option<String>,
    ) -> PhpResult<Vec<Role>> {
        match TOKIO_RT.block_on(self.client.query_roles(&policy._as, role_name.as_deref())) {
            Ok(roles) => Ok(roles.into_iter().map(Role::from).collect()),
            Err(e) => throw_aero_error(&e, vec![]),
        }
    }

    pub fn create_role(
        &self,
        policy: &AdminPolicy,
        role_name: String,
        privileges: Vec<Privilege>,
        allowlist: Vec<String>,
        read_quota: u32,
        write_quota: u32,
    ) -> PhpResult<()> {
        let privs: Vec<aero::Privilege> = privileges.into_iter().map(|p| p._as).collect();
        let allowlist_ref: Vec<&str> = allowlist.iter().map(String::as_str).collect();
        if let Err(e) = TOKIO_RT.block_on(self.client.create_role(
            &policy._as,
            &role_name,
            &privs,
            &allowlist_ref,
            read_quota,
            write_quota,
        )) {
            return throw_aero_error(&e, ());
        }
        Ok(())
    }

    pub fn drop_role(&self, policy: &AdminPolicy, role_name: String) -> PhpResult<()> {
        if let Err(e) = TOKIO_RT.block_on(self.client.drop_role(&policy._as, &role_name)) {
            return throw_aero_error(&e, ());
        }
        Ok(())
    }

    pub fn grant_privileges(
        &self,
        policy: &AdminPolicy,
        role_name: String,
        privileges: Vec<Privilege>,
    ) -> PhpResult<()> {
        let privs: Vec<aero::Privilege> = privileges.into_iter().map(|p| p._as).collect();
        if let Err(e) = TOKIO_RT.block_on(self.client.grant_privileges(
            &policy._as,
            &role_name,
            &privs,
        )) {
            return throw_aero_error(&e, ());
        }
        Ok(())
    }

    pub fn revoke_privileges(
        &self,
        policy: &AdminPolicy,
        role_name: String,
        privileges: Vec<Privilege>,
    ) -> PhpResult<()> {
        let privs: Vec<aero::Privilege> = privileges.into_iter().map(|p| p._as).collect();
        if let Err(e) = TOKIO_RT.block_on(self.client.revoke_privileges(
            &policy._as,
            &role_name,
            &privs,
        )) {
            return throw_aero_error(&e, ());
        }
        Ok(())
    }

    pub fn set_allowlist(
        &self,
        policy: &AdminPolicy,
        role_name: String,
        allowlist: Vec<String>,
    ) -> PhpResult<()> {
        let allowlist_ref: Vec<&str> = allowlist.iter().map(String::as_str).collect();
        if let Err(e) = TOKIO_RT.block_on(self.client.set_allowlist(
            &policy._as,
            &role_name,
            &allowlist_ref,
        )) {
            return throw_aero_error(&e, ());
        }
        Ok(())
    }

    pub fn set_quotas(
        &self,
        policy: &AdminPolicy,
        role_name: String,
        read_quota: u32,
        write_quota: u32,
    ) -> PhpResult<()> {
        if let Err(e) = TOKIO_RT.block_on(self.client.set_quotas(
            &policy._as,
            &role_name,
            read_quota,
            write_quota,
        )) {
            return throw_aero_error(&e, ());
        }
        Ok(())
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  AerospikeException
//
////////////////////////////////////////////////////////////////////////////////////////////

/// Represents an exception specific to the Aerospike database operations.
#[php_class]
#[php(name = "Aerospike\\AerospikeException")]
#[php(extends(ce = ext_php_rs::zend::ce::exception, stub = "\\Exception"))]
#[derive(Debug, Clone, Default)]
pub struct AerospikeException {
    #[php(prop, flags = ext_php_rs::flags::PropertyFlags::Public)]
    message: String,
    #[php(prop, flags = ext_php_rs::flags::PropertyFlags::Public)]
    code: i32,
    #[php(prop, flags = ext_php_rs::flags::PropertyFlags::Public)]
    in_doubt: bool,
}

/// Constructs a new `AerospikeException` with the specified error message.
///
/// # Arguments
///
/// * `message` - The error message describing the exception.
///
/// # Returns
///
/// A new `AerospikeException` instance initialized with the provided error message.
impl AerospikeException {
    pub fn new(message: &str) -> Self {
        AerospikeException {
            message: message.to_string(),
            code: ResultCode::COMMON_ERROR,
            in_doubt: false,
        }
    }
}

/// Map an `aerospike::ResultCode` enum variant back to the wire-protocol i32
/// used by the PHP-side `Aerospike\\ResultCode` constants.
fn aero_result_code_to_i32(rc: aero::ResultCode) -> i32 {
    use aero::ResultCode as RC;
    match rc {
        RC::Ok => 0,
        RC::ServerError => 1,
        RC::KeyNotFoundError => 2,
        RC::GenerationError => 3,
        RC::ParameterError => 4,
        RC::KeyExistsError => 5,
        RC::BinExistsError => 6,
        RC::ClusterKeyMismatch => 7,
        RC::ServerMemError => 8,
        RC::Timeout => 9,
        RC::AlwaysForbidden => 10,
        RC::PartitionUnavailable => 11,
        RC::BinTypeError => 12,
        RC::RecordTooBig => 13,
        RC::KeyBusy => 14,
        RC::ScanAbort => 15,
        RC::UnsupportedFeature => 16,
        RC::BinNotFound => 17,
        RC::DeviceOverload => 18,
        RC::KeyMismatch => 19,
        RC::InvalidNamespace => 20,
        RC::BinNameTooLong => 21,
        RC::FailForbidden => 22,
        RC::ElementNotFound => 23,
        RC::ElementExists => 24,
        RC::EnterpriseOnly => 25,
        RC::OpNotApplicable => 26,
        RC::FilteredOut => 27,
        RC::LostConflict => 28,
        RC::XDRKeyBusy => 32,
        RC::QueryEnd => 50,
        RC::SecurityNotSupported => 51,
        RC::SecurityNotEnabled => 52,
        RC::SecuritySchemeNotSupported => 53,
        RC::InvalidCommand => 54,
        RC::InvalidField => 55,
        RC::IllegalState => 56,
        RC::InvalidUser => 60,
        RC::UserAlreadyExists => 61,
        RC::InvalidPassword => 62,
        RC::ExpiredPassword => 63,
        RC::ForbiddenPassword => 64,
        RC::InvalidCredential => 65,
        RC::ExpiredSession => 66,
        RC::InvalidRole => 70,
        RC::RoleAlreadyExists => 71,
        RC::InvalidPrivilege => 72,
        RC::InvalidAllowlist => 73,
        RC::QuotasNotEnabled => 74,
        RC::InvalidQuota => 75,
        RC::NotAuthenticated => 80,
        RC::RoleViolation => 81,
        RC::NotAllowlisted => 82,
        RC::QuotaExceeded => 83,
        RC::UdfBadResponse => 100,
        RC::BatchDisabled => 150,
        RC::BatchMaxRequestsExceeded => 151,
        RC::BatchQueuesFull => 152,
        RC::InvalidGeojson => 160,
        RC::IndexFound => 200,
        RC::IndexNotFound => 201,
        RC::IndexOom => 202,
        RC::IndexNotReadable => 203,
        RC::IndexGeneric => 204,
        RC::IndexNameMaxLen => 205,
        RC::IndexMaxCount => 206,
        RC::QueryAborted => 210,
        RC::QueryQueueFull => 211,
        RC::QueryTimeout => 212,
        RC::QueryGeneric => 213,
        RC::QueryNetioErr => 214,
        RC::QueryDuplicate => 215,
        RC::Unknown(code) => code as i32,
    }
}

impl From<&aero::Error> for AerospikeException {
    fn from(error: &aero::Error) -> AerospikeException {
        let (code, in_doubt) = match error {
            aero::Error::ServerError(rc, in_doubt, _node) => {
                (aero_result_code_to_i32(*rc), *in_doubt)
            }
            aero::Error::BatchError(_idx, rc, in_doubt, _node)
            | aero::Error::BatchLastError(_idx, rc, in_doubt, _node) => {
                (aero_result_code_to_i32(*rc), *in_doubt)
            }
            _ => (ResultCode::COMMON_ERROR, false),
        };
        AerospikeException {
            message: error.to_string(),
            code,
            in_doubt,
        }
    }
}

impl From<AerospikeException> for PhpException {
    fn from(error: AerospikeException) -> PhpException {
        PhpException::default(error.message)
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  Key
//
////////////////////////////////////////////////////////////////////////////////////////////

/// Key is the unique record identifier. Records can be identified using a specified namespace,
/// an optional set name, and a user defined key which must be unique within a set.
/// Records can also be identified by namespace/digest which is the combination used
/// on the server.
#[php_class]
#[php(name = "Aerospike\\Key")]
pub struct Key {
    _as: aero::Key,
}

#[php_impl]
impl Key {
    pub fn __construct(namespace: &str, set: &str, key: PHPValue) -> PhpResult<Self> {
        let aero_value: aero::Value = key.into();
        match aero::Key::new(namespace.to_string(), set.to_string(), aero_value) {
            Ok(k) => Ok(Key { _as: k }),
            Err(e) => Err(format!("Invalid key: {e}").into()),
        }
    }

    /// v1 compatibility shim: forwards `$key->namespace`, `->set`, `->setname`, `->userKey`,
    /// `->value`, `->digest`, `->digestBytes` to the corresponding getters.
    pub fn __get(&self, name: &str) -> PhpResult<Zval> {
        let mut zv = Zval::new();
        match name {
            "namespace" => zv.set_string(&self.get_namespace(), false)?,
            "set" | "setname" | "setName" => zv.set_string(&self.get_setname(), false)?,
            "userKey" | "user_key" | "value" => match self.get_value() {
                Some(v) => v.set_zval(&mut zv, false)?,
                None => zv.set_null(),
            },
            "digest" => zv.set_string(&self.get_digest(), false)?,
            "digestBytes" | "digest_bytes" => zv.set_binary(self.get_digest_bytes()),
            _ => zv.set_null(),
        }
        Ok(zv)
    }

    /// namespace. Equivalent to database name.
    pub fn get_namespace(&self) -> String {
        self._as.namespace.clone()
    }

    /// Optional set name. Equivalent to database table.
    pub fn get_setname(&self) -> String {
        self._as.set_name.clone()
    }

    /// getValue() returns key's value.
    pub fn get_value(&self) -> Option<PHPValue> {
        self._as.user_key.clone().map(Into::into)
    }

    /// get_digest_bytes returns key digest as byte array.
    pub fn get_digest_bytes(&self) -> Vec<u8> {
        self._as.digest.to_vec()
    }

    /// get_digest returns key digest as string.
    pub fn get_digest(&self) -> String {
        hex::encode(self._as.digest)
    }

    /// PartitionId returns the partition that the key belongs to.
    fn partition_id(&self) -> Option<usize> {
        Some(self._as.partition_id())
    }
}

impl FromZval<'_> for Key {
    const TYPE: DataType = DataType::Mixed;

    fn from_zval(zval: &Zval) -> Option<Self> {
        let f: &Key = zval.extract()?;

        Some(Key { _as: f._as.clone() })
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  GeoJSON
//
////////////////////////////////////////////////////////////////////////////////////////////

/// Implementation of the GeoJson Value for Aerospike.
#[php_class]
#[php(name = "Aerospike\\GeoJSON")]
pub struct GeoJSON {
    v: String,
}

impl FromZval<'_> for GeoJSON {
    const TYPE: DataType = DataType::Mixed;

    fn from_zval(zval: &Zval) -> Option<Self> {
        let f: &GeoJSON = zval.extract()?;

        Some(GeoJSON { v: f.v.clone() })
    }
}

#[php_impl]
impl GeoJSON {
    pub fn get_value(&self) -> String {
        self.v.clone()
    }
    pub fn set_value(&mut self, geo: String) {
        self.v = geo
    }

    /// Returns a string representation of the value.
    pub fn as_string(&self) -> String {
        PHPValue::GeoJSON(self.v.clone()).as_string()
    }
}

impl fmt::Display for GeoJSON {
    fn fmt(&self, f: &mut fmt::Formatter) -> std::result::Result<(), fmt::Error> {
        write!(f, "{}", self.as_string())
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  Json
//
////////////////////////////////////////////////////////////////////////////////////////////

/// Implementation of the Json (Map<String, Value>) data structure for Aerospike.
#[php_class]
#[php(name = "Aerospike\\Json")]
pub struct Json {
    v: HashMap<String, PHPValue>,
}

impl FromZval<'_> for Json {
    const TYPE: DataType = DataType::Mixed;

    fn from_zval(zval: &Zval) -> Option<Self> {
        let f: &Json = zval.extract()?;

        Some(Json { v: f.v.clone() })
    }
}

#[php_impl]
impl Json {
    /// getter method to get the json value
    pub fn get_value(&self) -> HashMap<String, PHPValue> {
        self.v.clone()
    }

    /// setter method to set the json value
    pub fn set_value(&mut self, v: HashMap<String, PHPValue>) {
        self.v = v
    }

    /// Returns a string representation of the value.
    pub fn as_string(&self) -> String {
        PHPValue::Json(self.v.clone()).as_string()
    }
}

impl fmt::Display for Json {
    fn fmt(&self, f: &mut fmt::Formatter) -> std::result::Result<(), fmt::Error> {
        write!(f, "{}", self.as_string())
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  Infinity
//
////////////////////////////////////////////////////////////////////////////////////////////

/// Represents a infinity value for Aerospike.
#[php_class]
#[php(name = "Aerospike\\Infinity")]
pub struct Infinity {}

impl FromZval<'_> for Infinity {
    const TYPE: DataType = DataType::Mixed;

    fn from_zval(zval: &Zval) -> Option<Self> {
        let _f: &Infinity = zval.extract()?;

        Some(Infinity {})
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  Wildcard
//
////////////////////////////////////////////////////////////////////////////////////////////

/// Represents a wildcard value for Aerospike.
#[php_class]
#[php(name = "Aerospike\\Wildcard")]
pub struct Wildcard {}

impl FromZval<'_> for Wildcard {
    const TYPE: DataType = DataType::Mixed;

    fn from_zval(zval: &Zval) -> Option<Self> {
        let _f: &Wildcard = zval.extract()?;

        Some(Wildcard {})
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  BLOB
//
////////////////////////////////////////////////////////////////////////////////////////////

/// Implementation of the BLOB data structure for Aerospike.
#[php_class]
#[php(name = "Aerospike\\BLOB")]
pub struct BLOB {
    v: Vec<u8>,
}

impl FromZval<'_> for BLOB {
    const TYPE: DataType = DataType::Mixed;

    fn from_zval(zval: &Zval) -> Option<Self> {
        let f: &BLOB = zval.extract()?;

        Some(BLOB { v: f.v.clone() })
    }
}

#[php_impl]
impl BLOB {
    pub fn get_binary(&self) -> Binary<u8> {
        self.v.clone().into_iter().collect::<Binary<_>>()
    }
    pub fn get_value(&self) -> Vec<u8> {
        self.v.clone()
    }
    pub fn set_value(&mut self, blob: Vec<u8>) {
        self.v = blob
    }

    /// Returns a string representation of the value.
    pub fn as_string(&self) -> String {
        PHPValue::Blob(self.v.clone()).as_string()
    }

    /// Returns a string representation of the value.
    pub fn equals(&self, other: &BLOB) -> bool {
        self.v == other.v
    }
}

impl fmt::Display for BLOB {
    fn fmt(&self, f: &mut fmt::Formatter) -> std::result::Result<(), fmt::Error> {
        write!(f, "{}", self.as_string())
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  HLL
//
////////////////////////////////////////////////////////////////////////////////////////////

/// Implementation of the HyperLogLog (HLL) data structure for Aerospike.
#[php_class]
#[php(name = "Aerospike\\HLL")]
pub struct HLL {
    v: Vec<u8>,
}

impl FromZval<'_> for HLL {
    const TYPE: DataType = DataType::Mixed;

    fn from_zval(zval: &Zval) -> Option<Self> {
        let f: &HLL = zval.extract()?;

        Some(HLL { v: f.v.clone() })
    }
}

#[php_impl]
impl HLL {
    pub fn get_value(&self) -> Vec<u8> {
        self.v.clone()
    }
    pub fn set_value(&mut self, hll: Vec<u8>) {
        self.v = hll
    }

    /// Returns a string representation of the value.
    pub fn as_string(&self) -> String {
        PHPValue::HLL(self.v.clone()).as_string()
    }
}

impl fmt::Display for HLL {
    fn fmt(&self, f: &mut fmt::Formatter) -> std::result::Result<(), fmt::Error> {
        write!(f, "{}", self.as_string())
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  PHPValue
//
////////////////////////////////////////////////////////////////////////////////////////////

/// Container for bin values stored in the Aerospike database.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum PHPValue {
    /// Empty value.
    Nil,
    /// Boolean value.
    Bool(bool),
    /// Integer value. All integers are represented as 64-bit numerics in Aerospike.
    Int(i64),
    /// Unsigned integer value. The largest integer value that can be stored in a record bin is
    /// `i64::max_value()`; however the list and map data types can store integer values (and keys)
    /// up to `u64::max_value()`.
    ///
    /// # Panics
    ///
    /// Attempting to store an `u64` value as a record bin value will cause a panic. Use casting to
    /// store and retrieve `u64` values.
    UInt(u64),
    /// Floating point value. All floating point values are stored in 64-bit IEEE-754 format in
    /// Aerospike. Aerospike server v3.6.0 and later support double data type.
    Float(ordered_float::OrderedFloat<f64>),
    /// String value.
    String(String),
    /// Byte array value.
    Blob(Vec<u8>),
    /// List data type is an ordered collection of values. Lists can contain values of any
    /// supported data type. List data order is maintained on writes and reads.
    List(Vec<PHPValue>),
    /// Map data type is a collection of key-value pairs. Each key can only appear once in a
    /// collection and is associated with a value. Map keys and values can be any supported data
    /// type.
    /// TODO: Implement the ordered map and remove hashmap completely
    HashMap(HashMap<PHPValue, PHPValue>),
    /// Map data type is a collection of key-value pairs. Each key can only appear once in a
    /// collection and is associated with a value. Map keys and values can be any supported data
    /// type.
    Json(HashMap<String, PHPValue>),
    /// GeoJSON data type are JSON formatted strings to encode geospatial information.
    GeoJSON(String),

    /// HLL value
    HLL(Vec<u8>),

    /// Wildcard value.
    Wildcard,

    /// Infinity value.
    Infinity,
}

#[allow(clippy::derived_hash_with_manual_eq)]
impl Hash for PHPValue {
    fn hash<H: Hasher>(&self, state: &mut H) {
        // Always mix in the discriminant so different variants never accidentally collide.
        std::mem::discriminant(self).hash(state);
        match self {
            PHPValue::Nil => {}
            PHPValue::Bool(v) => v.hash(state),
            PHPValue::Int(v) => v.hash(state),
            PHPValue::UInt(v) => v.hash(state),
            PHPValue::Float(v) => v.hash(state),
            PHPValue::String(v) | PHPValue::GeoJSON(v) => v.hash(state),
            PHPValue::Blob(v) | PHPValue::HLL(v) => v.hash(state),
            PHPValue::List(v) => v.hash(state),
            // HashMap iteration order is unspecified; hashing pair-by-pair would violate
            // the `k1 == k2 ⇒ hash(k1) == hash(k2)` contract. Fold a commutative XOR of
            // per-entry hashes so order does not matter.
            PHPValue::HashMap(map) => {
                map.len().hash(state);
                let mut acc: u64 = 0;
                for (k, v) in map {
                    let mut h = std::collections::hash_map::DefaultHasher::new();
                    k.hash(&mut h);
                    v.hash(&mut h);
                    acc ^= h.finish();
                }
                acc.hash(state);
            }
            PHPValue::Json(map) => {
                map.len().hash(state);
                let mut acc: u64 = 0;
                for (k, v) in map {
                    let mut h = std::collections::hash_map::DefaultHasher::new();
                    k.hash(&mut h);
                    v.hash(&mut h);
                    acc ^= h.finish();
                }
                acc.hash(state);
            }
            PHPValue::Infinity | PHPValue::Wildcard => {
                // Discriminant already mixed in above; sentinel variants carry no extra state.
            }
        }
    }
}

impl PHPValue {
    /// Returns a string representation of the value.
    pub fn as_string(&self) -> String {
        match *self {
            PHPValue::Nil => "<null>".to_string(),
            PHPValue::Int(ref val) => val.to_string(),
            PHPValue::UInt(ref val) => val.to_string(),
            PHPValue::Bool(ref val) => val.to_string(),
            PHPValue::Float(ref val) => val.to_string(),
            PHPValue::String(ref val) => val.to_string(),
            PHPValue::GeoJSON(ref val) => format!("GeoJSON('{val}')"),
            PHPValue::Blob(ref val) => format!("Blob({val:?})"),
            PHPValue::HLL(ref val) => format!("HLL('{val:?}')"),
            PHPValue::List(ref val) => format!("{val:?}"),
            PHPValue::HashMap(ref val) => format!("{val:?}"),
            PHPValue::Json(ref val) => format!("{val:?}"),
            PHPValue::Infinity => "<infinity>".to_string(),
            PHPValue::Wildcard => "<wildcard>".to_string(),
            // PHPValue::OrderedMap(ref val) => format!("{:?}", val),
        }
    }
}

impl fmt::Display for PHPValue {
    fn fmt(&self, f: &mut fmt::Formatter) -> std::result::Result<(), fmt::Error> {
        write!(f, "{}", self.as_string())
    }
}

impl IntoZval for PHPValue {
    const TYPE: DataType = DataType::Mixed;
    const NULLABLE: bool = true;

    fn set_zval(self, zv: &mut Zval, persistent: bool) -> Result<()> {
        match self {
            PHPValue::Nil => zv.set_null(),
            PHPValue::Bool(b) => zv.set_bool(b),
            PHPValue::Int(i) => zv.set_long(i),
            PHPValue::UInt(ui) => zv.set_long(ui as i64),
            PHPValue::Float(f) => zv.set_double(f),
            PHPValue::String(s) => zv.set_string(&s, persistent)?,
            // PHPValue::Blob(b) => zv.set_binary(b),
            PHPValue::List(l) => zv.set_array(l)?,
            PHPValue::Json(h) => {
                let mut arr = ZendHashTable::with_capacity(h.len() as u32);
                for (k, v) in h.iter() {
                    arr.insert(k.to_string(), v.clone())?;
                }

                zv.set_hashtable(arr)
            }
            PHPValue::HashMap(h) => {
                let mut arr = ZendHashTable::with_capacity(h.len() as u32);
                for (k, v) in h.iter() {
                    arr.insert(k.to_string(), v.clone())?;
                }

                zv.set_hashtable(arr)
            }
            PHPValue::GeoJSON(s) => {
                let geo = GeoJSON { v: s };
                let zo: ZBox<ZendObject> = geo.into_zend_object()?;
                zo.set_zval(zv, persistent)?;
            }
            PHPValue::Blob(b) => {
                let blob = BLOB { v: b };
                let zo: ZBox<ZendObject> = blob.into_zend_object()?;
                zo.set_zval(zv, persistent)?;
            }
            PHPValue::HLL(b) => {
                let hll = HLL { v: b };
                let zo: ZBox<ZendObject> = hll.into_zend_object()?;
                zo.set_zval(zv, persistent)?;
            }
            PHPValue::Infinity => {
                let inf = Infinity {};
                let zo: ZBox<ZendObject> = inf.into_zend_object()?;
                zo.set_zval(zv, persistent)?;
            }
            PHPValue::Wildcard => {
                let inf = Wildcard {};
                let zo: ZBox<ZendObject> = inf.into_zend_object()?;
                zo.set_zval(zv, persistent)?;
            }
        }

        Ok(())
    }
}

/// Converts a `Zval` into a `PHPValue`. Returns `None` (and may throw a PHP exception)
/// for objects we don't recognise or values whose contents cannot be extracted; the caller
/// must propagate the `None` so PHP sees the thrown exception.
fn from_zval(zval: &Zval) -> Option<PHPValue> {
    match zval.get_type() {
        DataType::Object(_) => {
            if let Some(o) = zval.extract::<BLOB>() {
                return Some(PHPValue::Blob(o.v));
            } else if let Some(o) = zval.extract::<HLL>() {
                return Some(PHPValue::HLL(o.v));
            } else if let Some(o) = zval.extract::<GeoJSON>() {
                return Some(PHPValue::GeoJSON(o.v));
            } else if zval.extract::<Infinity>().is_some() {
                return Some(PHPValue::Infinity);
            } else if zval.extract::<Wildcard>().is_some() {
                return Some(PHPValue::Wildcard);
            }
            let _ = throw_msg::<()>("Invalid Object", ());
            None
        }
        DataType::Null => Some(PHPValue::Nil),
        DataType::False => Some(PHPValue::Bool(false)),
        DataType::True => Some(PHPValue::Bool(true)),
        DataType::Bool => zval.bool().map(PHPValue::Bool),
        DataType::Long => zval.long().map(PHPValue::Int),
        DataType::Double => zval
            .double()
            .map(|v| PHPValue::Float(ordered_float::OrderedFloat(v))),
        DataType::String => zval.string().map(PHPValue::String),
        DataType::Array => {
            let arr = zval.array()?;
            if arr.has_sequential_keys() {
                // Sequential integer keys (0..N) → list. Propagate child failure as None
                // instead of `.unwrap()` so a bad nested value cannot abort the process.
                let mut val_arr: Vec<PHPValue> = Vec::with_capacity(arr.len());
                for (_, v) in arr.iter() {
                    val_arr.push(from_zval(v)?);
                }
                Some(PHPValue::List(val_arr))
            } else {
                // Mixed or string-keyed array → hashmap. PHP arrays may carry both
                // integer and string keys in the same array (e.g. `[1 => 'a', 'x' => 'b']`);
                // a single pass handles every key type without silently dropping entries.
                let mut h = HashMap::<PHPValue, PHPValue>::with_capacity(arr.len());
                for (k, v) in arr.iter() {
                    let key = match k {
                        ArrayKey::Long(i) => PHPValue::Int(i),
                        ArrayKey::String(s) => PHPValue::String(s),
                        ArrayKey::Str(s) => PHPValue::String(s.to_string()),
                        // ext-php-rs 0.15.15+ added the `ZendString` key variant.
                        // `iter()` never yields it (keys come back as Long/String via
                        // ArrayKey::from_zval), but the match must stay exhaustive.
                        ArrayKey::ZendString(s) => {
                            PHPValue::String(s.as_str().unwrap_or_default().to_string())
                        }
                    };
                    h.insert(key, from_zval(v)?);
                }
                Some(PHPValue::HashMap(h))
            }
        }
        // Any data type we don't model yet (Reference, Resource, ...). Throw rather than
        // panic so the host process survives encountering an unfamiliar zval.
        _ => {
            let _ = throw_msg::<()>("Unsupported PHP value type", ());
            None
        }
    }
}

impl FromZval<'_> for PHPValue {
    const TYPE: DataType = DataType::Mixed;

    fn from_zval(zval: &Zval) -> Option<Self> {
        from_zval(zval)
    }
}

impl From<HashMap<String, aero::Value>> for PHPValue {
    fn from(h: HashMap<String, aero::Value>) -> Self {
        let mut hash = HashMap::<PHPValue, PHPValue>::with_capacity(h.len());
        h.iter().for_each(|(k, v)| {
            hash.insert(PHPValue::String(k.into()), (*v).clone().into());
        });
        PHPValue::HashMap(hash)
    }
}

impl From<HashMap<PHPValue, PHPValue>> for PHPValue {
    fn from(h: HashMap<PHPValue, PHPValue>) -> Self {
        PHPValue::HashMap(h)
    }
}

impl From<PHPValue> for aero::Value {
    fn from(other: PHPValue) -> Self {
        match other {
            PHPValue::Nil => aero::Value::Nil,
            PHPValue::Bool(b) => aero::Value::Bool(b),
            PHPValue::Int(i) => aero::Value::Int(i),
            // Aerospike server stores all integers as signed i64. Reject values that don't
            // fit instead of silently wrapping to a negative number (which would corrupt
            // user data). Surfaces a PHP exception via throw_msg + Nil sentinel.
            PHPValue::UInt(ui) => match i64::try_from(ui) {
                Ok(v) => aero::Value::Int(v),
                Err(_) => {
                    let _ = throw_msg::<()>(
                        "Value::uint exceeds i64::MAX; Aerospike integers are signed 64-bit",
                        (),
                    );
                    aero::Value::Nil
                }
            },
            PHPValue::Float(f) => aero::Value::Float(f64::from(f).into()),
            PHPValue::String(s) => aero::Value::String(s),
            PHPValue::Blob(b) => aero::Value::Blob(b),
            PHPValue::List(l) => aero::Value::List(l.into_iter().map(Into::into).collect()),
            PHPValue::HashMap(h) => {
                let mut m = HashMap::<aero::Value, aero::Value>::with_capacity(h.len());
                for (k, v) in h {
                    m.insert(k.into(), v.into());
                }
                aero::Value::HashMap(m)
            }
            // Aerospike has no separate Json type; collapse to a string-keyed HashMap.
            PHPValue::Json(h) => {
                let mut m = HashMap::<aero::Value, aero::Value>::with_capacity(h.len());
                for (k, v) in h {
                    m.insert(aero::Value::String(k), v.into());
                }
                aero::Value::HashMap(m)
            }
            PHPValue::GeoJSON(gj) => aero::Value::GeoJSON(gj),
            PHPValue::HLL(b) => aero::Value::HLL(b),
            PHPValue::Infinity => aero::Value::Infinity,
            PHPValue::Wildcard => aero::Value::Wildcard,
        }
    }
}

impl From<aero::Value> for PHPValue {
    fn from(other: aero::Value) -> Self {
        match other {
            aero::Value::Nil => PHPValue::Nil,
            aero::Value::Bool(b) => PHPValue::Bool(b),
            aero::Value::Int(i) => PHPValue::Int(i),
            aero::Value::Float(f) => PHPValue::Float(ordered_float::OrderedFloat(f64::from(f))),
            aero::Value::String(s) => PHPValue::String(s),
            aero::Value::Blob(b) => PHPValue::Blob(b),
            aero::Value::List(l) | aero::Value::MultiResult(l) => {
                PHPValue::List(l.into_iter().map(Into::into).collect())
            }
            aero::Value::HashMap(h) => {
                let mut m = HashMap::<PHPValue, PHPValue>::with_capacity(h.len());
                for (k, v) in h {
                    m.insert(k.into(), v.into());
                }
                PHPValue::HashMap(m)
            }
            aero::Value::OrderedMap(h) => {
                let mut m = HashMap::<PHPValue, PHPValue>::with_capacity(h.len());
                for (k, v) in h {
                    m.insert(k.into(), v.into());
                }
                PHPValue::HashMap(m)
            }
            aero::Value::KeyValueList(kv) => {
                let mut m = HashMap::<PHPValue, PHPValue>::with_capacity(kv.len());
                for (k, v) in kv {
                    m.insert(k.into(), v.into());
                }
                PHPValue::HashMap(m)
            }
            aero::Value::GeoJSON(gj) => PHPValue::GeoJSON(gj),
            aero::Value::HLL(b) => PHPValue::HLL(b),
            aero::Value::Infinity => PHPValue::Infinity,
            aero::Value::Wildcard => PHPValue::Wildcard,
        }
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  Value
//
////////////////////////////////////////////////////////////////////////////////////////////

#[php_class]
#[php(name = "Aerospike\\Value")]
pub struct Value;

/// Value interface is used to efficiently serialize objects into the wire protocol.
#[php_impl]
impl Value {
    pub fn nil() -> PHPValue {
        PHPValue::Nil
    }

    pub fn int(val: i64) -> PHPValue {
        PHPValue::Int(val)
    }

    pub fn uint(val: u64) -> PHPValue {
        PHPValue::UInt(val)
    }

    pub fn float(val: f64) -> PHPValue {
        PHPValue::Float(ordered_float::OrderedFloat(val))
    }

    pub fn bool(val: bool) -> PHPValue {
        PHPValue::Bool(val)
    }

    pub fn string(val: String) -> PHPValue {
        PHPValue::String(val)
    }

    pub fn list(val: Vec<PHPValue>) -> PHPValue {
        PHPValue::List(val)
    }

    pub fn map(val: &Zval) -> PHPValue {
        match from_zval(val) {
            Some(PHPValue::HashMap(hm)) => PHPValue::HashMap(hm),
            _ => {
                let _ = throw_msg::<()>("Invalid value", ());
                PHPValue::Nil
            }
        }
    }

    pub fn blob(zval: &Zval) -> PhpResult<PHPValue> {
        match zval.get_type() {
            DataType::String => {
                if let Some(bin) = zval.binary::<u8>() {
                    Ok(PHPValue::Blob(bin))
                } else if let Some(s) = zval.string() {
                    Ok(PHPValue::Blob(s.into_bytes()))
                } else {
                    throw_msg(
                        "Value::blob: failed to read string contents",
                        PHPValue::Blob(Vec::new()),
                    )
                }
            }
            DataType::Array => {
                let arr = match zval.array() {
                    Some(a) if a.has_sequential_keys() => a,
                    _ => {
                        return throw_msg(
                            "Invalid Array type for Value::blob. Must be an array of integers [0, 255]",
                            PHPValue::Blob(Vec::new()),
                        );
                    }
                };
                let mut bytes: Vec<u8> = Vec::with_capacity(arr.len());
                for (_, v) in arr.iter() {
                    match from_zval(v) {
                        Some(PHPValue::Int(b)) if (0..=255).contains(&b) => bytes.push(b as u8),
                        Some(PHPValue::Int(b)) => {
                            return throw_msg(
                                &format!(
                                    "Invalid value {b} in array for Value::blob. Must be an array of integers [0, 255]"
                                ),
                                PHPValue::Blob(Vec::new()),
                            );
                        }
                        _ => {
                            return throw_msg(
                                "Invalid array for Value::blob. Must be an array of integers [0, 255]",
                                PHPValue::Blob(Vec::new()),
                            );
                        }
                    }
                }
                Ok(PHPValue::Blob(bytes))
            }
            _ => throw_msg(
                "Invalid Array type for Value::blob. Must be an array of integers [0, 255]",
                PHPValue::Blob(Vec::new()),
            ),
        }
    }

    pub fn geo_json(val: String) -> PHPValue {
        PHPValue::GeoJSON(val)
    }

    pub fn hll(val: Vec<u8>) -> PHPValue {
        PHPValue::HLL(val)
    }

    pub fn json(val: HashMap<String, PHPValue>) -> PHPValue {
        PHPValue::Json(val)
    }

    pub fn infinity() -> PHPValue {
        PHPValue::Infinity
    }

    pub fn wildcard() -> PHPValue {
        PHPValue::Wildcard
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  Converters
//
////////////////////////////////////////////////////////////////////////////////////////////

/// Conversion traits for interoperability with the underlying aerospike crate types.
impl From<&aero::Key> for Key {
    fn from(other: &aero::Key) -> Self {
        Key { _as: other.clone() }
    }
}

impl From<&aero::Record> for Record {
    fn from(other: &aero::Record) -> Self {
        Record { _as: other.clone() }
    }
}

impl From<&Bin> for aero::Bin {
    fn from(other: &Bin) -> Self {
        other._as.clone()
    }
}

#[derive(Debug)]
pub struct AeroPHPError(String);

impl std::fmt::Display for AeroPHPError {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        write!(f, "{}", self.0)
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
// ResultCode
//
////////////////////////////////////////////////////////////////////////////////////////////

/// ResultCode signifies the database operation error codes.
/// The positive numbers align with the server side file kvs.h.
#[php_class]
#[php(name = "Aerospike\\ResultCode")]
pub struct ResultCode {}

#[php_impl]
#[allow(non_camel_case_types)]
impl ResultCode {
    /// GRPC_ERROR is wrapped and directly returned from the grpc library
    const GRPC_ERROR: i32 = -21;

    /// BATCH_FAILED means one or more keys failed in a batch.
    const BATCH_FAILED: i32 = -20;

    /// NO_RESPONSE means no response was received from the server.
    const NO_RESPONSE: i32 = -19;

    /// NETWORK_ERROR defines a network error. Checked the wrapped error for detail.
    const NETWORK_ERROR: i32 = -18;

    /// COMMON_ERROR defines a common, none-aerospike error. Checked the wrapped error for detail.
    const COMMON_ERROR: i32 = -17;

    /// MAX_RETRIES_EXCEEDED defines max retries limit reached.
    const MAX_RETRIES_EXCEEDED: i32 = -16;

    /// MAX_ERROR_RATE defines max errors limit reached.
    const MAX_ERROR_RATE: i32 = -15;

    /// RACK_NOT_DEFINED defines requested Rack for node/namespace was not defined in the cluster.
    const RACK_NOT_DEFINED: i32 = -13;

    /// INVALID_CLUSTER_PARTITION_MAP defines cluster has an invalid partition map, usually due to bad configuration.
    const INVALID_CLUSTER_PARTITION_MAP: i32 = -12;

    /// SERVER_NOT_AVAILABLE defines server is not accepting requests.
    const SERVER_NOT_AVAILABLE: i32 = -11;

    /// CLUSTER_NAME_MISMATCH_ERROR defines cluster Name does not match the ClientPolicy.ClusterName value.
    const CLUSTER_NAME_MISMATCH_ERROR: i32 = -10;

    /// RECORDSET_CLOSED defines recordset has already been closed or cancelled
    const RECORDSET_CLOSED: i32 = -9;

    /// NO_AVAILABLE_CONNECTIONS_TO_NODE defines there were no connections available to the node in the pool, and the pool was limited
    const NO_AVAILABLE_CONNECTIONS_TO_NODE: i32 = -8;

    /// TYPE_NOT_SUPPORTED defines data type is not supported by aerospike server.
    const TYPE_NOT_SUPPORTED: i32 = -7;

    /// COMMAND_REJECTED defines info Command was rejected by the server.
    const COMMAND_REJECTED: i32 = -6;

    /// QUERY_TERMINATED defines query was terminated by user.
    const QUERY_TERMINATED: i32 = -5;

    /// SCAN_TERMINATED defines scan was terminated by user.
    const SCAN_TERMINATED: i32 = -4;

    /// INVALID_NODE_ERROR defines chosen node is not currently active.
    const INVALID_NODE_ERROR: i32 = -3;

    /// PARSE_ERROR defines client parse error.
    const PARSE_ERROR: i32 = -2;

    /// SERIALIZE_ERROR defines client serialization error.
    const SERIALIZE_ERROR: i32 = -1;

    /// OK defines operation was successful.
    const OK: i32 = 0;

    /// SERVER_ERROR defines unknown server failure.
    const SERVER_ERROR: i32 = 1;

    /// KEY_NOT_FOUND_ERROR defines on retrieving, touching or replacing a record that doesn't exist.
    const KEY_NOT_FOUND_ERROR: i32 = 2;

    /// GENERATION_ERROR defines on modifying a record with unexpected generation.
    const GENERATION_ERROR: i32 = 3;

    /// PARAMETER_ERROR defines bad parameter(s) were passed in database operation call.
    const PARAMETER_ERROR: i32 = 4;

    /// KEY_EXISTS_ERROR defines on create-only (write unique) operations on a record that already exists.
    const KEY_EXISTS_ERROR: i32 = 5;

    /// BIN_EXISTS_ERROR defines bin already exists on a create-only operation.
    const BIN_EXISTS_ERROR: i32 = 6;

    /// CLUSTER_KEY_MISMATCH defines expected cluster ID was not received.
    const CLUSTER_KEY_MISMATCH: i32 = 7;

    /// SERVER_MEM_ERROR defines server has run out of memory.
    const SERVER_MEM_ERROR: i32 = 8;

    /// TIMEOUT defines client or server has timed out.
    const TIMEOUT: i32 = 9;

    /// ALWAYS_FORBIDDEN defines operation not allowed in current configuration.
    const ALWAYS_FORBIDDEN: i32 = 10;

    /// PARTITION_UNAVAILABLE defines partition is unavailable.
    const PARTITION_UNAVAILABLE: i32 = 11;

    /// BIN_TYPE_ERROR defines operation is not supported with configured bin type (single-bin or multi-bin);
    const BIN_TYPE_ERROR: i32 = 12;

    /// RECORD_TOO_BIG defines record size exceeds limit.
    const RECORD_TOO_BIG: i32 = 13;

    /// KEY_BUSY defines too many concurrent operations on the same record.
    const KEY_BUSY: i32 = 14;

    /// SCAN_ABORT defines scan aborted by server.
    const SCAN_ABORT: i32 = 15;

    /// UNSUPPORTED_FEATURE defines unsupported Server Feature (e.g. Scan + UDF)
    const UNSUPPORTED_FEATURE: i32 = 16;

    /// BIN_NOT_FOUND defines bin not found on update-only operation.
    const BIN_NOT_FOUND: i32 = 17;

    /// DEVICE_OVERLOAD defines device not keeping up with writes.
    const DEVICE_OVERLOAD: i32 = 18;

    /// KEY_MISMATCH defines key type mismatch.
    const KEY_MISMATCH: i32 = 19;

    /// INVALID_NAMESPACE defines invalid namespace.
    const INVALID_NAMESPACE: i32 = 20;

    /// BIN_NAME_TOO_LONG defines bin name length greater than 14 characters, or maximum number of unique bin names are exceeded;
    const BIN_NAME_TOO_LONG: i32 = 21;

    /// FAIL_FORBIDDEN defines operation not allowed at this time.
    const FAIL_FORBIDDEN: i32 = 22;

    /// FAIL_ELEMENT_NOT_FOUND defines element Not Found in CDT
    const FAIL_ELEMENT_NOT_FOUND: i32 = 23;

    /// FAIL_ELEMENT_EXISTS defines element Already Exists in CDT
    const FAIL_ELEMENT_EXISTS: i32 = 24;

    /// ENTERPRISE_ONLY defines attempt to use an Enterprise feature on a Community server or a server without the applicable feature key;
    const ENTERPRISE_ONLY: i32 = 25;

    /// OP_NOT_APPLICABLE defines the operation cannot be applied to the current bin value on the server.
    const OP_NOT_APPLICABLE: i32 = 26;

    /// FILTERED_OUT defines the transaction was not performed because the filter was false.
    const FILTERED_OUT: i32 = 27;

    /// LOST_CONFLICT defines write command loses conflict to XDR.
    const LOST_CONFLICT: i32 = 28;

    /// QUERY_END defines there are no more records left for query.
    const QUERY_END: i32 = 50;

    /// SECURITY_NOT_SUPPORTED defines security type not supported by connected server.
    const SECURITY_NOT_SUPPORTED: i32 = 51;

    /// SECURITY_NOT_ENABLED defines administration command is invalid.
    const SECURITY_NOT_ENABLED: i32 = 52;

    /// SECURITY_SCHEME_NOT_SUPPORTED defines administration field is invalid.
    const SECURITY_SCHEME_NOT_SUPPORTED: i32 = 53;

    /// INVALID_COMMAND defines administration command is invalid.
    const INVALID_COMMAND: i32 = 54;

    /// INVALID_FIELD defines administration field is invalid.
    const INVALID_FIELD: i32 = 55;

    /// ILLEGAL_STATE defines security protocol not followed.
    const ILLEGAL_STATE: i32 = 56;

    /// INVALID_USER defines user name is invalid.
    const INVALID_USER: i32 = 60;

    /// USER_ALREADY_EXISTS defines user was previously created.
    const USER_ALREADY_EXISTS: i32 = 61;

    /// INVALID_PASSWORD defines password is invalid.
    const INVALID_PASSWORD: i32 = 62;

    /// EXPIRED_PASSWORD defines security credential is invalid.
    const EXPIRED_PASSWORD: i32 = 63;

    /// FORBIDDEN_PASSWORD defines forbidden password (e.g. recently used)
    const FORBIDDEN_PASSWORD: i32 = 64;

    /// INVALID_CREDENTIAL defines security credential is invalid.
    const INVALID_CREDENTIAL: i32 = 65;

    /// EXPIRED_SESSION defines login session expired.
    const EXPIRED_SESSION: i32 = 66;

    /// INVALID_ROLE defines role name is invalid.
    const INVALID_ROLE: i32 = 70;

    /// ROLE_ALREADY_EXISTS defines role already exists.
    const ROLE_ALREADY_EXISTS: i32 = 71;

    /// INVALID_PRIVILEGE defines privilege is invalid.
    const INVALID_PRIVILEGE: i32 = 72;

    /// INVALID_WHITELIST defines invalid IP address whiltelist
    const INVALID_WHITELIST: i32 = 73;

    /// QUOTAS_NOT_ENABLED defines Quotas not enabled on server.
    const QUOTAS_NOT_ENABLED: i32 = 74;

    /// INVALID_QUOTA defines invalid quota value.
    const INVALID_QUOTA: i32 = 75;

    /// NOT_AUTHENTICATED defines user must be authentication before performing database operations.
    const NOT_AUTHENTICATED: i32 = 80;

    /// ROLE_VIOLATION defines user does not posses the required role to perform the database operation.
    const ROLE_VIOLATION: i32 = 81;

    /// NOT_WHITELISTED defines command not allowed because sender IP address not whitelisted.
    const NOT_WHITELISTED: i32 = 82;

    /// QUOTA_EXCEEDED defines Quota exceeded.
    const QUOTA_EXCEEDED: i32 = 83;

    /// UDF_BAD_RESPONSE defines a user defined function returned an error code.
    const UDF_BAD_RESPONSE: i32 = 100;

    /// BATCH_DISABLED defines batch functionality has been disabled.
    const BATCH_DISABLED: i32 = 150;

    /// BATCH_MAX_REQUESTS_EXCEEDED defines batch max requests have been exceeded.
    const BATCH_MAX_REQUESTS_EXCEEDED: i32 = 151;

    /// BATCH_QUEUES_FULL defines all batch queues are full.
    const BATCH_QUEUES_FULL: i32 = 152;

    /// GEO_INVALID_GEOJSON defines invalid GeoJSON on insert/update
    const GEO_INVALID_GEOJSON: i32 = 160;

    /// INDEX_FOUND defines secondary index already exists.
    const INDEX_FOUND: i32 = 200;

    /// INDEX_NOTFOUND defines requested secondary index does not exist.
    const INDEX_NOT_FOUND: i32 = 201;

    /// INDEX_OOM defines secondary index memory space exceeded.
    const INDEX_OOM: i32 = 202;

    /// INDEX_NOTREADABLE defines secondary index not available.
    const INDEX_NOT_READABLE: i32 = 203;

    /// INDEX_GENERIC defines generic secondary index error.
    const INDEX_GENERIC: i32 = 204;

    /// INDEX_NAME_MAXLEN defines index name maximum length exceeded.
    const INDEX_NAME_MAX_LEN: i32 = 205;

    /// INDEX_MAXCOUNT defines maximum number of indexes exceeded.
    const INDEX_MAX_COUNT: i32 = 206;

    /// QUERY_ABORTED defines secondary index query aborted.
    const QUERY_ABORTED: i32 = 210;

    /// QUERY_QUEUEFULL defines secondary index queue full.
    const QUERY_QUEUE_FULL: i32 = 211;

    /// QUERY_TIMEOUT defines secondary index query timed out on server.
    const QUERY_TIMEOUT: i32 = 212;

    /// QUERY_GENERIC defines generic query error.
    const QUERY_GENERIC: i32 = 213;

    /// QUERY_NETIO_ERR defines query NetIO error on server
    const QUERY_NET_IO_ERR: i32 = 214;

    /// QUERY_DUPLICATE defines duplicate TaskId sent for the statement
    const QUERY_DUPLICATE: i32 = 215;

    /// AEROSPIKE_ERR_UDF_NOT_FOUND defines UDF does not exist.
    const AEROSPIKE_ERR_UDF_NOT_FOUND: i32 = 1301;

    /// AEROSPIKE_ERR_LUA_FILE_NOT_FOUND defines LUA file does not exist.
    const AEROSPIKE_ERR_LUA_FILE_NOT_FOUND: i32 = 1302;

    pub fn to_string(code: i32) -> String {
        match code {
             ResultCode::GRPC_ERROR => "wrapped and directly returned from the grpc library".into(),
             ResultCode::BATCH_FAILED => "one or more keys failed in a batch".into(),
             ResultCode::NO_RESPONSE => "no response was received from the server".into(),
             ResultCode::NETWORK_ERROR => "a network error. Checked the wrapped error for detail".into(),
             ResultCode::COMMON_ERROR => "a common, none-aerospike error. Checked the wrapped error for detail".into(),
             ResultCode::MAX_RETRIES_EXCEEDED => "max retries limit reached".into(),
             ResultCode::MAX_ERROR_RATE => "max errors limit reached".into(),
             ResultCode::RACK_NOT_DEFINED => "requested Rack for node/namespace was not defined in the cluster".into(),
             ResultCode::INVALID_CLUSTER_PARTITION_MAP => "cluster has an invalid partition map, usually due to bad configuration".into(),
             ResultCode::SERVER_NOT_AVAILABLE => "server is not accepting requests".into(),
             ResultCode::CLUSTER_NAME_MISMATCH_ERROR => "cluster Name does not match the ClientPolicy.ClusterName value".into(),
             ResultCode::RECORDSET_CLOSED=> "recordset has already been closed or cancelled".into(),
             ResultCode::NO_AVAILABLE_CONNECTIONS_TO_NODE=> "there were no connections available to the node in the pool, and the pool was limited".into(),
             ResultCode::TYPE_NOT_SUPPORTED=> "data type is not supported by aerospike server".into(),
             ResultCode::COMMAND_REJECTED=> "info Command was rejected by the server".into(),
             ResultCode::QUERY_TERMINATED=> "query was terminated by user".into(),
             ResultCode::SCAN_TERMINATED=> "scan was terminated by user".into(),
             ResultCode::INVALID_NODE_ERROR=> "chosen node is not currently active".into(),
             ResultCode::PARSE_ERROR=> "client parse error".into(),
             ResultCode::SERIALIZE_ERROR=> "client serialization error".into(),
             ResultCode::OK=> "operation was successful".into(),
             ResultCode::SERVER_ERROR=> "unknown server failure".into(),
             ResultCode::KEY_NOT_FOUND_ERROR=> "on retrieving, touching or replacing a record that doesn't exist".into(),
             ResultCode::GENERATION_ERROR=> "on modifying a record with unexpected generation".into(),
             ResultCode::PARAMETER_ERROR=> "bad parameter(s) were passed in database operation call".into(),
             ResultCode::KEY_EXISTS_ERROR=> "on create-only (write unique) operations on a record that already exists".into(),
             ResultCode::BIN_EXISTS_ERROR=> "bin already exists on a create-only operation".into(),
             ResultCode::CLUSTER_KEY_MISMATCH=> "expected cluster ID was not received".into(),
             ResultCode::SERVER_MEM_ERROR=> "server has run out of memory".into(),
             ResultCode::TIMEOUT=> "client or server has timed out".into(),
             ResultCode::ALWAYS_FORBIDDEN=> "operation not allowed in current configuration".into(),
             ResultCode::PARTITION_UNAVAILABLE=> "partition is unavailable".into(),
             ResultCode::BIN_TYPE_ERROR=> "operation is not supported with configured bin type (single-bin or multi-bin)".into(),
             ResultCode::RECORD_TOO_BIG=> "record size exceeds limit".into(),
             ResultCode::KEY_BUSY=> "too many concurrent operations on the same record".into(),
             ResultCode::SCAN_ABORT=> "scan aborted by server".into(),
             ResultCode::UNSUPPORTED_FEATURE=> "unsupported Server Feature (e.g. Scan + UDF)".into(),
             ResultCode::BIN_NOT_FOUND=> "bin not found on update-only operation".into(),
             ResultCode::DEVICE_OVERLOAD=> "device not keeping up with writes".into(),
             ResultCode::KEY_MISMATCH=> "key type mismatch".into(),
             ResultCode::INVALID_NAMESPACE=> "invalid namespace".into(),
             ResultCode::BIN_NAME_TOO_LONG=> "bin name length greater than 14 characters, or maximum number of unique bin names are exceeded".into(),
             ResultCode::FAIL_FORBIDDEN=> "operation not allowed at this time".into(),
             ResultCode::FAIL_ELEMENT_NOT_FOUND=> "element Not Found in CDT".into(),
             ResultCode::FAIL_ELEMENT_EXISTS=> "element Already Exists in CDT".into(),
             ResultCode::ENTERPRISE_ONLY=> "attempt to use an Enterprise feature on a Community server or a server without the applicable feature key".into(),
             ResultCode::OP_NOT_APPLICABLE=> "the operation cannot be applied to the current bin value on the server".into(),
             ResultCode::FILTERED_OUT=> "the transaction was not performed because the filter was false".into(),
             ResultCode::LOST_CONFLICT=> "write command loses conflict to XDR".into(),
             ResultCode::QUERY_END=> "there are no more records left for query".into(),
             ResultCode::SECURITY_NOT_SUPPORTED=> "security type not supported by connected server".into(),
             ResultCode::SECURITY_NOT_ENABLED=> "administration command is invalid".into(),
             ResultCode::SECURITY_SCHEME_NOT_SUPPORTED=> "administration field is invalid".into(),
             ResultCode::INVALID_COMMAND=> "administration command is invalid".into(),
             ResultCode::INVALID_FIELD=> "administration field is invalid".into(),
             ResultCode::ILLEGAL_STATE=> "security protocol not followed".into(),
             ResultCode::INVALID_USER=> "user name is invalid".into(),
             ResultCode::USER_ALREADY_EXISTS=> "user was previously created".into(),
             ResultCode::INVALID_PASSWORD=> "password is invalid".into(),
             ResultCode::EXPIRED_PASSWORD=> "security credential is invalid".into(),
             ResultCode::FORBIDDEN_PASSWORD=> "forbidden password (e.g. recently used)".into(),
             ResultCode::INVALID_CREDENTIAL=> "security credential is invalid".into(),
             ResultCode::EXPIRED_SESSION=> "login session expired".into(),
             ResultCode::INVALID_ROLE=> "role name is invalid".into(),
             ResultCode::ROLE_ALREADY_EXISTS=> "role already exists".into(),
             ResultCode::INVALID_PRIVILEGE=> "privilege is invalid".into(),
             ResultCode::INVALID_WHITELIST=> "invalid IP address whiltelist".into(),
             ResultCode::QUOTAS_NOT_ENABLED=> "Quotas not enabled on server".into(),
             ResultCode::INVALID_QUOTA=> "invalid quota value".into(),
             ResultCode::NOT_AUTHENTICATED=> "user must be authentication before performing database operations".into(),
             ResultCode::ROLE_VIOLATION=> "user does not posses the required role to perform the database operation".into(),
             ResultCode::NOT_WHITELISTED=> "command not allowed because sender IP address not whitelisted".into(),
             ResultCode::QUOTA_EXCEEDED=> "Quota exceeded".into(),
             ResultCode::UDF_BAD_RESPONSE => "a user defined function returned an error code".into(),
             ResultCode::BATCH_DISABLED => "batch functionality has been disabled".into(),
             ResultCode::BATCH_MAX_REQUESTS_EXCEEDED => "batch max requests have been exceeded".into(),
             ResultCode::BATCH_QUEUES_FULL => "all batch queues are full".into(),
             ResultCode::GEO_INVALID_GEOJSON => "invalid GeoJSON on insert/update".into(),
             ResultCode::INDEX_FOUND => "secondary index already exists".into(),
             ResultCode::INDEX_NOT_FOUND => "requested secondary index does not exist".into(),
             ResultCode::INDEX_OOM => "secondary index memory space exceeded".into(),
             ResultCode::INDEX_NOT_READABLE => "secondary index not available".into(),
             ResultCode::INDEX_GENERIC => "generic secondary index error".into(),
             ResultCode::INDEX_NAME_MAX_LEN => "index name maximum length exceeded".into(),
             ResultCode::INDEX_MAX_COUNT => "maximum number of indexes exceeded".into(),
             ResultCode::QUERY_ABORTED => "secondary index query aborted".into(),
             ResultCode::QUERY_QUEUE_FULL => "secondary index queue full".into(),
             ResultCode::QUERY_TIMEOUT => "secondary index query timed out on server".into(),
             ResultCode::QUERY_GENERIC => "generic query error".into(),
             ResultCode::QUERY_NET_IO_ERR => "query NetIO error on server".into(),
             ResultCode::QUERY_DUPLICATE => "duplicate TaskId sent for the statement".into(),
             ResultCode::AEROSPIKE_ERR_UDF_NOT_FOUND => "UDF does not exist".into(),
             ResultCode::AEROSPIKE_ERR_LUA_FILE_NOT_FOUND => "LUA file does not exist".into(),
             _ => "Unknown Error".into()
             }
    }
}

////////////////////////////////////////////////////////////////////////////////////////////
//
//  utility methods
//
////////////////////////////////////////////////////////////////////////////////////////////

/// Returns true iff every element is `PHPValue::HLL`. Throws an Aerospike exception
/// (no panic) on the first non-HLL value encountered.
fn assert_hll_list(val: &[PHPValue]) -> bool {
    for v in val {
        if !matches!(v, PHPValue::HLL(_)) {
            let _ = throw_msg::<()>("Invalid type", ());
            return false;
        }
    }
    true
}

/// Build a PHP `Zval` wrapping a fresh `Client` PHP object that shares the `Arc<aero::Client>`
/// from the given cache entry. Each PHP-visible `$client` is its own Zend object; the
/// underlying connection pool is reference-counted via `Arc`.
fn zval_from_entry(entry: &ClientEntry) -> PhpResult<Zval> {
    let client = Client {
        client: entry.client.clone(),
        hosts: entry.hosts.clone(),
        policy_fingerprint: entry.policy_fingerprint.clone(),
    };
    let mut zval = Zval::new();
    let zo: ZBox<ZendObject> = client
        .into_zend_object()
        .map_err(|_| PhpException::default("failed to allocate Client zend object".into()))?;
    zo.set_zval(&mut zval, false)
        .map_err(|_| PhpException::default("failed to set Client zval".into()))?;
    Ok(zval)
}

/// Used by the `phpinfo()` function and when you run `php -i`.
pub extern "C" fn php_module_info(_module: *mut ModuleEntry) {
    info_table_start!();
    info_table_row!("Aerospike Client PHP", "enabled");
    info_table_row!("Version", env!("CARGO_PKG_VERSION"));
    info_table_row!("Transport", "native (aerospike-client-rust)");
    info_table_end!();
}

#[php_module]
#[php(startup = aerospike_php_startup)]
pub fn get_module(module: ModuleBuilder) -> ModuleBuilder {
    module
        .class::<ExpType>()
        .class::<Expression>()
        .class::<ReadModeAP>()
        .class::<ReadModeSC>()
        .class::<RecordExistsAction>()
        .class::<QueryDuration>()
        .class::<CommitLevel>()
        .class::<ConsistencyLevel>()
        .class::<GenerationPolicy>()
        .class::<Expiration>()
        .class::<Concurrency>()
        .class::<ListOrderType>()
        .class::<MapOrderType>()
        .class::<CDTContext>()
        .class::<ReadPolicy>()
        .class::<AdminPolicy>()
        .class::<InfoPolicy>()
        .class::<WritePolicy>()
        .class::<QueryPolicy>()
        .class::<ScanPolicy>()
        .class::<IndexCollectionType>()
        .class::<ParticleType>()
        .class::<IndexType>()
        .class::<Filter>()
        .class::<Statement>()
        .class::<PartitionStatus>()
        .class::<PartitionFilter>()
        .class::<Recordset>()
        .class::<Bin>()
        .class::<Record>()
        .class::<BatchPolicy>()
        .class::<BatchReadPolicy>()
        .class::<BatchWritePolicy>()
        .class::<BatchDeletePolicy>()
        .class::<BatchUdfPolicy>()
        .class::<Operation>()
        .class::<BatchRecord>()
        .class::<BatchRead>()
        .class::<BatchWrite>()
        .class::<BatchDelete>()
        .class::<BatchUdf>()
        .class::<UdfLanguage>()
        .class::<UdfMeta>()
        .class::<UserRole>()
        .class::<Role>()
        .class::<Privilege>()
        .class::<CdtListReturnType>()
        .class::<CdtListWriteFlags>()
        .class::<CdtListSortFlags>()
        .class::<CdtListPolicy>()
        .class::<CdtListOperation>()
        .class::<CdtMapReturnType>()
        .class::<CdtMapWriteMode>()
        .class::<CdtMapWriteFlags>()
        .class::<CdtMapPolicy>()
        .class::<CdtMapOperation>()
        .class::<CdtHllWriteFlags>()
        .class::<CdtHllPolicy>()
        .class::<CdtHllOperation>()
        .class::<CdtBitwiseWriteFlags>()
        .class::<CdtBitwiseResizeFlags>()
        .class::<CdtBitwiseOverflowAction>()
        .class::<CdtBitwisePolicy>()
        .class::<CdtBitwiseOperation>()
        .class::<ClientPolicy>()
        .class::<Client>()
        .class::<AerospikeException>()
        .class::<Key>()
        .class::<GeoJSON>()
        .class::<Json>()
        .class::<Infinity>()
        .class::<Wildcard>()
        .class::<BLOB>()
        .class::<HLL>()
        .class::<Value>()
        .class::<ResultCode>()
}
