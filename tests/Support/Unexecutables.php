<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_key_exists;
use function count;
use function file_put_contents;
use function in_array;
use function mkdir;

use NightWorksIO\MutationGate\Adapter\Pest\Command;
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
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use Pest\Mutate\Mutators\Number\IncrementInteger;

use function realpath;
use function sprintf;
use function str_replace;

/**
 * A project whose Money declares values on lines coverage cannot see run: a
 * constant a test reads, one only a covered method reads, one nothing reads,
 * beside a method whose line is executable. Its run's map has InternalSpec
 * cover line 10 and OtherSpec line 14 of Money.
 */
final class Unexecutables
{
    public const string MONEY = <<<'PHP'
        <?php
        namespace App;
        final class Money
        {
            public const RATE = 21;
            public const UNREAD = 3;
            public const INTERNAL = 5;
            public function internal(): int
            {
                return self::INTERNAL;
            }
            public function other(): int
            {
                return 0;
            }
        }
        PHP;

    /** Each mutant this project's run can leave uncovered, by its id: the line, and the text it replaces and with what. */
    public const array MUTANTS = [
        'rate' => [5, 'RATE = 21', 'RATE = 22'],
        'unread' => [6, 'UNREAD = 3', 'UNREAD = 4'],
        'internal' => [7, 'INTERNAL = 5', 'INTERNAL = 6'],
        'other' => [14, 'return 0', 'return 1'],
    ];

    private const string INTERNAL = 'P\Tests\InternalSpec::__pest_evaluable_it_runs';

    private const string OTHER = 'P\Tests\OtherSpec::__pest_evaluable_it_runs_the_other';

    private const string READS = 'P\Tests\MoneySpec::__pest_evaluable_it_reads';

    private const string INCREMENT = IncrementInteger::class;

    public static function project(): Project
    {
        $root = (string) realpath(Scratch::directory());
        Scratch::write($root, 'src/Money.php', self::MONEY);
        Scratch::write($root, 'tests/MoneySpec.php', "<?php\nuse App\\Money;\nit('reads', fn () => Money::RATE);\n");
        Scratch::write($root, 'tests/InternalSpec.php', "<?php\nit('runs', fn () => new App\\Money()->internal());\n");
        Scratch::write($root, 'tests/OtherSpec.php', "<?php\nit('runs the other', fn () => new App\\Money()->other());\n");
        Scratch::write($root, 'tests/Support/RatedTest.php', "<?php\nnamespace Tests\\Support;\nfinal class RatedTest { const RATE = \\App\\Money::RATE; }\n");

        return Project::at($root, Paths::of(Path::of('tests')), Path::of('.mutation-gate'), Path::of('vendor'));
    }

    /**
     * The results file of a run that left these mutants uncovered, with its
     * map beside it and each mutant's mutated copy kept, but for those left
     * out.
     *
     * @param list<string> $uncovered
     * @param list<string> $uncopied
     */
    public static function run(Project $project, array $uncovered, array $uncopied = []): string
    {
        $results = sprintf('%s/.mutation-gate/pest/results.jsonl', $project->root());
        mkdir(sprintf('%s/.mutation-gate/pest/mutants', $project->root()), recursive: true);
        CoverageMaps::write(
            Recorder::coverageBeside($results),
            sprintf('%s/', $project->root()),
            ['src/Money.php' => [10 => [0], 14 => [1]]],
            [self::INTERNAL, self::OTHER, self::READS],
            [self::INTERNAL => 0.1, self::OTHER => 0.1, self::READS => 0.1],
        );
        $records = [];

        foreach ($uncovered as $id) {
            [$line, $removed, $added] = self::MUTANTS[$id];
            $records[] = PestRun::planned($id, sprintf('%s/src/Money.php', $project->root()), $line, self::INCREMENT, $removed, $added);
            $copied = ! in_array($id, $uncopied, strict: true);

            if ($copied) {
                file_put_contents(Recorder::mutantBeside($results, $id), str_replace($removed, $added, self::MONEY));
            }
        }

        PestRun::write($results, [...$records, PestRun::made(count($uncovered)), PestRun::end()]);

        return $results;
    }

    /** A mutant of Money the run left uncovered, as the gate reads it. */
    public static function mutant(string $id): Mutant
    {
        [$line, $removed, $added] = self::MUTANTS[$id];
        $diff = sprintf("@@ @@\n-%s\n+%s", $removed, $added);
        $path = Path::of('src/Money.php');

        return Mutant::of(
            MutantId::hash($path, self::INCREMENT, $diff, 0),
            $id,
            Location::of($path, Line::of($line), Line::of($line)),
            Mutation::of(self::INCREMENT, MutatorFamily::Arithmetic, $diff),
            MutantStatus::Uncovered,
            Unmeasured::duration(),
        );
    }

    /**
     * Answers a judging run as Pest would: the tests on their own pass, and a
     * run of a mutant fails wherever it names a test file that reads a value
     * the mutant changes, writing a guard that says the mutated copy ran.
     *
     * @param list<string> $killing the test files, by name, that fail against the mutant
     */
    public static function answering(Command $command, array $killing): Ran
    {
        $environment = $command->environment();

        if (! array_key_exists('PEST_MUTATION_FILE', $environment) || $environment['PEST_MUTATION_FILE'] === false) {
            return Ran::finished(succeeded: true, output: '');
        }

        file_put_contents((string) $environment['MUTATION_GATE_GUARD'], '{"before":false,"loaded":true,"opcache":false}');
        $fails = false;

        foreach ($killing as $file) {
            $fails = $fails || in_array($file, $command->arguments(), strict: true);
        }

        return Ran::finished(succeeded: ! $fails, output: '');
    }
}
