//! A program compiled by the PHP that loaded the extension, as one digest of
//! its op arrays (ADR-0130): the main script's, each closure's, each function's
//! and each method's the compile declared, in the order PHP declared them.
//! Each op is read whole but for its handler: opcode, operand kinds, operands,
//! extended value and line. A constant operand is read by its exact value, an
//! array or a constant expression whole; any other operand, a jump among
//! them, is an offset PHP lays out alike for alike programs. The file's own
//! path is never read: every program compiles under one name. What the
//! compile declared is taken out of PHP's tables again, so the next program
//! compiles as the first did.

#![allow(
    unsafe_code,
    reason = "the op arrays exist only behind the Zend engine's own pointers"
)]

use std::ffi::CStr;
use std::panic::AssertUnwindSafe;

use ext_php_rs::ffi;
use ext_php_rs::types::ZendStr;
use ext_php_rs::zend::try_catch;

use crate::canon::Canon;
use crate::value::{self, Walk};

#[cfg(not(target_pointer_width = "64"))]
compile_error!("outbreak.so reads constant operands as PHP lays them out on 64-bit systems");

/// The name every program compiles under, so no path reaches its op arrays.
const NAME: &CStr = c"outbreak-program.php";

/// Leaves PHP mode at once, so the program is read from its first byte as a file is.
const OPENING: &[u8] = b"?>";

/// A constant operand.
const IS_CONST: u8 = 1;

/// A user function, as `zend_function.type` says it.
const USER_FUNCTION: u8 = 2;

/// The digest of a program's op arrays; none where it does not compile, or
/// holds a value the walk does not read.
#[must_use]
pub fn digest(source: &[u8]) -> Option<String> {
    let mut text = OPENING.to_vec();
    text.extend_from_slice(source);
    let code = ZendStr::new(&text, false);

    // SAFETY: the compiler globals live as long as the request that called the extension.
    let globals = unsafe { ffi::ext_php_rs_compiler_globals() };
    // SAFETY: as above.
    let (functions, classes, counter) = unsafe {
        (
            (*(*globals).function_table).nNumUsed,
            (*(*globals).class_table).nNumUsed,
            (*globals).rtd_key_counter,
        )
    };

    let compiled = try_catch(AssertUnwindSafe(|| {
        // SAFETY: every program compiles from the same counter, so its runtime keys are alike.
        unsafe { (*globals).rtd_key_counter = 0 };
        // SAFETY: `code` outlives the call, and NAME is a constant C string.
        let main =
            unsafe { ffi::ext_php_rs_zend_compile_string(code.as_ptr().cast_mut(), NAME.as_ptr()) };

        if main.is_null() {
            return None;
        }

        let mut canon = Canon::new();
        // SAFETY: a compiled op array, and the tables PHP declared its functions and classes into.
        let read = unsafe {
            op_array(&mut canon, main, 0).is_some()
                && declared(
                    &mut canon,
                    (*globals).function_table,
                    functions,
                    Kind::Function,
                )
                .is_some()
                && declared(&mut canon, (*globals).class_table, classes, Kind::Class).is_some()
        };
        // SAFETY: the op array is the compile's own, freed once, as PHP frees an eval's.
        unsafe {
            ffi::destroy_op_array(main);
            ext_php_rs::alloc::efree(main.cast::<u8>());
        }

        read.then(|| canon.digest())
    }));

    // SAFETY: the tables are PHP's, and only what this compile added is taken out.
    unsafe {
        removed((*globals).function_table, functions);
        removed((*globals).class_table, classes);
        (*globals).rtd_key_counter = counter;
    }

    compiled.ok().flatten()
}

#[derive(Clone, Copy)]
enum Kind {
    Function,
    Class,
}

/// Each function or class the compile added to a table, after the first `from` entries, by its key.
unsafe fn declared(
    canon: &mut Canon,
    table: *mut ffi::HashTable,
    from: u32,
    kind: Kind,
) -> Option<()> {
    canon.section(match kind {
        Kind::Function => "functions",
        Kind::Class => "classes",
    });
    let mut count = 0;

    // SAFETY: the caller hands a live table; buckets past `from` are this compile's.
    for bucket in unsafe { buckets(table, from) } {
        // SAFETY: a used bucket of a live table.
        let (key, entry) = unsafe { (&(*bucket).key, &(*bucket).val) };
        // SAFETY: a table's key is a live string or none.
        canon.bytes(unsafe { value::text(*key) }.unwrap_or_default());
        // SAFETY: both tables hold pointers, to functions and to classes.
        match kind {
            Kind::Function => unsafe { function(canon, entry.value.func) }?,
            Kind::Class => unsafe { class(canon, entry.value.ce) }?,
        }
        count += 1;
    }

    canon.end(count);
    Some(())
}

/// A class's name, then each method it holds, by its key.
unsafe fn class(canon: &mut Canon, class: *const ffi::zend_class_entry) -> Option<()> {
    if class.is_null() {
        return None;
    }

    // SAFETY: a live class entry.
    let (name, methods) = unsafe { ((*class).name, &raw const (*class).function_table) };
    // SAFETY: a class's name is a live string.
    canon.bytes(unsafe { value::text(name) }.unwrap_or_default());
    let mut count = 0;

    // SAFETY: a class's own table of methods.
    for bucket in unsafe { buckets(methods.cast_mut(), 0) } {
        // SAFETY: a used bucket.
        let (key, entry) = unsafe { (&(*bucket).key, &(*bucket).val) };
        // SAFETY: as above.
        canon.bytes(unsafe { value::text(*key) }.unwrap_or_default());
        // SAFETY: a method table holds functions.
        unsafe { function(canon, entry.value.func) }?;
        count += 1;
    }

    canon.end(count);
    Some(())
}

/// A function: a user function by its op array, an internal one by its kind alone.
unsafe fn function(canon: &mut Canon, function: *const ffi::zend_function) -> Option<()> {
    if function.is_null() {
        return None;
    }

    // SAFETY: a live function; its `type` is common to every kind.
    let kind = unsafe { (*function).type_ };
    canon.number(u64::from(kind));

    if kind == USER_FUNCTION {
        // SAFETY: a user function is an op array.
        unsafe { op_array(canon, &raw const (*function).op_array, 0) }
    } else {
        Some(())
    }
}

/// An op array, with every closure and function it declares inside it.
unsafe fn op_array(
    canon: &mut Canon,
    array: *const ffi::zend_op_array,
    depth: usize,
) -> Option<()> {
    if array.is_null() || depth > value::DEPTH {
        return None;
    }

    // SAFETY: a live op array PHP compiled, read field by field.
    let array = unsafe { &*array };
    canon.section("op_array");
    // SAFETY: a live string or none.
    canon.bytes(unsafe { value::text(array.function_name) }.unwrap_or_default());

    for number in [
        array.fn_flags,
        array.num_args,
        array.required_num_args,
        array.T,
        array.line_start,
        array.line_end,
        array.last,
        array.num_dynamic_func_defs,
    ] {
        canon.number(u64::from(number));
    }

    let variables = usize::try_from(array.last_var).ok()?;

    for at in 0..variables {
        // SAFETY: `vars` holds `last_var` names.
        canon.bytes(unsafe { value::text(*array.vars.add(at)) }.unwrap_or_default());
    }

    canon.end(variables as u64);

    for at in 0..usize::try_from(array.last).ok()? {
        // SAFETY: `opcodes` holds `last` ops.
        unsafe { op(canon, array.opcodes.add(at)) }?;
    }

    for at in 0..usize::try_from(array.last_live_range).ok()? {
        // SAFETY: `live_range` holds `last_live_range` ranges.
        let range = unsafe { &*array.live_range.add(at) };
        canon.number(u64::from(range.var));
        canon.number(u64::from(range.start));
        canon.number(u64::from(range.end));
    }

    for at in 0..usize::try_from(array.last_try_catch).ok()? {
        // SAFETY: `try_catch_array` holds `last_try_catch` elements.
        let element = unsafe { &*array.try_catch_array.add(at) };
        canon.number(u64::from(element.try_op));
        canon.number(u64::from(element.catch_op));
        canon.number(u64::from(element.finally_op));
        canon.number(u64::from(element.finally_end));
    }

    canon.section("statics");
    // SAFETY: a live table or none.
    unsafe { value::table(canon, array.static_variables, Walk::Literal, depth + 1) }?;

    for at in 0..usize::try_from(array.num_dynamic_func_defs).ok()? {
        // SAFETY: `dynamic_func_defs` holds `num_dynamic_func_defs` op arrays.
        unsafe { op_array(canon, *array.dynamic_func_defs.add(at), depth + 1) }?;
    }

    Some(())
}

/// One op, its handler left out, its constant operands by their values.
unsafe fn op(canon: &mut Canon, op: *const ffi::zend_op) -> Option<()> {
    // SAFETY: a live op of a compiled op array.
    let read = unsafe { &*op };

    for number in [read.opcode, read.op1_type, read.op2_type, read.result_type] {
        canon.number(u64::from(number));
    }

    canon.number(u64::from(read.extended_value));
    canon.number(u64::from(read.lineno));
    // SAFETY: each operand is read as its kind says.
    unsafe {
        operand(canon, op, read.op1, read.op1_type)?;
        operand(canon, op, read.op2, read.op2_type)?;
        canon.number(u64::from(read.result.num));
    }

    Some(())
}

/// An operand: a constant by its value, found where PHP lays it, relative to its op; anything else as it is.
unsafe fn operand(
    canon: &mut Canon,
    op: *const ffi::zend_op,
    node: ffi::znode_op,
    kind: u8,
) -> Option<()> {
    if kind != IS_CONST {
        // SAFETY: every member of the union is a `u32`.
        canon.number(u64::from(unsafe { node.num }));

        return Some(());
    }

    // SAFETY: a constant operand is a signed byte offset from its op to its literal (RT_CONSTANT).
    let offset = isize::try_from(unsafe { node.constant }.cast_signed()).ok()?;
    // SAFETY: the literal lies at that offset within the op array's allocation.
    let literal = unsafe { op.byte_offset(offset).cast::<ffi::zval>() };

    // SAFETY: a literal is a live value.
    unsafe { value::of(canon, literal, Walk::Literal, 0) }
}

/// Each used bucket of a table from this position on.
unsafe fn buckets(table: *mut ffi::HashTable, from: u32) -> Vec<*mut ffi::Bucket> {
    if table.is_null() {
        return Vec::new();
    }

    // SAFETY: a live table; function and class tables are hashes, never packed.
    let (used, data) = unsafe { ((*table).nNumUsed, (*table).__bindgen_anon_1.arData) };

    (from..used)
        .filter_map(|at| usize::try_from(at).ok())
        // SAFETY: `arData` holds `nNumUsed` buckets.
        .map(|at| unsafe { data.add(at) })
        // SAFETY: as above; a deleted bucket is undefined.
        .filter(|bucket| unsafe { (**bucket).val.u1.v.type_ } != value::UNDEF)
        .collect()
}

/// Every entry from this position on taken out of the table again.
unsafe fn removed(table: *mut ffi::HashTable, from: u32) {
    // SAFETY: as `buckets`.
    let keys: Vec<*mut ffi::zend_string> = unsafe { buckets(table, from) }
        .into_iter()
        // SAFETY: a used bucket.
        .map(|bucket| unsafe { (*bucket).key })
        .filter(|key| !key.is_null())
        .collect();

    for key in keys {
        // SAFETY: each key names a live entry of this table, deleted once.
        unsafe { ffi::zend_hash_del(table, key) };
    }
}
