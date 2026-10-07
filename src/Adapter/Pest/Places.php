<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_key_first;
use function array_key_last;
use function array_unique;
use function count;

use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Prefix;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * Where each killer of the own runs on one mutated copy stood, in the order
 * their lines came (see Recording\Placed): its position and the digest of
 * the order up to it, where the run started it as a test, and the process
 * it came from, where its line names one.
 */
final readonly class Places
{
    /** @param list<array{int|NotGiven, string|NotGiven, int|NotGiven}> $places position, order, process, by killer */
    private function __construct(private array $places)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /** These, and where one killer more stood, and the process it came from. */
    public function with(int|NotGiven $at, string|NotGiven $order, int|NotGiven $run): self
    {
        return new self([...$this->places, [$at, $order, $run]]);
    }

    /**
     * How far the run went, its files these: the position of its first
     * killer, and the key of its order up to its last, where that killer's
     * line gives the order. None where its first killer stood nowhere, as a
     * class whose set-up failed does, or where its killers do not all come
     * from one process the lines name, since then which run went how far
     * cannot be told.
     */
    public function prefix(Paths $files): Prefix|NotGiven
    {
        $first = array_key_first($this->places);
        $last = array_key_last($this->places);

        if ($first === null || $last === null || ! $this->ofOneRun()) {
            return NotGiven::value();
        }

        [$at] = $this->places[$first];
        [, $order] = $this->places[$last];

        return match (true) {
            $at instanceof NotGiven => $at,
            $order instanceof NotGiven => Prefix::at($at),
            default => Prefix::keyedAt($at, Prefix::keyOf($files, $order)),
        };
    }

    /** Whether every killer's line names the same process. */
    private function ofOneRun(): bool
    {
        $runs = [];

        foreach ($this->places as [, , $run]) {
            if ($run instanceof NotGiven) {
                return false;
            }

            $runs[] = $run;
        }

        return count(array_unique($runs)) === 1;
    }
}
