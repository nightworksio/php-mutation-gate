# ADR-0116: An empty holding list is allowed, and noted

**Status:** Accepted
**Date:** 2026-11-07
**Amends:** [0002](0002-one-typed-config-from-several-formats.md): `tests.holding` takes an empty list; [0104](0104-suggestions-can-be-turned-off-and-each-ci-has-a-context.md): a suggestion that can be turned off by itself

## Context

`tests.holding` names the suites whose tests judge only the units they hold
with `#[Holds]` or a `holds:` group. Written, it had to name at least one, so
a config could only say "no holding suite" by leaving the key out. A layer
cannot leave out what a layer under it sets, so a context block or a later
layer had no way to take the holding suites away again.

An empty list also costs something a reader may not see. Where `tests.suites`
names the suites that judge, the tests of every other suite judge no mutant,
not even of the code they hold. A team that empties the list by mistake loses
those judges without a word.

## Decision

1. **`tests.holding` takes an empty list.** It holds none, as leaving the key
   out does: the suites `tests.suites` names judge every unit, and no other
   suite judges any. The PHP builder writes it as `Tests::holding()`.
2. **The verdict notes what it means**, where `tests.suites` names the
   suites that judge: the other suites' tests judge no mutant, not even of the
   code they hold, and how to name them or keep it so. It is a note
   (ADR-0111), so it never blocks.
3. **`suggest.emptyHolding` turns that note off**, on by default, and
   `suggest.enabled` off turns it off with every other suggestion.

## Alternatives considered

- **Refusing an empty list, as before.** A later layer could still not take
  the holding suites away.
- **A warning.** An empty list can be what a team wants; a warning asks for
  action, and a release is tagged only when the gate prints none.
- **A `config:suggest` proposal.** It changes nothing in the config, and
  `config:suggest` is run by hand; the verdict is where a team sees it.

## Consequences

- A context block can turn holding suites off, as `contexts.local.tests.holding: []`.
- A team that empties the list on purpose silences the note with one key.

## Related

- [ADR-0002](0002-one-typed-config-from-several-formats.md): `tests.suites` and `tests.holding`
- [ADR-0104](0104-suggestions-can-be-turned-off-and-each-ci-has-a-context.md): `suggest.enabled`
- [ADR-0111](0111-warnings-ask-for-action-and-notes-never-block.md): notes never block
