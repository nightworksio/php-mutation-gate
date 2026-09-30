<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function array_map;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;

/** The reports a run writes or sends (ADR-0009): `reports`, which each layer adds to. */
final readonly class Reports implements Part
{
    /** @param Listed<Report>|Absent $reports */
    private function __construct(private Listed|Absent $reports)
    {
    }

    /** @param Listed<Report>|Absent $reports */
    public static function of(Listed|Absent $reports): self
    {
        return new self($reports);
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
        return $later instanceof self
            ? new self(match (true) {
                $later->reports instanceof Absent => $this->reports,
                $this->reports instanceof Absent => $later->reports,
                default => $this->reports->and(
                    $later->reports,
                    static fn(Report $report): string => $report->written(ProjectRoot::origin())->line(),
                ),
            })
            : $this;
    }

    /** @return Listed<Report> */
    public function reports(): Listed
    {
        return $this->reports instanceof Listed ? $this->reports : Listed::of();
    }

    public function written(PathOrigin $origin): Json
    {
        return Json::object(Member::of(
            'reports',
            $this->reports instanceof Listed ? Json::items(...array_map(
                static fn(Report $report): Json => $report->written($origin),
                [...$this->reports],
            )) : $this->reports,
        ));
    }

    public function php(PathOrigin $origin): PhpCalls
    {
        return $this->reports instanceof Listed && [...$this->reports] !== []
            ? PhpCalls::onGate(
                'reporting',
                ...array_map(
                    static fn(Report $report): string => $report->php($origin),
                    [...$this->reports],
                ),
            )
            : PhpCalls::none();
    }
}
