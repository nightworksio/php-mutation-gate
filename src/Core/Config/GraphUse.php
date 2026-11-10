<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/**
 * Where Outbreak's static graph acts (ADR-0132): `outbreak.graph`. Without
 * the binary, or with `outbreak.enabled` off, every value acts as `off`.
 */
enum GraphUse: string
{
    /** In `watch`'s quick rounds alone, after the map's covering tests. */
    case Local = 'local';

    /** Also in every run, where the reach rules reach a whole package only because the map cannot place a change. */
    case Narrow = 'narrow';

    /** Also in every run, where a changed source file reaches the units its joined tests run. */
    case Widen = 'widen';

    /** Nowhere. */
    case Off = 'off';

    /** The builder's call that sets it. */
    public function php(): string
    {
        return match ($this) {
            self::Local => 'Outbreak::graphInQuickRounds()',
            self::Narrow => 'Outbreak::graphNarrowing()',
            self::Widen => 'Outbreak::graphWidening()',
            self::Off => 'Outbreak::graphOff()',
        };
    }

    /** Whether it loosens what a run reaches (ADR-0142). */
    public function isLoose(): bool
    {
        return $this === self::Narrow;
    }

    /** Whether a quick round adds the tests the graph says reach an edited file. */
    public function addsToQuickRounds(): bool
    {
        return $this !== self::Off;
    }
}
