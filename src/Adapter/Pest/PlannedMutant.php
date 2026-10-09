<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;

use function usort;

/**
 * A mutant as Pest made it: its native id, its file as Pest spells it on
 * disk, the lines it spans, its mutator as the gate names it (Pest's class
 * for one Pest ships, a bridged mutator's own name for a registered one),
 * Pest's diff of it, and the mutated copy Pest serves in the mutant's own
 * process.
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
        private int $occurrence = 0,
        private bool $twin = false,
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
     * This mutant, kept out of Pest's run as the twin of one made before it
     * that leaves its file as it does (ADR-0025, decision 13).
     */
    public function asTwin(): self
    {
        return clone($this, ['twin' => true]);
    }

    /** Whether it was kept out of Pest's run as another's twin, whose run judges it. */
    public function isTwin(): bool
    {
        return $this->twin;
    }

    /**
     * The mutant whose run judges this one: the first of these that Pest
     * ran on this one's mutated copy of its file, where this one is a twin;
     * this one otherwise, or where none is there.
     *
     * @param list<self> $planned numbered, as numbered() numbers them
     */
    public function judgedBy(array $planned): self
    {
        foreach ($this->twin ? $planned : [] as $mutant) {
            $alike = $mutant->file->value() === $this->file->value()
                && $mutant->mutated->value() === $this->mutated->value();

            if (! $mutant->twin && $alike) {
                return $mutant;
            }
        }

        return $this;
    }

    /**
     * The mutants, each numbered among those before it that Pest gave the
     * same id. Pest's id hashes the file, the mutator and the mutated source,
     * so two changes that leave the same source share one, as removing either
     * item of `['', '']` does; Pest still makes, runs and counts both.
     *
     * @param  list<self> $planned
     * @return list<self>
     */
    public static function numbered(array $planned): array
    {
        $seen = [];
        $numbered = [];

        foreach ($planned as $mutant) {
            $occurrence = array_key_exists($mutant->id, $seen) ? $seen[$mutant->id] : 0;
            $numbered[] = clone($mutant, ['occurrence' => $occurrence]);
            $seen[$mutant->id] = $occurrence + 1;
        }

        return $numbered;
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

    /** Pest's own id of the mutant, which another mutant that leaves the same source shares. */
    public function id(): string
    {
        return $this->id;
    }

    /**
     * How many mutants before it Pest gave the same id: 0 for the first, and
     * for every mutant numbered() has not seen.
     */
    public function occurrence(): int
    {
        return $this->occurrence;
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

    /** The mutator, as the gate names it: Pest's class for one Pest ships, and its own name for a bridged one. */
    public function mutator(): string
    {
        return $this->mutator;
    }

    /** The change, as Pest's diff of it reads. */
    public function diff(): string
    {
        return $this->diff;
    }

    /** The change, as the gate names it: its mutator, the mutator's family and hint, and the diff. */
    public function mutation(Bridges $bridges): Mutation
    {
        return Mutation::of(
            $this->mutator,
            $bridges->familyOf($this->mutator),
            Diff::fromPest($this->diff),
            $bridges->hintOf($this->mutator),
        );
    }

    /** The mutated copy Pest serves in the original's place in the mutant's own process. */
    public function mutated(): DiskPath
    {
        return $this->mutated;
    }
}
