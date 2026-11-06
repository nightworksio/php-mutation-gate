# ADR-0101: Rules find a concrete way to kill each survivor

**Status:** Accepted
**Date:** 2026-11-05
**Amends:** [0015](0015-a-survivor-reaches-the-editor-the-test-file-and-the-commit.md): what a stub holds; [0009](0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md): what the JSON report, the HTML report and the pull request comment say of a survivor

## Context

A survivor's hint says what its tests miss in one sentence for its
mutator's family, and `stub` writes a failing test whose scaffold calls
the function around the mutant with its parameters' names as
placeholders. Both stop short of the values that would kill it. For many
survivors those values are in the diff itself: the number a boundary
compares with, the value an equality tests for, a constant's declared
value (ADR-0037). A person still has to read them out and pick them.

## Decision

1. **Rules build an attempt for a survivor from its diff, the function
   around it and the values its file declares.** No model is involved;
   each rule reads tokens and patterns.
   - **Boundary:** a comparison between a variable and an integer, written
     out or a class constant its file declares with one, is tried at that
     value, then one step past it on the side the mutant does not flip:
     `$amount < 10` at 10, then 9.
   - **Equality:** a variable compared with a literal is tried at the
     literal, then at any other value.
   - **Arithmetic:** each variable operand is tried at 3, a second at 5, so
     no operand is 0, 1 or the other.
   - **Logical:** two variables are tried with exactly one of them true,
     then the other way round.
   - **A changed class constant or backed case** is asserted by its name:
     `Cart::LIMIT` is `10`.
   - **A returned literal** is expected from the call to the function.
   - **A removed call on `$this->property` or a variable** is noticed by a
     test double that expects it.
   Where no rule reads a value, there is no attempt, and every report keeps
   what it said before.

2. **An attempt calls the function where its variables are the function's
   parameters,** and otherwise says to use the values: `call fits() with
   $amount at 10, then at 9`, or `use $total at 5, then at 6`.

3. **Where it shows.**
   - The hint of a survivor holds its attempt. The JSON report gives it as
     the mutant's optional `try`.
   - The pull request comment shows `Try: …` under each survivor's hint,
     and so do the HTML report's survivors and its pane.
   - `stub` writes `Try: …` under the hint, and its scaffold calls the
     function with the values tried in place of the parameters they name.

## Alternatives considered

- **A model that writes the test.** The gate's bots and reports are rules
  and static analysis; a model's output cannot be reproduced or reviewed
  as a rule can.
- **Only boundaries and literals.** Equality, arithmetic, logical and
  removed-call survivors are as common, and their values are as readable.
- **A command of its own.** `stub` already writes the test a survivor
  needs; the values belong in it and beside the hint a reviewer reads.

## Consequences

**Many survivors come with the values that kill them.** A reader copies a
call instead of working one out.

**An attempt is a start, not a proof.** A rule reads only the changed
line, its function and its file's constants, so the expected result is
still the person's to write.

## Related

- [ADR-0015](0015-a-survivor-reaches-the-editor-the-test-file-and-the-commit.md): `stub`
- [ADR-0037](0037-a-value-is-covered-by-the-tests-that-read-it.md): declared values
- [ADR-0009](0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md): the reports
