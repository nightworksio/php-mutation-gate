<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function array_map;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;

/**
 * The reports a run writes or sends (ADR-0009): `reports`, which each layer adds to; when `config:suggest`
 * proposes a change (ADR-0076): `suggest`; and how much of the heatmap each report shows (ADR-0084): `heatmap`.
 */
final readonly class Reports implements Part
{
    /** @param Listed<Report>|Absent $reports */
    private function __construct(private Listed|Absent $reports, private Suggest $suggest, private HeatmapRows $heatmap)
    {
    }

    /** @param Listed<Report>|Absent $reports */
    public static function of(Listed|Absent $reports): self
    {
        return new self($reports, Suggest::of(), HeatmapRows::of());
    }

    /** What a layer sets of when `config:suggest` proposes a change, and of no report. */
    public static function suggesting(Suggest $suggest): self
    {
        return new self(Absent::setting(), $suggest, HeatmapRows::of());
    }

    /** What a layer sets of how much of the heatmap each report shows, and of no report. */
    public static function heatmapping(HeatmapRows $heatmap): self
    {
        return new self(Absent::setting(), Suggest::of(), $heatmap);
    }

    public static function none(): self
    {
        return self::of(Absent::setting());
    }

    public static function standard(): self
    {
        return self::of(Listed::of());
    }

    public function over(Part $later): self
    {
        if (! $later instanceof self) {
            return $this;
        }

        $reports = match (true) {
            $later->reports instanceof Absent => $this->reports,
            $this->reports instanceof Absent => $later->reports,
            default => $this->reports->and(
                $later->reports,
                static fn(Report $report): string => $report->written(ProjectRoot::origin())->line(),
            ),
        };

        return new self($reports, $this->suggest->over($later->suggest), $this->heatmap->over($later->heatmap));
    }

    /** @return Listed<Report> */
    public function reports(): Listed
    {
        return $this->reports instanceof Listed ? $this->reports : Listed::of();
    }

    public function suggest(): Suggest
    {
        return $this->suggest;
    }

    public function heatmap(): HeatmapRows
    {
        return $this->heatmap;
    }

    public function written(PathOrigin $origin): Json
    {
        return Json::object(
            Member::of(
                'reports',
                $this->reports instanceof Listed ? Json::items(...array_map(
                    static fn(Report $report): Json => $report->written($origin),
                    [...$this->reports],
                )) : $this->reports,
            ),
            $this->suggest->written(),
            $this->heatmap->written(),
        );
    }

    public function php(PathOrigin $origin): PhpCalls
    {
        $reports = $this->reports instanceof Listed && [...$this->reports] !== []
            ? PhpCalls::onGate(
                GateMethod::Reporting,
                ...array_map(
                    static fn(Report $report): string => $report->php($origin),
                    [...$this->reports],
                ),
            )
            : PhpCalls::none();

        return $reports->and($this->suggest->php())->and($this->heatmap->php());
    }
}
