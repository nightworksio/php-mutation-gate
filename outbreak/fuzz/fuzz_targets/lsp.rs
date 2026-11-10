//! The language server's reading of what the editor and `watch` send it (ADR-0133 decision 10):
//! any bytes are read as frames, messages, events and file URIs without a panic, and every
//! event read is held and shown as diagnostics that are JSON.
#![no_main]

use libfuzzer_sys::fuzz_target;
use outbreak_lsp::survivors::{Event, Severity, Survivors};
use outbreak_lsp::{frame, message, uri};

fuzz_target!(|bytes: &[u8]| {
    let mut rest = bytes;

    while let Ok(Some(content)) = frame::read(&mut rest) {
        drop(message::read(&content));
    }

    if let Some(event) = Event::read(bytes) {
        let mut survivors = Survivors::default();
        let unit = event.unit.clone();
        survivors.take(event);

        assert!(message::items(survivors.diagnostics(&unit, Severity::Warning).as_bytes()).is_some());
    }

    drop(uri::path(&String::from_utf8_lossy(bytes)));
});
