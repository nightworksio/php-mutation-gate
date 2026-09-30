<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use function array_map;
use function implode;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Mutator\Engine\MadeMutant;

use function sprintf;

/**
 * What PHPUnit reads and writes for one mutant, in the adapter's directory:
 * the ids of the tests it runs, one to a line; the results the extension
 * records, empty to begin with; the file the override serves, and the mutated
 * file it serves in its place.
 */
final readonly class MutantFiles
{
    private function __construct(
        private string $ids,
        private string $results,
        private string $original,
        private string $mutated,
    ) {
    }

    public static function writtenFor(Project $project, MadeMutant $mutant, TestIds $tests): self|CannotJudge
    {
        $id = $mutant->id()->value();
        $lines = implode("\n", array_map(static fn(TestId $test): string => $test->value(), [...$tests]));
        $ids = $project->written(sprintf('%s/ids.txt', $id), sprintf("%s\n", $lines));
        $results = $project->written(sprintf('%s/results.txt', $id), '');
        $mutated = $project->written(sprintf('%s/mutant.php', $id), $mutant->mutated()->text());

        return match (true) {
            $ids instanceof CannotJudge => $ids,
            $results instanceof CannotJudge => $results,
            $mutated instanceof CannotJudge => $mutated,
            default => new self($ids, $results, $project->absolute($mutant->location()->file()), $mutated),
        };
    }

    public function ids(): string
    {
        return $this->ids;
    }

    public function results(): string
    {
        return $this->results;
    }

    public function original(): string
    {
        return $this->original;
    }

    public function mutated(): string
    {
        return $this->mutated;
    }
}
