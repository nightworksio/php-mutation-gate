<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/** `flaky.killControls`: what a kill's unmutated control runs (ADR-0143). */
enum KillControl: string
{
    /** Its run again in its order, so a test that fails only after the tests before it kills nothing. */
    case Order = 'order';

    /** Its killers alone, which is quicker and gives up that proof. */
    case Alone = 'alone';

    /** The builder's call that sets it. */
    public function php(): string
    {
        return match ($this) {
            self::Order => 'Flaky::controllingKillsInOrder()',
            self::Alone => 'Flaky::controllingKillsAlone()',
        };
    }
}
