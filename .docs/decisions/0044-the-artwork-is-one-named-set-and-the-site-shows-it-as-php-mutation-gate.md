# ADR-0044: The artwork is one named set in `.docs/brand/`, and the site shows it as php-mutation-gate

**Status:** Accepted
**Date:** 2026-11-01

## Context

ADR-0038's item 3 makes the pixel-art logo the docs site's theme, names
it `.docs/brand/mutation-gate.svg` and the social card
`.docs/brand/mutation-gate-social.svg`, and draws every colour of the theme
from the logo's fills. What holds, read at source:

- **The logo carries the name beneath the gate.** At the size of a site's
  header or a browser tab, the lettering is unreadable, and the header
  already prints the title beside the logo.
- **`.docs/brand/` holds an older mark.** The MG monogram, `mark.svg`,
  `mark-light.svg` and `mark-dark.svg`, heads the README. Nothing else uses
  it.
- **The product's name is php-mutation-gate.** It is the repository's name
  and the name the artwork spells. The Composer package is
  `nightworksio/mutation-gate`, and the command is `mutation-gate`.
- **Starlight highlights code through Expressive Code,** whose themes have
  colours of their own, tuned for contrast in each scheme.

## Decision

This amends ADR-0038's item 3.

1. **The artwork is one set in `.docs/brand/`, each file named for
   php-mutation-gate and for its use.**

   | File | What it is | Where it is used |
   |------|------------|------------------|
   | `php-mutation-gate-logo.svg` | The logo: the gate, the PHP elephant in its arch, and the name beneath, 576 by 512 | The README's header, by its absolute `raw.githubusercontent.com` URL |
   | `php-mutation-gate-mark.svg` | The mark: the logo without the name, 576 by 456 | The site's logo |
   | `php-mutation-gate-favicon.svg`, `-favicon-32.png`, `-favicon-180.png`, `-favicon-512.png` | The mark padded to a square, as an SVG and at 32, 180 and 512 pixels | The site's icon, its touch icon, and app icons |
   | `php-mutation-gate-social.svg` | The social card, 1280 by 640 | The site's Open Graph image, rendered to PNG at build, and the repository's social preview |

   - The MG monogram leaves: one set, with no second mark beside it.
   - Every file is crisp-edged rects on an 8-unit grid. The mark keeps the
     logo's rects whole; the PNG files are rendered rect for rect, never
     resampled. `.docs/brand/README.md` lists each file and its use.

2. **Where a name is shown, it is php-mutation-gate:** the site's title,
   header and metadata, the README's heading, page titles, the artwork's
   file names and alt text, and prose that names the product. The Composer
   package, `nightworksio/mutation-gate`, and the command,
   `mutation-gate <command>`, keep their names.

3. **Code keeps its highlighter's colours.** Every other colour of the theme
   is one of the logo's fills, as ADR-0038 has it.

## Alternatives considered

| Option | Why it lost |
|--------|-------------|
| **The full logo as the site's logo and icon** | Its lettering is unreadable at the size of a header or a tab, and repeats the title printed beside it. |
| **Keeping the MG monogram beside the new set** | Two marks for one product, and the monogram matches none of the artwork's palette. |
| **mutation-gate as the display name** | The repository, the artwork and the site's URL say php-mutation-gate. |
| **Code recoloured to the logo's fills** | The palette has too few hues to tell a highlighter's token kinds apart at a readable contrast in both schemes. |

## Consequences

**The site's header and icons stay legible at any size,** and the README
shows the full logo.

**One name and one set of artwork,** each file saying what it is for.

## Related

- [ADR-0038](0038-the-documentation-is-a-versioned-site-built-from-docs-where-it-stands.md): the site and its theme, whose item 3 this amends
