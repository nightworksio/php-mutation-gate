<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function count;
use function file_put_contents;
use function mkdir;

use NightWorksIO\MutationGate\Adapter\Pest\PestStatus;
use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use Pest\Mutate\Mutators\Logical\TrueToFalse;

use function realpath;
use function sprintf;
use function str_replace;

/**
 * A project whose Band returns a `match (true)`, a statement whose first line
 * pcov never marks run, beside a statement of one line no test runs. Its
 * run's map has BandSpec cover the default arm on line 9 alone, so the arm
 * on line 8 and the head on line 7 are uncovered.
 */
final class MatchHeads
{
    public const string BAND = <<<'PHP'
        <?php
        namespace App;
        final class Band
        {
            public function of(int $n): string
            {
                return match (true) {
                    $n > 10 => 'high',
                    default => 'low',
                };
            }
            public function flat(): bool
            {
                return true;
            }
        }
        PHP;

    /** Each mutant this project's run leaves uncovered, by its id: the line, and the text it replaces and with what. */
    public const array MUTANTS = [
        'head' => [7, 'match (true)', 'match (false)'],
        'arm' => [8, "\$n > 10 => 'high'", "\$n > 10 => 'low'"],
        'flat' => [14, 'return true', 'return false'],
    ];

    private const string BANDS = 'P\Tests\BandSpec::__pest_evaluable_it_bands';

    private const string MUTATOR = TrueToFalse::class;

    public static function project(): Project
    {
        $root = (string) realpath(Scratch::directory());
        Scratch::write($root, 'src/Band.php', self::BAND);
        Scratch::write($root, 'tests/BandSpec.php', "<?php\nit('bands', fn () => expect(new App\\Band()->of(1))->toBe('low'));\n");

        return Project::at($root, Paths::of(Path::of('tests')), Path::of('.mutation-gate'), Path::of('vendor'));
    }

    /** The results file of a run that left every mutant uncovered, with its map beside it and each mutated copy kept. */
    public static function run(Project $project): string
    {
        $results = sprintf('%s/.mutation-gate/pest/results.jsonl', $project->root());
        mkdir(sprintf('%s/.mutation-gate/pest/mutants', $project->root()), recursive: true);
        CoverageMaps::write(
            Recorder::coverageBeside($results),
            sprintf('%s/', $project->root()),
            ['src/Band.php' => [9 => [0]]],
            [self::BANDS],
            [self::BANDS => 0.1],
        );
        $records = [];
        $finished = [];

        foreach (self::MUTANTS as $id => [$line, $removed, $added]) {
            $records[] = PestRun::planned($id, sprintf('%s/src/Band.php', $project->root()), $line, self::MUTATOR, $removed, $added);
            $finished[] = PestRun::finished($id, PestStatus::Uncovered, 0.0);
            file_put_contents(Recorder::mutantBeside($results, $id), str_replace($removed, $added, self::BAND));
        }

        PestRun::write($results, [...$records, PestRun::made(count(self::MUTANTS)), ...$finished, PestRun::end()]);

        return $results;
    }

    /** A mutant of Band the run left uncovered, as the gate reads it. */
    public static function mutant(string $id): Mutant
    {
        [$line, $removed, $added] = self::MUTANTS[$id];
        $diff = sprintf("@@ @@\n-%s\n+%s", $removed, $added);
        $path = Path::of('src/Band.php');

        return Mutant::of(
            MutantId::hash($path, self::MUTATOR, $diff, 0),
            $id,
            Location::of($path, Line::of($line), Line::of($line)),
            Mutation::of(self::MUTATOR, MutatorFamily::Logical, $diff),
            MutantStatus::Uncovered,
            Unmeasured::duration(),
        );
    }
}
