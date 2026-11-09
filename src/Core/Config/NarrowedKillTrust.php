<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/** `pest.narrowedKills`: what a kill in a narrowed run needs before it counts (ADR-0036, ADR-0089, ADR-0142). */
enum NarrowedKillTrust: string
{
    /** Its replay, its alone run, or its run again with every test file. */
    case Vouch = 'vouch';

    /** Nothing more: it counts as the narrowed run found it, which loosens. */
    case Trust = 'trust';

    /** The builder's call that sets it. */
    public function php(): string
    {
        return match ($this) {
            self::Vouch => 'Pest::vouchingForNarrowedKills()',
            self::Trust => 'Pest::trustingNarrowedKills()',
        };
    }
}
