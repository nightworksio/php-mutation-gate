<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Score\Unrecorded;
use NightWorksIO\MutationGate\Core\Verdict\Judgement;

/**
 * One verdict as `trend.json` recorded it: its judgement, and each tree's
 * floor and score, where the entry holds them. An entry written before the
 * trend held judgements and floors has neither (ADR-0016, decision 10).
 */
final readonly class TrendEntry
{
    /**
     * @param array<string, Floor> $floors by tree
     * @param array<string, Score> $scores by tree
     */
    private function __construct(
        private Judgement|Unrecorded $verdict,
        private array $floors,
        private array $scores,
    ) {
    }

    /** The entry of a trend that has none. */
    public static function none(): self
    {
        return new self(Unrecorded::floor(), [], []);
    }

    /** An entry of a verdict that judged this, or whose judgement was not recorded, with no tree yet. */
    public static function judged(Judgement|Unrecorded $verdict): self
    {
        return new self($verdict, [], []);
    }

    public function withFloor(Path $tree, Floor $floor): self
    {
        return new self($this->verdict, [...$this->floors, $tree->value() => $floor], $this->scores);
    }

    public function withScore(Path $tree, Score $score): self
    {
        return new self($this->verdict, $this->floors, [...$this->scores, $tree->value() => $score]);
    }

    /** What the verdict judged; unrecorded for no entry, or one written before the trend recorded it. */
    public function verdict(): Judgement|Unrecorded
    {
        return $this->verdict;
    }

    public function floorOf(Path $tree): Floor|Unrecorded
    {
        return array_key_exists($tree->value(), $this->floors) ? $this->floors[$tree->value()] : Unrecorded::floor();
    }

    public function scoreOf(Path $tree): Score|Unrecorded
    {
        return array_key_exists($tree->value(), $this->scores) ? $this->scores[$tree->value()] : Unrecorded::floor();
    }
}
