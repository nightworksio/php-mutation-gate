# ADR-0261: A process digests the gate's code once while it stands

**Status:** Accepted
**Date:** 2026-11-19
**Amends:** [0084](0084-the-gate-is-keyed-by-the-code-a-verdict-depends-on.md): how item 2 of the key is read

## Context

Item 2 of a proof's key is the digest of the gate's verdict code (ADR-0084):
every file of the sets the run's runner reads, by its path and its contents.
For Pest that is 1,319 files and 4.2 MB of this package's own source. Each
reading lists them, matches them against the sets' globs, and reads and
hashes every one: 0.1 to 0.2 s on a developer's machine.

A plan reads it two or three times: for the coverage entries' base, for the
units' keys, and, where a split-stream run may start early, for the reads
digest. `watch` reads it again every round. In this package's own suite,
every test of a command that plans pays it: `Planning::plan` was 9.1 s of
`WatchCommandTest`'s 16 s, and all of that was these readings.

The code does not change while a run reads it, except where the gate's own
source is the project, as in this package's `watch`: a round after an edit
must key by the code as it now stands.

## Decision

1. **A process keeps the digest of each runner's verdict code where it is
   installed**, beside what each file's status said when it was read: its
   device, inode, size, and the seconds it was last written and changed.
2. **Each reading looks at every file's status again before it reads any.**
   The kept digest is used only where the same files are there, each with the
   same status. A file added, removed or written moves its status, so the code
   is read and hashed again.
3. **Nothing is kept where a file was written or changed in the second it
   was looked at, or later.** Status times count whole seconds, so a second
   write in that second, of the same size, could leave its status as it was.
   A file's change time is set by the system on every write and cannot be set
   back, so a write after the kept reading always moves it. This is the rule
   git keeps its index by.
4. **A reading lists the files with `scandir`, and matches each file only
   against the globs of the base it was found under.** A file a glob matches
   is under the glob's base, so the files found are the same. A kept reading
   then costs the listing and one status per file: 11 ms for Pest's 1,319
   files, where every reading took 144 ms.
5. **The digest itself does not change**: the same files, by the same names
   and contents, digest to the same value as before, for every built-in
   runner and an extension's, so no key moves.

## Alternatives considered

| Option | Why it lost |
|--------|-------------|
| **Reading it once per plan and handing it on** | Saves one or two of each plan's readings, but every plan and every `watch` round still pays one. |
| **A port for it, so tests pass a fixed digest** | Only the tests get faster, and every adapters value grows a member that only the key reads. |
| **Keeping it for the life of the process** | Unsound in `watch` on the gate itself: a round after an edit would take proofs keyed by code that no longer stands. |
| **Comparing modification times alone** | A write within the same second as the reading, or a tool that sets the time back, would leave the digest stale. |
