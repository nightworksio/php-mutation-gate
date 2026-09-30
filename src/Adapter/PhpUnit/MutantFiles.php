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
 * What PHPUnit reads and writes for one mutant, in its own directory of the
 * adapter's: the ids of the tests it runs, one to a line; the results the
 * extension records and the guard the wrapper and the extension write, each
 * empty to begin with; the file the override serves, and the mutated file it
 * serves in its place.
 */
final readonly class MutantFiles
{
    private const string IDS = 'ids.txt';

    private const string RESULTS = 'results.txt';

    private const string GUARD = 'guard.txt';

    private const string MUTATED = 'mutant.php';

    private function __construct(
        private string $ids,
        private string $results,
        private string $guard,
        private string $original,
        private string $mutated,
    ) {
    }

    public static function writtenFor(Project $project, MadeMutant $mutant, TestIds $tests): self|CannotJudge
    {
        $id = $mutant->id()->value();
        $lines = implode("\n", array_map(static fn(TestId $test): string => $test->value(), [...$tests]));
        $ids = $project->written(sprintf('%s/%s', $id, self::IDS), sprintf("%s\n", $lines));
        $results = $project->written(sprintf('%s/%s', $id, self::RESULTS), '');
        $guard = $project->written(sprintf('%s/%s', $id, self::GUARD), '');
        $mutated = $project->written(sprintf('%s/%s', $id, self::MUTATED), $mutant->mutated()->text());

        return match (true) {
            $ids instanceof CannotJudge => $ids,
            $results instanceof CannotJudge => $results,
            $guard instanceof CannotJudge => $guard,
            $mutated instanceof CannotJudge => $mutated,
            default => new self($ids, $results, $guard, $project->absolute($mutant->location()->file()), $mutated),
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

    public function guard(): string
    {
        return $this->guard;
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
