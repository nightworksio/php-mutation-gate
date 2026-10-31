# ADR-0045: Questions go to a Q&A category of Discussions

**Status:** Accepted
**Date:** 2026-11-01

## Context

ADR-0018's item 14 turns blank issues off, sends questions to the docs from
the issue form's chooser, and keeps Discussions off, because they would be
a second inbox for one maintainer. What holds, read at source:

- **A question has no place to be asked.** The chooser's *Ask a question*
  leads to the docs, which answer what they hold and nothing else, and blank
  issues are off.
- **Discussions are on,** with a Q&A category, at
  `https://github.com/nightworksio/php-mutation-gate/discussions/categories/q-a`.
  A Q&A discussion can mark the reply that answers it.
- **The docs are a site** (ADR-0038), with search, so a reader looks there
  first.

## Decision

This amends ADR-0018's item 14.

1. **Questions are asked in the Q&A category of Discussions.** The issue
   form's chooser sends *Ask a question* there, and says the docs hold the
   commands, the configuration, the CI setups and the troubleshooting
   guide.
2. **The README links the category** beside how to report a vulnerability
   and how to contribute.
3. **Issues stay for bugs and feature requests,** each through its form, and
   blank issues stay off.

## Alternatives considered

| Option | Why it lost |
|--------|-------------|
| **Questions to the docs alone, as ADR-0018 has it** | A question the docs do not answer has nowhere to go. |
| **Questions as issues** | Questions and bugs in one list, and an answered question stays an open issue until someone closes it. |
| **A chat server** | An inbox outside GitHub, whose answers no search of the repository finds. |

## Consequences

**A reader with a question has one place to ask it,** where an answer stays
findable and marked.

**The maintainer reads one more list,** Discussions' Q&A, beside issues and
pull requests.

## Related

- [ADR-0018](0018-the-documentation-is-versioned-and-tested-with-the-code.md): the issue forms and the chooser, whose item 14 this amends
- [ADR-0038](0038-the-documentation-is-a-versioned-site-built-from-docs-where-it-stands.md): the docs site the chooser points readers to first
