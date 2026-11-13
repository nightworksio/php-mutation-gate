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

    /** An invocation's controls run beside its survivors' runs again, each in a share of the pool. */
    case PestOverlapSteps = 'pest.overlapSteps';

    /** A mutation run starts with pcov off, unless its streams record lines with pcov (ADR-0147). */
    case PestPcovOff = 'pest.pcovOff';

    /** A mutation run starts with Xdebug's mode off (ADR-0147). */
    case PestXdebugOff = 'pest.xdebugOff';

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
            self::PestRemember,
            self::PestOverlapSteps,
            self::PestPcovOff,
            self::PestXdebugOff => true,
            self::ControlsKills,
            self::ControlsMemory,
            self::StaticCheckAfter,
            self::TimeoutsParts,
            self::SuggestLooseValues,
            self::StaticCheckTurbo => false,
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
            self::StaticCheckTurbo,
            self::PestShardScan,
            self::PestLongestFirst,
            self::PestRemember,
            self::PestOverlapSteps,
            self::PestPcovOff,
            self::PestXdebugOff => true,
            self::ControlsKills,
            self::ControlsMemory,
            self::PestTrials,
            self::PestValueReaders,
            self::PestNarrow,
            self::StaticCheckAfter,
            self::TimeoutsParts,
            self::SuggestLooseValues => false,
        };
    }

    /** The builder's call that sets it so. */
    public function php(bool $on): string
    {
        return $on ? $this->onCall() : $this->offCall();
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
            self::ControlsMemory,
            self::PestValueReaders,
            self::PestNarrow,
            self::StaticCheckAfter,
            self::TimeoutsParts,
            self::SuggestLooseValues,
            self::PestShardScan,
            self::PestLongestFirst,
            self::PestRemember,
            self::PestOverlapSteps,
            self::PestPcovOff,
            self::PestXdebugOff,
            self::StaticCheckTurbo => false,
        };
    }

    /** The builder's call that turns it on. */
    private function onCall(): string
    {
        return match ($this) {
            self::ControlsKills => 'Controls::killsControlled()',
            self::ControlsMemory => 'Controls::memoryControlled()',
            self::PestTrials => 'Pest::trying()',
            self::PestValueReaders => 'Pest::readingValues()',
            self::PestNarrow => 'Pest::narrowing()',
            self::StaticCheckAfter => 'CheckTiming::checkingSurvivors()',
            self::TimeoutsParts => 'Timeouts::inParts()',
            self::SuggestLooseValues => 'Suggest::looseValuesNoted()',
            self::StaticCheckTurbo => 'StaticCheck::turbo()',
            self::PestShardScan => 'PestSpeedUps::scanningOnceAShard()',
            self::PestLongestFirst => 'PestSpeedUps::longestFirst()',
            self::PestRemember => 'PestSpeedUps::remembering()',
            self::PestOverlapSteps => 'PestSpeedUps::overlappingSteps()',
            self::PestPcovOff => 'PestSpeedUps::pcovOff()',
            self::PestXdebugOff => 'PestSpeedUps::xdebugOff()',
        };
    }

    /** The builder's call that turns it off. */
    private function offCall(): string
    {
        return match ($this) {
            self::ControlsKills => 'Controls::killsUncontrolled()',
            self::ControlsMemory => 'Controls::memoryUncontrolled()',
            self::PestTrials => 'Pest::withoutTrials()',
            self::PestValueReaders => 'Pest::withoutValueReaders()',
            self::PestNarrow => 'Pest::unnarrowed()',
            self::StaticCheckAfter => 'CheckTiming::survivorsUnchecked()',
            self::TimeoutsParts => 'Timeouts::neverInParts()',
            self::SuggestLooseValues => 'Suggest::looseValuesSilent()',
            self::StaticCheckTurbo => 'StaticCheck::withoutTurbo()',
            self::PestShardScan => 'PestSpeedUps::scanningEachChunk()',
            self::PestLongestFirst => 'PestSpeedUps::inTheirOrder()',
            self::PestRemember => 'PestSpeedUps::forgetting()',
            self::PestOverlapSteps => 'PestSpeedUps::stepByStep()',
            self::PestPcovOff => 'PestSpeedUps::pcovOn()',
            self::PestXdebugOff => 'PestSpeedUps::xdebugAsConfigured()',
        };
    }
}
