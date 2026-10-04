<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Mutator\Engine;

use function array_key_exists;
use function array_values;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Cost\MutantSites;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Hunks;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Mutator\Hint;
use NightWorksIO\MutationGate\Mutator\Mutator;

use function sprintf;
use function usort;

/**
 * The gate's own mutation engine, which native runners take their mutants
 * from (ADR-0023 decision 8). Every mutator is offered every node it handles
 * anywhere in a file, as Pest offers them, and each change is printed by
 * php-parser's format-preserving printer, so only the changed node's lines
 * differ and the diff is exact. A change that prints the same code is no
 * mutant.
 *
 * @internal the engine's own
 */
final readonly class Engine
{
    /** @param list<Mutator> $mutators */
    private function __construct(private array $mutators)
    {
    }

    public static function with(Mutator ...$mutators): self
    {
        return new self(array_values($mutators));
    }

    /**
     * Every mutant of the file, in the order of their lines, each with the
     * gate's id (ADR-0004 decision 2). A file that does not parse cannot be
     * judged.
     */
    public function mutantsOf(Path $file, Contents $code): MadeMutants|CannotJudge
    {
        $source = Source::parse($code->text());

        return $source instanceof Unparsable
            ? $this->unparsable($file, $source)
            : $this->identified($file, $this->changes($source, $code));
    }

    /**
     * Where the file's mutants start, counted without making them: fast
     * enough to count every file a plan runs. A change that would print the
     * same code counts here, where `mutantsOf()` makes no mutant of it. A
     * file that does not parse cannot be judged.
     */
    public function sitesOf(Path $file, Contents $code): MutantSites|CannotJudge
    {
        $source = Source::parse($code->text());

        return $source instanceof Unparsable
            ? $this->unparsable($file, $source)
            : $source->sites($file, ...$this->mutators);
    }

    /**
     * Every change a mutator makes to the code, with the mutator that made it,
     * ordered by the line it starts on and then by the order they were made.
     *
     * @return list<array{Mutator, Edit}>
     */
    private function changes(Source $source, Contents $code): array
    {
        $changes = [];

        foreach ($this->mutators as $mutator) {
            foreach ($source->edits($mutator, Offered::Everywhere) as $edit) {
                if ($edit->mutated() !== $code->text()) {
                    $changes[] = [$mutator, $edit];
                }
            }
        }

        usort($changes, static fn(array $a, array $b): int => $a[1]->start()->number() <=> $b[1]->start()->number());

        return $changes;
    }

    private function unparsable(Path $file, Unparsable $source): CannotJudge
    {
        return CannotJudge::because(sprintf(
            '%s does not parse, so no mutant of it can be made: %s',
            $file->value(),
            $source->reason(),
        ));
    }

    /**
     * The mutants of these changes, each counting how many before it share
     * its mutator and diff, whitespace aside: those share the id they would
     * have as the first.
     *
     * @param list<array{Mutator, Edit}> $changes
     */
    private function identified(Path $file, array $changes): MadeMutants
    {
        $mutants = [];
        $seen = [];

        foreach ($changes as [$mutator, $edit]) {
            $name = $mutator->name()->value();
            $diff = Hunks::of($edit->changed());
            $key = MutantId::hash($file, $name, $diff, 0)->value();
            $occurrence = array_key_exists($key, $seen) ? $seen[$key] : 0;
            $seen[$key] = $occurrence + 1;
            $mutants[] = MadeMutant::of(
                MutantId::hash($file, $name, $diff, $occurrence),
                Location::of($file, $edit->start(), $edit->end()),
                Mutation::of($name, $mutator->family(), $diff, $this->hintOf($mutator)),
                Contents::of($edit->mutated()),
            );
        }

        return MadeMutants::of(...$mutants);
    }

    /** A mutator's own sentence for its survivors; nothing where it uses its family's. */
    private function hintOf(Mutator $mutator): string|NotGiven
    {
        $hint = $mutator->hint();

        return $hint instanceof Hint ? $hint->sentence() : NotGiven::value();
    }

}
