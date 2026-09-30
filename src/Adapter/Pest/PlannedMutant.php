<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Line;

use function usort;

/**
 * A mutant as Pest made it: its native id, its file as Pest spells it on
 * disk, the lines it spans, its mutator class, Pest's diff of it, and the
 * mutated copy Pest serves in the mutant's own process.
 */
final readonly class PlannedMutant
{
    private function __construct(
        private string $id,
        private DiskPath $file,
        private Line $start,
        private Line $end,
        private string $mutator,
        private string $diff,
        private DiskPath $mutated,
    ) {
    }

    public static function of(
        string $id,
        DiskPath $file,
        Line $start,
        Line $end,
        string $mutator,
        string $diff,
        DiskPath $mutated,
    ): self {
        return new self($id, $file, $start, $end, $mutator, $diff, $mutated);
    }

    /**
     * Mutants ordered by file and then by the line each starts on, each
     * keeping its place among those that share both.
     *
     * @param  list<self> $planned
     * @return list<self>
     */
    public static function inOrder(array $planned): array
    {
        usort(
            $planned,
            static fn(self $one, self $two): int => $one->file->value() === $two->file->value()
                ? $one->start->number() <=> $two->start->number()
                : $one->file->value() <=> $two->file->value(),
        );

        return $planned;
    }

    /** Pest's own id of the mutant. */
    public function id(): string
    {
        return $this->id;
    }

    public function file(): DiskPath
    {
        return $this->file;
    }

    public function start(): Line
    {
        return $this->start;
    }

    public function end(): Line
    {
        return $this->end;
    }

    /** The mutator's class, as Pest names it. */
    public function mutator(): string
    {
        return $this->mutator;
    }

    /** The change, as Pest's diff of it reads. */
    public function diff(): string
    {
        return $this->diff;
    }

    /** The mutated copy Pest serves in the original's place in the mutant's own process. */
    public function mutated(): DiskPath
    {
        return $this->mutated;
    }
}
