//! A pack of proofs and a cache request, as the gate and the store hand them to `outbreak cache`: any
//! bytes are read without a panic, gzipped or not, and a pack read is written back within its limits.
#![no_main]

use libfuzzer_sys::fuzz_target;
use outbreak_cache::pack;
use outbreak_cache::request::{self, Limits};

const LIMITS: Limits = Limits {
    packed: 1 << 16,
    unpacked: 1 << 20,
};

fuzz_target!(|text: &[u8]| {
    if let Ok(read) = pack::parse(text) {
        drop(read.bytes(LIMITS));
    }

    drop(pack::read(text, LIMITS));
    drop(request::read(text));
});
