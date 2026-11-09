//! A coverage map's text, as the gate hands `outbreak map vouch` one: any bytes are read without a
//! panic, and a vouched map's places name only the tests it lists.
#![no_main]

use libfuzzer_sys::fuzz_target;
use outbreak_map::map;

fuzz_target!(|text: &[u8]| {
    if let Ok(shape) = map::read(text) {
        assert!(shape.tests.end <= text.len());
        for entry in shape.files.iter().chain(&shape.methods) {
            assert!(entry.key.end <= entry.value.start && entry.value.end <= text.len());
        }
    }
});
