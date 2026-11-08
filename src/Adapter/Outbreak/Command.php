<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Outbreak;

use function explode;

/**
 * A command the `outbreak` binary runs (ADR-0118): `contract`, which says the
 * contract it speaks; `map vouch`, which vouches for a coverage map's text on
 * its input or says why not; and `map split`, which writes the slices a
 * request on its input asks for.
 *
 * @internal
 */
enum Command: string
{
    case Contract = 'contract';
    case Vouch = 'map vouch';
    case Split = 'map split';

    /** @return non-empty-list<string> the binary's arguments */
    public function words(): array
    {
        return explode(' ', $this->value);
    }
}
