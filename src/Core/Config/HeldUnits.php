<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Hold\HotPaths;

use function sprintf;

/**
 * How held units are read (ADR-0005): the share of the suite past which code
 * nothing holds is warned about, `holds.hotPath`, the fewest tests a suite
 * holds for a file to read as hot, `holds.fewestTests`, and how a shard
 * learns what a held unit's holding tests cover, `holds.coverage`.
 */
final readonly class HeldUnits
{
    private function __construct(
        private int|float|Absent $hotPath,
        private int|Absent $fewestTests,
        private HeldCoverageMode|Absent $coverage,
    ) {
    }

    public static function of(
        int|float|Absent $hotPath = new Absent(),
        int|Absent $fewestTests = new Absent(),
        HeldCoverageMode|Absent $coverage = new Absent(),
    ): self {
        return new self($hotPath, $fewestTests, $coverage);
    }

    /** These settings, with what a later layer sets laid over them. */
    public function over(self $later): self
    {
        return new self(
            Absent::laid($this->hotPath, $later->hotPath),
            Absent::laid($this->fewestTests, $later->fewestTests),
            Absent::laid($this->coverage, $later->coverage),
        );
    }

    /** `holds.coverage`: how a shard learns what a held unit's holding tests cover; `measure` unless a layer says. */
    public function coverage(): HeldCoverageMode
    {
        return $this->coverage instanceof HeldCoverageMode ? $this->coverage : HeldCoverageMode::Measure;
    }

    /** The files most of the suite runs through that nothing holds, past `holds.hotPath` of it. */
    public function hotPaths(): HotPaths
    {
        return HotPaths::atShareOfAtLeast(
            $this->hotPath instanceof Absent ? HotPaths::STANDARD : $this->hotPath,
            $this->fewestTests instanceof Absent ? HotPaths::SMALLEST_SUITE : $this->fewestTests,
        );
    }

    /** `holds`, as a layer writes what it sets of it. */
    public function written(): Member
    {
        return Member::unlessEmpty(
            'holds',
            Json::object(
                Member::of('hotPath', $this->hotPath),
                Member::of('fewestTests', $this->fewestTests),
                Member::of(
                    'coverage',
                    $this->coverage instanceof HeldCoverageMode ? $this->coverage->value : $this->coverage,
                ),
            ),
        );
    }

    /** The builder's calls that set these as a layer sets them. */
    public function php(): PhpCalls
    {
        return PhpCalls::inWith(
            ...$this->hotPath instanceof Absent
                ? []
                : [sprintf('HeldUnits::hotPath(%s)', PhpCalls::literal($this->hotPath))],
            ...$this->fewestTests instanceof Absent
                ? []
                : [sprintf('HeldUnits::fewestTests(%d)', $this->fewestTests)],
            ...$this->coverage instanceof HeldCoverageMode ? [$this->coverage->php()] : [],
        );
    }
}
