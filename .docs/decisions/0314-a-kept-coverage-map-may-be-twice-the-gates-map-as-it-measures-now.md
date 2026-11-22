# ADR-0314: A kept coverage map may be twice the gate's map as it measures now

**Status:** Accepted
**Date:** 2026-11-21
**Amends:** [0023](0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md): the limits a kept coverage map is read and kept within

## Context

ADR-0023 keeps a coverage map only within two limits, each twice what the
gate's own suite's map measured then: 4,200,000 bytes packed and 85,000,000
unpacked. The suite has grown. Run 44911741946's map holds 16,913 tests and
18,987,623 covered line-test pairs, and measures 4,025,585 bytes packed and
100,564,003 unpacked, past the unpacked limit. Every verdict says "The
coverage map inflates to more than 85000000 bytes." and keeps no map, so
every next run measures every test again. That costs a full coverage run each
time and never changes a verdict.

## Decision

1. **The defaults are twice the map as it measures now**: `MapLimits::PACKED`
   is 8,100,000 bytes and `MapLimits::UNPACKED` is 201,200,000 bytes,
   which `proofs.limits.map.packed` and `proofs.limits.map.unpacked` still
   override.
2. A map past either is still neither read nor kept, as ADR-0023 says.

## Consequences

- The gate's own map is kept again, and a run reads it instead of measuring
  every test.
- Reading a kept map takes about 23 bytes of memory per decompressed byte
  (`HandoffLimits`), so a map at the new unpacked limit takes about 4.6 GB to
  read. The gate's map takes about 2.3 GB, as it does in every shard today.
- No verdict changes.
