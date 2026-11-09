//! A split request, as the gate writes one on `outbreak map split`'s input: any bytes are read
//! without a panic.
#![no_main]

use libfuzzer_sys::fuzz_target;
use outbreak_map::request;

fuzz_target!(|text: &[u8]| {
    drop(request::read(text));
});
