<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Dashboard;

use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Score;

/**
 * A tree a repository published as scoring below its floor: its path, the floor and the score (ADR-0024).
 *
 * @internal
 */
final readonly class TreeBelow
{
    private function __construct(private string $path, private Floor $floor, private Score $score)
    {
    }

    public static function of(string $path, Floor $floor, Score $score): self
    {
        return new self($path, $floor, $score);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function floor(): Floor
    {
        return $this->floor;
    }

    public function score(): Score
    {
        return $this->score;
    }
}
