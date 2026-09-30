<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Hold\HotPaths;

use function sprintf;

/**
 * What a change reaches (ADR-0005): `packages`, `reach.everything`, and the
 * share of the suite past which code nothing holds is warned about,
 * `holds.hotPath`.
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
    ): self {
        return new self($packages, $everything, $hotPath);
    }

    public static function none(): self
    {
        return self::of();
    }

    public static function standard(): self
    {
        $none = self::none();

        return self::of($none->packages(), $none->everything(), $none->hotPaths()->share());
    }

    public function over(Part $later): self
    {
        return $later instanceof self
            ? new self(
                $this->joined($this->packages, $later->packages),
                $this->joined($this->everything, $later->everything),
                $later->hotPath instanceof Absent ? $this->hotPath : $later->hotPath,
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

    public function written(Origin $origin): Json
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
        );
    }

    public function php(Origin $origin): PhpCalls
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

        return PhpCalls::inWith(...$packages, ...$everything, ...$hotPath);
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
