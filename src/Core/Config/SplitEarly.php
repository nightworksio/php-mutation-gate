<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/**
 * `splitStream.early`: which full split-stream runs plan without waiting for
 * a fresh coverage map (ADR-0135).
 */
enum SplitEarly: string
{
    /** Where nothing could prove a unit anyway: under `unproven`, or where no proof shares the run's base. */
    case NoMatch = 'no-match';

    /** Every full split-stream run, taking no proof. */
    case Always = 'always';

    /** Only a run under `unproven`. */
    case UnprovenOnly = 'unproven-only';

    /** No run: every plan waits for a fresh map. */
    case Never = 'never';

    /** The builder's call that sets it. */
    public function php(): string
    {
        return match ($this) {
            self::NoMatch => 'EarlyStreams::whereNothingMatches()',
            self::Always => 'EarlyStreams::always()',
            self::UnprovenOnly => 'EarlyStreams::unprovenOnly()',
            self::Never => 'EarlyStreams::never()',
        };
    }

    /**
     * Whether a full run starts early: under `unproven`, or, under
     * `no-match`, where no proof the run reads shares its base.
     */
    public function startsEarly(bool $unproven, bool $anyMatch): bool
    {
        return match ($this) {
            self::Always => true,
            self::UnprovenOnly => $unproven,
            self::NoMatch => $unproven || ! $anyMatch,
            self::Never => false,
        };
    }
}
