<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function explode;

/**
 * A setting that turns one step of a run on or off, by the path it is written at; every one is on unless a layer
 * turns it off (ADR-0112, ADR-0142).
 */
enum Toggle: string
{
    /** Every kill a test is named for, and every kill no test is named for, runs its control (ADR-0014). */
    case ControlsKills = 'controls.kills';

    /** An out-of-memory mutant's tests run unmutated under the same cap (ADR-0004). */
    case ControlsMemory = 'controls.memory';

    /** A mutant Pest leaves uncovered on a line that is not executable is tried (ADR-0004). */
    case PestTrials = 'pest.trials';

    /** A declared value's lines are covered by the tests that read it (ADR-0037). */
    case PestValueReaders = 'pest.valueReaders';

    /** A patched mutant's own run loads only the test files its covering tests need. */
    case PestNarrow = 'pest.narrow';

    /** A survivor the analyser rejects counts as killed by static analysis (ADR-0020). */
    case StaticCheckAfter = 'staticCheck.after';

    /** A mutant whose tests cannot fit `timeouts.most` in one run is judged in parts (ADR-0008). */
    case TimeoutsParts = 'timeouts.parts';

    /** The verdict notes each loose value in force (ADR-0142). */
    case SuggestLooseValues = 'suggest.looseValues';

    /** A patched shard scans the codebase for the tests that read a value once, not once a chunk. */
    case PestShardScan = 'pest.shardScan';

    /** Commands a run starts side by side start the longest-limited first. */
    case PestLongestFirst = 'pest.longestFirst';

    /** A shard keeps what it read, listed, scanned and ran unmutated once, for every later run of it to take. */
    case PestRemember = 'pest.remember';

    /** PHPStan's checks load its turbo extension, where a build of it is shipped for this PHP. */
    case StaticCheckTurbo = 'staticCheck.turbo';

    /** The section it is written in. */
    public function section(): string
    {
        return explode('.', $this->value, 2)[0];
    }

    /** Its key within its section. */
    public function key(): string
    {
        return explode('.', $this->value, 2)[1];
    }

    /** Whether the Pest runner reads it among its options. */
    public function isPests(): bool
    {
        return match ($this) {
            self::PestTrials,
            self::PestValueReaders,
            self::PestNarrow,
            self::PestShardScan,
            self::PestLongestFirst,
            self::PestRemember => true,
            default => false,
        };
    }

    /** What it can change. */
    public function effect(): Effect
    {
        return $this === self::SuggestLooseValues || $this->speedsUp()
            ? Effect::JudgesOrReportsOnly
            : Effect::AffectsResults;
    }

    /** Whether it is a speed-up that changes no output, which every run may turn off where it misbehaves (ADR-0112). */
    public function speedsUp(): bool
    {
        return match ($this) {
            self::StaticCheckTurbo, self::PestShardScan, self::PestLongestFirst, self::PestRemember => true,
            default => false,
        };
    }

    /** The builder's call that sets it so. */
    public function php(bool $on): string
    {
        return match ($this) {
            self::ControlsKills => $on ? 'Controls::killsControlled()' : 'Controls::killsUncontrolled()',
            self::ControlsMemory => $on ? 'Controls::memoryControlled()' : 'Controls::memoryUncontrolled()',
            self::PestTrials => $on ? 'Pest::trying()' : 'Pest::withoutTrials()',
            self::PestValueReaders => $on ? 'Pest::readingValues()' : 'Pest::withoutValueReaders()',
            self::PestNarrow => $on ? 'Pest::narrowing()' : 'Pest::unnarrowed()',
            self::StaticCheckAfter => $on ? 'StaticCheck::checkingSurvivors()' : 'StaticCheck::survivorsUnchecked()',
            self::TimeoutsParts => $on ? 'Timeouts::inParts()' : 'Timeouts::neverInParts()',
            self::SuggestLooseValues => $on ? 'Suggest::looseValuesNoted()' : 'Suggest::looseValuesSilent()',
            self::StaticCheckTurbo => $on ? 'StaticCheck::turbo()' : 'StaticCheck::withoutTurbo()',
            self::PestShardScan => $on ? 'Pest::scanningOnceAShard()' : 'Pest::scanningEachChunk()',
            self::PestLongestFirst => $on ? 'Pest::longestFirst()' : 'Pest::inTheirOrder()',
            self::PestRemember => $on ? 'Pest::remembering()' : 'Pest::forgetting()',
        };
    }

    /**
     * Whether this value loosens what a run judges, given whether uncovered mutants are excluded rather than
     * counted (ADR-0142): `controls.kills` off always, `pest.trials` off where they are excluded.
     */
    public function loosens(bool $on, bool $uncoveredExcluded): bool
    {
        return ! $on && match ($this) {
            self::ControlsKills => true,
            self::PestTrials => $uncoveredExcluded,
            default => false,
        };
    }
}
