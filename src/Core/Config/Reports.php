<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function array_map;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;

/**
 * The reports a run writes or sends (ADR-0009): `reports`, which each layer adds to; and when `config:suggest`
 * proposes a change (ADR-0076): `suggest`.
 */
final readonly class Reports implements Part
{
    /** @param Listed<Report>|Absent $reports */
    private function __construct(private Listed|Absent $reports, private Suggest $suggest)
    {
    }

    /** @param Listed<Report>|Absent $reports */
    public static function of(Listed|Absent $reports): self
    {
        return new self($reports, Suggest::of());
    }

    /** What a layer sets of when `config:suggest` proposes a change, and of no report. */
    public static function suggesting(Suggest $suggest): self
    {
        return new self(Absent::setting(), $suggest);
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

        return new self($reports, $this->suggest->over($later->suggest));
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

        return $reports->and($this->suggest->php());
    }
}
