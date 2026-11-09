<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Outbreak;

use function explode;

/**
 * A command the `outbreak` binary runs (ADR-0118, ADR-0119): `contract`, which
 * says the contract it speaks; `map vouch`, which vouches for a coverage map's
 * text on its input or says why not; `map split`, which writes the slices a
 * request on its input asks for; `report outline`, which answers the outline
 * of the map of the report a request names; and `report convert`, which
 * writes that map; `cache pull`, which pulls the packs of proofs a request
 * names from their store, and `cache push`, which merges a verdict's
 * entries into their class's pack (ADR-0131).
 *
 * @internal
 */
enum Command: string
{
    case Contract = 'contract';
    case Vouch = 'map vouch';
    case Split = 'map split';
    case Outline = 'report outline';
    case Convert = 'report convert';
    case Pull = 'cache pull';
    case Push = 'cache push';

    /** @return non-empty-list<string> the binary's arguments */
    public function words(): array
    {
        return explode(' ', $this->value);
    }
}
