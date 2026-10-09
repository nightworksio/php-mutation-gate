<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Hold\HotPaths;

use function sprintf;

/**
 * What a change reaches (ADR-0005): `packages`, `reach.everything`, the
 * share of the suite past which code nothing holds is warned about,
 * `holds.hotPath`, whether a run given no mode considers every unit,
 * `run.full`, rather than what changed since its last passing commit, and
 * which mutators a reached unit whose content is unchanged leaves out,
 * `pruning`.
 */
final readonly class Reach implements Part
{
    /**
     * @param Listed<Glob>|Absent $packages
     * @param Listed<Glob>|Absent $everything
     */
    private function __construct(
        private Listed|Absent $packages,
        private Listed|Absent $everything,
        private int|float|Absent $hotPath,
        private bool|Absent $full,
        private Pruning|Absent $pruning,
    ) {
    }

    /**
     * @param Listed<Glob>|Absent $packages
     * @param Listed<Glob>|Absent $everything
     */
    public static function of(
        Listed|Absent $packages = new Absent(),
        Listed|Absent $everything = new Absent(),
        int|float|Absent $hotPath = new Absent(),
        bool|Absent $full = new Absent(),
        Pruning|Absent $pruning = new Absent(),
    ): self {
        return new self($packages, $everything, $hotPath, $full, $pruning);
    }

    public static function none(): self
    {
        return self::of();
    }

    public static function standard(): self
    {
        $none = self::none();

        $pruning = $none->pruning();

        return self::of(
            $none->packages(),
            $none->everything(),
            $none->hotPaths()->share(),
            $none->isFullByDefault(),
            Pruning::of($pruning->enabled(), $pruning->window()->mutants(), $pruning->audit()),
        );
    }

    public function over(Part $later): self
    {
        return $later instanceof self
            ? new self(
                $this->joined($this->packages, $later->packages),
                $this->joined($this->everything, $later->everything),
                $later->hotPath instanceof Absent ? $this->hotPath : $later->hotPath,
                $later->full instanceof Absent ? $this->full : $later->full,
                $this->laidPruning($later->pruning),
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
        return $this->pruning instanceof Pruning ? $this->pruning : Pruning::of();
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
                Json::object(Member::of(
                    'everything',
                    $this->everything instanceof Listed
                        ? Json::items(...WrittenPaths::globs($origin, $this->everything))
                        : $this->everything,
                )),
            ),
            Member::unlessEmpty('holds', Json::object(Member::of('hotPath', $this->hotPath))),
            Member::unlessEmpty('run', Json::object(Member::of('full', $this->full))),
            $this->pruning instanceof Pruning ? $this->pruning->written() : Member::of('pruning', $this->pruning),
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
        $hotPath = $this->hotPath instanceof Absent
            ? []
            : [sprintf('Reach::hotPath(%s)', PhpCalls::literal($this->hotPath))];

        $full = match ($this->full) {
            true => ['Reach::fullByDefault()'],
            false => ['Reach::changedByDefault()'],
            default => [],
        };

        $calls = PhpCalls::inWith(...$packages, ...$everything, ...$hotPath, ...$full);

        return $this->pruning instanceof Pruning ? $calls->and($this->pruning->php()) : $calls;
    }

    /** The pruning keys a later layer sets, laid over this one's, key by key. */
    private function laidPruning(Pruning|Absent $later): Pruning|Absent
    {
        return match (true) {
            $later instanceof Absent => $this->pruning,
            $this->pruning instanceof Absent => $later,
            default => $this->pruning->over($later),
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
