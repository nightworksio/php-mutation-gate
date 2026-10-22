<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function array_map;

use NightWorksIO\MutationGate\Core\Composer\DecidingPackages;
use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Hold\HotPaths;

use function sprintf;

/**
 * What a change reaches (ADR-0005): `packages`, `reach.everything`, the
 * packages whose move in a lock decides how the gate runs,
 * `reach.lockPackages` (ADR-0030), the
 * share of the suite past which code nothing holds is warned about,
 * `holds.hotPath`, whether a run given no mode considers every unit,
 * `run.full`, rather than what changed since its last passing commit, and
 * which mutators a reached unit whose content is unchanged leaves out,
 * `pruning`, and how a sampled run sizes and draws its sample, `sampling`.
 */
final readonly class Reach implements Part
{
    /**
     * @param Listed<Glob>|Absent   $packages
     * @param Listed<Glob>|Absent   $everything
     * @param Listed<string>|Absent $lockPackages
     */
    private function __construct(
        private Listed|Absent $packages,
        private Listed|Absent $everything,
        private int|float|Absent $hotPath,
        private bool|Absent $full,
        private Pruning $pruning,
        private Listed|Absent $lockPackages,
        private Sampling $sampling,
    ) {
    }

    /**
     * @param Listed<Glob>|Absent   $packages
     * @param Listed<Glob>|Absent   $everything
     * @param Listed<string>|Absent $lockPackages
     */
    public static function of(
        Listed|Absent $packages = new Absent(),
        Listed|Absent $everything = new Absent(),
        int|float|Absent $hotPath = new Absent(),
        bool|Absent $full = new Absent(),
        Pruning|Absent $pruning = new Absent(),
        Listed|Absent $lockPackages = new Absent(),
        Sampling|Absent $sampling = new Absent(),
    ): self {
        $set = $pruning instanceof Pruning ? $pruning : Pruning::of();
        $drawn = $sampling instanceof Sampling ? $sampling : Sampling::of();

        return new self($packages, $everything, $hotPath, $full, $set, $lockPackages, $drawn);
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
            sampling: Sampling::of($sampling->confidence(), $sampling->margin(), $sampling->minUnits()),
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
                $this->joinedNames($this->lockPackages, $later->lockPackages),
                $this->sampling->over($later->sampling),
            )
            : $this;
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

    /**
     * The packages whose move in a `composer.lock` decides how the gate runs:
     * those `reach.lockPackages` names, every package where it names none.
     */
    public function lockPackages(): DecidingPackages
    {
        return $this->lockPackages instanceof Listed
            ? DecidingPackages::named(...array_map(Glob::of(...), [...$this->lockPackages]))
            : DecidingPackages::every();
    }

    /** The files most of the suite runs through that nothing holds, past `holds.hotPath` of it. */
    public function hotPaths(): HotPaths
    {
        return $this->hotPath instanceof Absent ? HotPaths::standard() : HotPaths::atShare($this->hotPath);
    }

    /**
     * Whether a run given neither `--full` nor `--changed-since` considers every unit; otherwise it considers what
     * changed since its scope's last passing commit, `last-passed` (ADR-0005, decision 2).
     */
    public function isFullByDefault(): bool
    {
        return $this->full === true;
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
                        'lockPackages',
                        $this->lockPackages instanceof Listed
                            ? Json::items(...$this->lockPackages)
                            : $this->lockPackages,
                    ),
                ),
            ),
            Member::unlessEmpty('holds', Json::object(Member::of('hotPath', $this->hotPath))),
            Member::unlessEmpty('run', Json::object(Member::of('full', $this->full))),
            $this->pruning->written(),
            $this->sampling->written(),
        );
    }

    public function php(PathOrigin $origin): PhpCalls
    {
        $packages = $this->packages instanceof Listed
            ? [sprintf('Reach::packages(%s)', PhpCalls::literals(...WrittenPaths::globs($origin, $this->packages)))]
            : [];
        $everything = $this->everything instanceof Listed
            ? [sprintf(
                'Reach::everything(%s)',
                PhpCalls::literals(...WrittenPaths::globs($origin, $this->everything)),
            )]
            : [];
        $lockPackages = $this->lockPackages instanceof Listed
            ? [sprintf('Reach::lockPackages(%s)', PhpCalls::literals(...$this->lockPackages))]
            : [];
        $hotPath = $this->hotPath instanceof Absent
            ? []
            : [sprintf('Reach::hotPath(%s)', PhpCalls::literal($this->hotPath))];

        $full = match ($this->full) {
            true => ['Reach::fullByDefault()'],
            false => ['Reach::changedByDefault()'],
            default => [],
        };

        $calls = PhpCalls::inWith(...$packages, ...$everything, ...$lockPackages, ...$hotPath, ...$full);

        return $calls->and($this->pruning->php())->and($this->sampling->php());
    }

    /**
     * @param  Listed<string>|Absent $earlier
     * @param  Listed<string>|Absent $later
     * @return Listed<string>|Absent
     */
    private function joinedNames(Listed|Absent $earlier, Listed|Absent $later): Listed|Absent
    {
        return match (true) {
            $later instanceof Absent => $earlier,
            $earlier instanceof Absent => $later,
            default => $earlier->and($later, static fn(string $name): string => $name),
        };
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
