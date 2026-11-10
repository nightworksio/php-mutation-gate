//! PHP values written into a canonical text, each by its type and its exact
//! contents: a literal of a compiled program, which may be a constant
//! expression, or the state a call left behind, which may hold objects.

#![allow(unsafe_code, reason = "values exist only behind the Zend engine's own pointers")]

use std::collections::HashSet;

use ext_php_rs::ffi;

use crate::canon::Canon;

/// How deep a walk goes into arrays, objects and expressions before it gives up.
pub const DEPTH: usize = 64;

/// PHP's type of a value that is not there.
pub const UNDEF: u8 = 0;
const NULL: u8 = 1;
const FALSE: u8 = 2;
const TRUE: u8 = 3;
const LONG: u8 = 4;
const DOUBLE: u8 = 5;
const STRING: u8 = 6;
const ARRAY: u8 = 7;
const OBJECT: u8 = 8;
const RESOURCE: u8 = 9;
const REFERENCE: u8 = 10;
const CONSTANT_AST: u8 = 11;
const INDIRECT: u8 = 12;

/// A table PHP lays out as a list of values, its keys their positions.
const PACKED: u32 = 1 << 2;

/// The kind of an AST node that holds a value, and of one that holds a constant's name.
const AST_ZVAL: u16 = 1 << 6;
const AST_CONSTANT: u16 = AST_ZVAL + 1;
/// A node whose kind has this bit is special: it holds no children the walk can read, but for the two above.
const AST_SPECIAL: u16 = 1 << 6;
/// A node whose kind has this bit is a list, its children counted in the node.
const AST_LIST: u16 = 1 << 7;
/// A plain node's children are counted in its kind, above this shift.
const AST_CHILDREN_SHIFT: u16 = 8;

/// What a walk reads: a compiled program's literals, or a call's state.
#[derive(Clone, Copy, PartialEq, Eq)]
pub enum Walk {
    /// Scalars, arrays and constant expressions; nothing else is read.
    Literal,
    /// Scalars, arrays, objects by their properties and resources by their kind.
    State,
}

/// The objects a state walk has met, so a cycle is written once.
#[derive(Default)]
pub struct Seen {
    objects: HashSet<u32>,
    left: usize,
}

impl Seen {
    /// A walk that reads at most this many values.
    #[must_use]
    pub fn within(values: usize) -> Self {
        Self { objects: HashSet::new(), left: values }
    }

    fn spent(&mut self) -> Option<()> {
        self.left = self.left.checked_sub(1)?;
        Some(())
    }
}

/// The bytes of a PHP string; none where there is no string.
///
/// # Safety
///
/// `string` is a live `zend_string` or null.
#[must_use]
pub unsafe fn text<'a>(string: *const ffi::zend_string) -> Option<&'a [u8]> {
    if string.is_null() {
        return None;
    }

    // SAFETY: a live string holds `len` bytes from `val`.
    Some(unsafe { std::slice::from_raw_parts((*string).val.as_ptr().cast::<u8>(), (*string).len) })
}

/// A literal of a compiled program; none where it holds what a literal walk does not read.
///
/// # Safety
///
/// `value` is a live `zval`.
pub unsafe fn of(canon: &mut Canon, value: *const ffi::zval, walk: Walk, depth: usize) -> Option<()> {
    // SAFETY: as the caller says; a literal walk meets no object.
    unsafe { walked(canon, value, walk, depth, &mut Seen::within(usize::MAX)) }
}

/// A call's state: each value, object and array within it, read once.
///
/// # Safety
///
/// `value` is a live `zval`.
pub unsafe fn state(canon: &mut Canon, value: *const ffi::zval, seen: &mut Seen) -> Option<()> {
    // SAFETY: as the caller says.
    unsafe { walked(canon, value, Walk::State, 0, seen) }
}

/// A table's entries, each key then value; an absent table as an empty one.
///
/// # Safety
///
/// `table` is a live `HashTable` or null.
pub unsafe fn table(canon: &mut Canon, table: *const ffi::HashTable, walk: Walk, depth: usize) -> Option<()> {
    // SAFETY: as the caller says.
    unsafe { entries(canon, table, walk, depth, &mut Seen::within(usize::MAX)) }
}

unsafe fn walked(canon: &mut Canon, value: *const ffi::zval, walk: Walk, depth: usize, seen: &mut Seen) -> Option<()> {
    if value.is_null() || depth > DEPTH {
        return None;
    }

    seen.spent()?;
    // SAFETY: a live value; its type tells which member of its union holds it.
    let (kind, held) = unsafe { ((*value).u1.v.type_, (*value).value) };
    canon.number(u64::from(kind));

    // SAFETY: each member is read only where the type says it holds the value.
    unsafe {
        match kind {
            UNDEF | NULL | FALSE | TRUE => Some(()),
            LONG => {
                canon.signed(held.lval);
                Some(())
            }
            DOUBLE => {
                canon.float(held.dval);
                Some(())
            }
            STRING => {
                canon.bytes(text(held.str_)?);
                Some(())
            }
            ARRAY => entries(canon, held.arr, walk, depth + 1, seen),
            CONSTANT_AST if walk == Walk::Literal => ast(canon, held.ast.add(1).cast::<ffi::zend_ast>(), depth + 1),
            OBJECT if walk == Walk::State => object(canon, held.obj, depth + 1, seen),
            REFERENCE if walk == Walk::State => walked(canon, &raw const (*held.ref_).val, walk, depth + 1, seen),
            INDIRECT if walk == Walk::State => walked(canon, held.zv, walk, depth + 1, seen),
            RESOURCE if walk == Walk::State => {
                canon.signed(i64::from((*held.res).type_));
                Some(())
            }
            _ => None,
        }
    }
}

unsafe fn entries(
    canon: &mut Canon,
    table: *const ffi::HashTable,
    walk: Walk,
    depth: usize,
    seen: &mut Seen,
) -> Option<()> {
    canon.section("table");

    if table.is_null() {
        canon.end(0);

        return Some(());
    }

    // SAFETY: a live table, packed or hashed as its flags say.
    let (used, packed) = unsafe { ((*table).nNumUsed, (*table).u.flags & PACKED != 0) };
    let mut count = 0;

    for at in 0..usize::try_from(used).ok()? {
        // SAFETY: a packed table holds `nNumUsed` values, a hashed one as many buckets.
        let (key, value) = unsafe {
            if packed {
                (None, (*table).__bindgen_anon_1.arPacked.add(at).cast_const())
            } else {
                let bucket = (*table).__bindgen_anon_1.arData.add(at);
                (Some(((*bucket).key, (*bucket).h)), &raw const (*bucket).val)
            }
        };

        // SAFETY: a live value.
        let kind = unsafe { (*value).u1.v.type_ };

        if kind == UNDEF || (kind == INDIRECT && walk == Walk::Literal) {
            continue;
        }

        match key {
            None => canon.number(at as u64),
            // SAFETY: a bucket's key is a live string, or none for an integer key.
            Some((name, hash)) => match unsafe { text(name) } {
                Some(name) => canon.bytes(name),
                None => canon.signed(hash.cast_signed()),
            },
        }

        // SAFETY: a live value of the table.
        unsafe { walked(canon, value, walk, depth, seen) }?;
        count += 1;
    }

    canon.end(count);
    Some(())
}

/// An object, by its class's name and each property it holds, once; again only by when it was first met.
unsafe fn object(canon: &mut Canon, object: *const ffi::zend_object, depth: usize, seen: &mut Seen) -> Option<()> {
    if object.is_null() {
        return None;
    }

    // SAFETY: a live object.
    let (handle, class, dynamic) = unsafe { ((*object).handle, (*object).ce, (*object).properties) };

    if !seen.objects.insert(handle) {
        canon.section("seen");
        canon.number(u64::from(handle));

        return Some(());
    }

    // SAFETY: a live object's class.
    canon.bytes(unsafe { text((*class).name) }.unwrap_or_default());
    // SAFETY: the class says how many declared properties the object's table holds.
    let declared = usize::try_from(unsafe { (*class).default_properties_count }).ok()?;

    for at in 0..declared {
        // SAFETY: the object's table holds `default_properties_count` values.
        unsafe { walked(canon, (*object).properties_table.as_ptr().add(at), Walk::State, depth, seen) }?;
    }

    // SAFETY: the object's own table of further properties, or none; its declared ones are indirect there.
    unsafe { entries(canon, dynamic, Walk::State, depth, seen) }
}

/// A constant expression, node by node: its kind, its attribute, then its children or its value.
unsafe fn ast(canon: &mut Canon, node: *const ffi::zend_ast, depth: usize) -> Option<()> {
    if depth > DEPTH {
        return None;
    }

    canon.section("ast");

    if node.is_null() {
        return Some(());
    }

    // SAFETY: a live node; its kind says its layout.
    let (kind, attribute) = unsafe { ((*node).kind, (*node).attr) };
    canon.number(u64::from(kind));
    canon.number(u64::from(attribute));

    if kind == AST_ZVAL || kind == AST_CONSTANT {
        // SAFETY: such a node holds a value right after its kind and attribute.
        return unsafe { of(canon, &raw const (*node.cast::<ValueNode>()).value, Walk::Literal, depth + 1) };
    }

    if kind & AST_LIST != 0 {
        // SAFETY: a list node counts its children.
        let list = unsafe { &*node.cast::<ListNode>() };

        for at in 0..usize::try_from(list.children).ok()? {
            // SAFETY: a list holds `children` nodes.
            unsafe { ast(canon, *list.child.as_ptr().add(at), depth + 1) }?;
        }

        return Some(());
    }

    if kind & AST_SPECIAL != 0 {
        return None;
    }

    for at in 0..usize::from(kind >> AST_CHILDREN_SHIFT) {
        // SAFETY: a plain node holds as many children as its kind counts.
        unsafe { ast(canon, *(*node).child.as_ptr().add(at), depth + 1) }?;
    }

    Some(())
}

/// `zend_ast_zval`: a node holding a value.
#[repr(C)]
struct ValueNode {
    kind: u16,
    attribute: u16,
    value: ffi::zval,
}

/// `zend_ast_list`: a node counting its children.
#[repr(C)]
struct ListNode {
    kind: u16,
    attribute: u16,
    line: u32,
    children: u32,
    child: [*mut ffi::zend_ast; 1],
}
