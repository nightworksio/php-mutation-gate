<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Dashboard;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Score\NothingToMutate;
use NightWorksIO\MutationGate\Core\Score\Score;

/**
 * One of the lowest-scoring files a repository's `summary.json` names (ADR-0100): its path, its score, its
 * score on the base where the base had one, and how many of its mutants are not killed.
 *
 * @internal
 */
final readonly class PublishedFile
{
    private function __construct(
        private string $path,
        private Score|NothingToMutate $score,
        private Score|NotGiven $base,
        private int $notKilled,
    ) {
    }

    public static function of(string $path, Score|NothingToMutate $score, Score|NotGiven $base, int $notKilled): self
    {
        return new self($path, $score, $base, $notKilled);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function score(): Score|NothingToMutate
    {
        return $this->score;
    }

    public function base(): Score|NotGiven
    {
        return $this->base;
    }

    public function notKilled(): int
    {
        return $this->notKilled;
    }
}
