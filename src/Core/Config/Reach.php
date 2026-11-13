<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Analysis\Unchecked;
use NightWorksIO\MutationGate\Core\Composer\DecidingPackages;
use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Hold\HotPaths;

use function sprintf;

/**
 * What a change reaches (ADR-0005): `packages`, `reach.everything`, the
 * packages whose move in a lock decides how the gate runs,
 * `reach.lockPackages` (ADR-0030), what a changed test file reaches,
 * `reach.changedTests` (ADR-0065), the fixture projects whose files reach
 * only the tests that name them, `reach.fixtures` (ADR-0064), the
 * share of the suite past which code nothing holds is warned about,
 * `holds.hotPath`, whether a run given no mode considers every unit,
 * `run.full`, rather than what changed since its last passing commit,
 * what a pull request's shard does once it cannot pass, `run.failFast`,
 * which survivors static analysis left unchecked it waits on rather than
 * counting, `run.failFastWaits`,
 * which mutators a reached unit whose content is unchanged leaves out,
 * `pruning`, and how a sampled run sizes and draws its sample, `sampling`.
 */
final readonly class Reach implements Part
{
    /** The key that chooses the change source and the repository. */
    public const string VERSION_CONTROL = 'versionControl';

    /** The choices the builder's `VersionControl` writes by name. */
    private const array NAMED_CONTROLS = [BuiltinVersionControl::Git->value, BuiltinVersionControl::GitHub->value];

    /**
     * @param Listed<Glob>|Absent   $packages
     * @param Listed<Glob>|Absent   $everything
     * @param Listed<Glob>|Absent   $fixtures
     */
    private function __construct(
        private Listed|Absent $packages,
        private Listed|Absent $everything,
        private int|float|Absent $hotPath,
        private bool|Absent $full,
        private Pruning $pruning,
        private LockReach $lock,
        private Sampling $sampling,
        private FailingFast $failingFast,
        private ChangedTests|Absent $changedTests,
        private Listed|Absent $fixtures,
        private Choice|Absent $versionControl,
        private int|Absent $hotPathFewest,
        private HeldCoverageMode|Absent $heldCoverage,
        private DefaultBranchSince|Absent $defaultBranchSince,
    ) {
    }

    /**
     * @param Listed<Glob>|Absent   $packages
     * @param Listed<Glob>|Absent   $everything
     * @param Listed<string>|Absent $lockPackages
     * @param Listed<Glob>|Absent   $fixtures
     * @param Listed<Unchecked>|Absent $failFastWaits
     */
    public static function of(
        Listed|Absent $packages = new Absent(),
        Listed|Absent $everything = new Absent(),
        int|float|Absent $hotPath = new Absent(),
        bool|Absent $full = new Absent(),
        Pruning|Absent $pruning = new Absent(),
        Listed|Absent $lockPackages = new Absent(),
        Sampling|Absent $sampling = new Absent(),
        FailFast|Absent $failFast = new Absent(),
        ChangedTests|Absent $changedTests = new Absent(),
        Listed|Absent $fixtures = new Absent(),
        Listed|Absent $failFastWaits = new Absent(),
        Choice|Absent $versionControl = new Absent(),
        LockMoveReach|Absent $lockMoves = new Absent(),
        int|Absent $hotPathFewest = new Absent(),
        HeldCoverageMode|Absent $heldCoverage = new Absent(),
        DefaultBranchSince|Absent $defaultBranchSince = new Absent(),
    ): self {
        $set = $pruning instanceof Pruning ? $pruning : Pruning::of();
        $failing = FailingFast::of($failFast, $failFastWaits);
        $drawn = $sampling instanceof Sampling ? $sampling : Sampling::of();

        return new self(
            $packages,
            $everything,
            $hotPath,
            $full,
            $set,
            LockReach::of($lockPackages, $lockMoves),
            $drawn,
            $failing,
            $changedTests,
            $fixtures,
            $versionControl,
            $hotPathFewest,
            $heldCoverage,
            $defaultBranchSince,
        );
    }

    public static function none(): self
    {
        return self::of();
    }

    public static function standard(): self
    {
        $none = self::none();

        $pruning = $none->pruning();
        $sampling = $none->sampling();

        return self::of(
            $none->packages(),
            $none->everything(),
            $none->hotPaths()->share(),
            $none->isFullByDefault(),
            Pruning::of($pruning->enabled(), $pruning->window()->mutants(), $pruning->audit()),
            sampling: Sampling::of(
                $sampling->confidence(),
                $sampling->margin(),
                $sampling->minUnits(),
                auto: $sampling->auto(),
                autoRuns: $sampling->autoRuns(),
            ),
            failFast: $none->failingFast()->mode(),
            changedTests: $none->changedTests(),
            fixtures: $none->fixtures(),
            failFastWaits: $none->failingFast()->waits(),
            lockMoves: LockMoveReach::Everything,
            hotPathFewest: HotPaths::SMALLEST_SUITE,
            heldCoverage: $none->heldCoverage(),
            defaultBranchSince: $none->defaultBranchSince(),
        );
    }

    public function over(Part $later): self
    {
        return $later instanceof self
            ? new self(
                $this->joined($this->packages, $later->packages),
                $this->joined($this->everything, $later->everything),
                Absent::laid($this->hotPath, $later->hotPath),
                Absent::laid($this->full, $later->full),
                $this->pruning->over($later->pruning),
                $this->lock->over($later->lock),
                $this->sampling->over($later->sampling),
                $this->failingFast->over($later->failingFast),
                Absent::laid($this->changedTests, $later->changedTests),
                $this->joined($this->fixtures, $later->fixtures),
                Absent::laid($this->versionControl, $later->versionControl),
                Absent::laid($this->hotPathFewest, $later->hotPathFewest),
                Absent::laid($this->heldCoverage, $later->heldCoverage),
                Absent::laid($this->defaultBranchSince, $later->defaultBranchSince),
            )
            : $this;
    }

    /** `holds.coverage`: how a shard learns what a held unit's holding tests cover; `measure` unless a layer says. */
    public function heldCoverage(): HeldCoverageMode
    {
        return $this->heldCoverage instanceof HeldCoverageMode ? $this->heldCoverage : HeldCoverageMode::Measure;
    }

    /** `reach.defaultBranchSince`: what the default branch's changes are read since; `judged` unless a layer says. */
    public function defaultBranchSince(): DefaultBranchSince
    {
        return $this->defaultBranchSince instanceof DefaultBranchSince
            ? $this->defaultBranchSince
            : DefaultBranchSince::Judged;
    }

    /** @return Listed<Glob> the globs of the packages in a monorepo */
    public function packages(): Listed
    {
        return $this->packages instanceof Listed ? $this->packages : Listed::of();
    }

    /** @return Listed<Glob> the globs of the files that reach everything */
    public function everything(): Listed
    {
        return $this->everything instanceof Listed ? $this->everything : Listed::of();
    }

    /** @return Listed<Glob> the globs of the fixture projects, whose files reach only the tests that name them */
    public function fixtures(): Listed
    {
        return $this->fixtures instanceof Listed ? $this->fixtures : Listed::of();
    }

    /** `reach.lockMoves`: what a package's move in a `composer.lock` reaches (ADR-0115). */
    public function lockMoves(): LockMoveReach
    {
        return $this->lock->moves();
    }

    /** What a package's move in a `composer.lock` decides, as `reach.lockMoves` and `reach.lockPackages` say. */
    public function lockPackages(): DecidingPackages
    {
        return $this->lock->deciding();
    }

    /**
     * What a changed test file reaches: the units its changed tests run, read
     * by their ids, unless `reach.changedTests` asks for every unit its
     * tests run (ADR-0065).
     */
    public function changedTests(): ChangedTests
    {
        return $this->changedTests instanceof ChangedTests ? $this->changedTests : ChangedTests::Narrow;
    }

    /** The files most of the suite runs through that nothing holds, past `holds.hotPath` of it. */
    public function hotPaths(): HotPaths
    {
        return HotPaths::atShareOfAtLeast(
            $this->hotPath instanceof Absent ? HotPaths::STANDARD : $this->hotPath,
            $this->hotPathFewest instanceof Absent ? HotPaths::SMALLEST_SUITE : $this->hotPathFewest,
        );
    }

    /**
     * Whether a run given neither `--full` nor `--changed-since` considers every unit; otherwise it considers what
     * changed since its scope's last passing commit, `last-passed` (ADR-0005).
     */
    public function isFullByDefault(): bool
    {
        return $this->full === true;
    }

    /** `versionControl`: where a run learns what changed and what the repository holds, where a layer chooses it. */
    public function versionControl(): Choice|Absent
    {
        return $this->versionControl;
    }

    /** `run.failFast` and `run.failFastWaits`: what a pull request's shard does once it cannot pass (ADR-0103). */
    public function failingFast(): FailingFast
    {
        return $this->failingFast;
    }

    /** `pruning`: which mutators a reached unit whose content is unchanged leaves out (ADR-0025). */
    public function pruning(): Pruning
    {
        return $this->pruning;
    }

    /** `sampling`: how a sampled run sizes and draws its sample (ADR-0020). */
    public function sampling(): Sampling
    {
        return $this->sampling;
    }

    public function written(PathOrigin $origin): Json
    {
        return Json::object(
            Member::of(
                'packages',
                $this->packages instanceof Listed
                    ? Json::items(...WrittenPaths::globs($origin, $this->packages))
                    : $this->packages,
            ),
            Member::unlessEmpty(
                'reach',
                Json::object(
                    Member::of(
                        'everything',
                        $this->everything instanceof Listed
                            ? Json::items(...WrittenPaths::globs($origin, $this->everything))
                            : $this->everything,
                    ),
                    Member::of(
                        'changedTests',
                        $this->changedTests instanceof ChangedTests ? $this->changedTests->value : $this->changedTests,
                    ),
                    Member::of(
                        'fixtures',
                        $this->fixtures instanceof Listed
                            ? Json::items(...WrittenPaths::globs($origin, $this->fixtures))
                            : $this->fixtures,
                    ),
                    Member::of(
                        'defaultBranchSince',
                        $this->defaultBranchSince instanceof DefaultBranchSince
                            ? $this->defaultBranchSince->value
                            : $this->defaultBranchSince,
                    ),
                    ...$this->lock->members(),
                ),
            ),
            Member::unlessEmpty(
                'holds',
                Json::object(
                    Member::of('hotPath', $this->hotPath),
                    Member::of('fewestTests', $this->hotPathFewest),
                    Member::of(
                        'coverage',
                        $this->heldCoverage instanceof HeldCoverageMode
                            ? $this->heldCoverage->value
                            : $this->heldCoverage,
                    ),
                ),
            ),
            Member::unlessEmpty(
                'run',
                Json::object(Member::of('full', $this->full))->merged($this->failingFast->written()),
            ),
            Member::of(
                self::VERSION_CONTROL,
                $this->versionControl instanceof Choice ? $this->versionControl->written() : $this->versionControl,
            ),
            $this->pruning->written(),
            $this->sampling->written(),
        );
    }

    public function php(PathOrigin $origin): PhpCalls
    {
        $packages = PhpCalls::globs('Reach::packages', $this->packages, $origin);
        $everything = PhpCalls::globs('Reach::everything', $this->everything, $origin);
        $changedTests = $this->changedTests instanceof ChangedTests ? [$this->changedTests->php()] : [];
        $fixtures = PhpCalls::globs('Reach::fixtures', $this->fixtures, $origin);
        $hotPath = $this->hotPath instanceof Absent
            ? []
            : [sprintf('Reach::hotPath(%s)', PhpCalls::literal($this->hotPath))];

        $full = PhpCalls::flag($this->full, 'Reach::fullByDefault()', 'Reach::changedByDefault()');
        $lock = $this->lock->php();
        $calls = PhpCalls::inWith(
            ...$packages,
            ...$everything,
            ...$lock,
            ...$changedTests,
            ...$fixtures,
            ...$hotPath,
            ...$this->hotPathFewest instanceof Absent
                ? []
                : [sprintf('Reach::hotPathFewestTests(%d)', $this->hotPathFewest)],
            ...$full,
            ...$this->heldCoverage instanceof HeldCoverageMode ? [$this->heldCoverage->php()] : [],
            ...$this->defaultBranchSince instanceof DefaultBranchSince ? [$this->defaultBranchSince->php()] : [],
            ...$this->versionControl instanceof Choice
                ? [PhpCalls::chosen($this->versionControl, AdapterBuilder::VersionControl, ...self::NAMED_CONTROLS)]
                : [],
        );

        return $calls->and($this->failingFast->php())->and($this->pruning->php())->and($this->sampling->php());
    }

    /**
     * @param  Listed<Glob>|Absent $earlier
     * @param  Listed<Glob>|Absent $later
     * @return Listed<Glob>|Absent
     */
    private function joined(Listed|Absent $earlier, Listed|Absent $later): Listed|Absent
    {
        return match (true) {
            $later instanceof Absent => $earlier,
            $earlier instanceof Absent => $later,
            default => $earlier->and($later, static fn(Glob $glob): string => $glob->value()),
        };
    }
}
