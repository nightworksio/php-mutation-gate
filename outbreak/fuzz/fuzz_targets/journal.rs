//! The daemon's journal and snapshot, as `outbreak serve` reads them back (ADR-0133): any bytes
//! are read without a panic, and every record a journal yields is written into a snapshot that
//! reads back to the same records.
#![no_main]

use libfuzzer_sys::fuzz_target;
use outbreak_serve::journal;

fuzz_target!(|bytes: &[u8]| {
    let records = journal::journal(bytes);

    assert_eq!(journal::snapshot_read(&journal::snapshot(&records)), Some(records));
    drop(journal::snapshot_read(bytes));
});
