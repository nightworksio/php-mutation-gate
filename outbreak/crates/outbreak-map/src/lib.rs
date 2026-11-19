//! The coverage map work Outbreak takes off the gate's PHP (ADR-0118): vouching
//! that a map's text holds nothing the gate's careful reading would drop, so the
//! gate builds it straight from its decoded text, and slicing a map the gate
//! wrote into the maps of some of its files, byte for byte as the gate writes
//! them. Every byte it reads it is handed, and it writes none.

pub mod json;
pub mod map;
pub mod request;
pub mod slice;
