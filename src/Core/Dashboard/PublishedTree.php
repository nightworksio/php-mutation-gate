<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Dashboard;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\NothingToMutate;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Score\Undeclared;

/** One tree as a repository's `summary.json` says it: its path, its floor and its score (ADR-0024). */
final readonly class PublishedTree
{
    private function __construct(
        private string $path,
        private Floor|Undeclared $floor,
        private Score|NothingToMutate $score,
    ) {
    }

    public static function of(string $path, Floor|Undeclared $floor, Score|NothingToMutate $score): self
    {
        return new self($path, $floor, $score);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function floor(): Floor|Undeclared
    {
        return $this->floor;
    }

    public function score(): Score|NothingToMutate
    {
        return $this->score;
    }

    /** The tree as scoring below the floor it is held to; not given where it is held to none, or meets it. */
    public function below(): TreeBelow|NotGiven
    {
        return $this->floor instanceof Floor
            && $this->score instanceof Score
            && $this->score->hundredths() < $this->floor->hundredths()
            ? TreeBelow::of($this->path, $this->floor, $this->score)
            : NotGiven::value();
    }
}
