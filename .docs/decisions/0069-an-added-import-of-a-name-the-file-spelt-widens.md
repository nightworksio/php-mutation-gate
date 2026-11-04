# ADR-0069: An added import of a name the file already spelt widens

**Status:** Accepted
**Date:** 2026-11-04
**Amends:** [0068](0068-an-added-import-reaches-nothing-and-support-is-wherever-the-tests-load-it.md): its rule 1, an import a change only added

## Context

ADR-0068 has an import a change only added reach nothing by itself, as the
tests that use the new name change with it. An import can also give a name
the file already spells a new meaning: `use Other\Foo;` changes what `Foo`
resolves to in a test whose declaration stays the same, and
`use function Other\sprintf;` changes every call of `sprintf()`. Those tests
were reached by nothing, and only the scheduled full run judged them again.

## Decision

1. **An added import widens the file where it gives a name the file spelt
   before.** Each clause of an import gives one name: its alias where it has
   one, or else the last segment of what it imports. The file widens to what
   `file` mode reaches, as ADR-0068's rule 2 has it, where the file as it was
   spells a name ending in that word, in any case, as PHP reads a class's or
   a function's name.
2. **An added import of a name the file did not spell reaches nothing by
   itself**, as ADR-0068's rule 1 had it.
3. **An import held more times than before counts as added.** Its name is in
   the file as it was, so it widens.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **An added import reaches nothing, as ADR-0068 had it** | A test that does not change can run other code after the change, and nothing reaches it until the scheduled full run. |
| **Every added import widens, as ADR-0065 had it** | An import of a new name changes no test that does not use it, and the tests that use it change with it. |
| **Read the name case for case** | A class's and a function's name read the same in any case. A constant's does not, so its import widens where it need not, which reaches more and never less. |
| **Look for the name in the file as it is** | The tests the change added or changed spell the new name, so every import that comes with a new test would widen. What the import changes the meaning of is what the file spelt before. |

## Consequences

- #517 and #499 reach what ADR-0068 measured: 727 of 850 units on 15 shards,
  and 1402 of 1472 on 20. None of their added imports gives a name the file
  spelt before.
- A `use function` of a function `RunCommandTest.php` of #517 calls widens it
  to 812 units on 16 shards, as `file` mode does.
- A name spelt only in a comment or a string does not widen, as neither
  resolves through an import.

## Related

- [ADR-0065](0065-a-changed-test-file-reaches-what-its-changed-tests-run.md): what a changed test file reaches
- [ADR-0068](0068-an-added-import-reaches-nothing-and-support-is-wherever-the-tests-load-it.md): an added import, and the widened file
