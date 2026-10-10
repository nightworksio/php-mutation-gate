<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_filter;
use function array_map;
use function array_values;
use function end;
use function file_put_contents;
use function in_array;
use function is_file;
use function json_encode;

use NightWorksIO\MutationGate\Adapter\Pest\Command;
use NightWorksIO\MutationGate\Adapter\Pest\Diff;
use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\Invocation;
use NightWorksIO\MutationGate\Adapter\Pest\Patch;
use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Adapter\Pest\PestStatus;
use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Placed;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordLine;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\CoveredLine;
use NightWorksIO\MutationGate\Core\Coverage\TimedTest;
use NightWorksIO\MutationGate\Core\Coverage\Unplaced;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\OrderDigest;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitTestList;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use Pest\Mutate\Mutators\Arithmetic\PlusToMinus;

use function realpath;
use function rtrim;
use function sprintf;

/** Projects, runs and shells the tests of the Pest adapter set up. */
final readonly class PestCases
{
    public const string RUN_PLUS = PlusToMinus::class;

    public const string RUN_ADDS = 'P\Tests\MoneySpec::__pest_evaluable_it_adds';

    /** The list of tests Pest writes for the fixture: two tests, one of them the canary group's. */
    public const string RUN_LISTING = <<<'XML'
        <?xml version="1.0"?>
        <testSuite xmlns="https://xml.phpunit.de/testSuite">
         <tests>
          <testClass name="P\Tests\MoneySpec" file="eval()'d code">
           <testMethod id="P\Tests\MoneySpec::__pest_evaluable_it_adds" name="__pest_evaluable_it_adds"/>
           <testMethod id="P\Tests\MoneySpec::__pest_evaluable_it_is_alive" name="__pest_evaluable_it_is_alive"/>
          </testClass>
         </tests>
         <groups>
          <group name="default">
           <test id="P\Tests\MoneySpec::__pest_evaluable_it_adds"/>
          </group>
          <group name="mutation-canary">
           <test id="P\Tests\MoneySpec::__pest_evaluable_it_is_alive"/>
          </group>
         </groups>
        </testSuite>
        XML;

    /** The file Pest lists a project's tests into. */
    public static function listingFile(Project $at): string
    {
        return sprintf('%s/%s', $at->workspace(), PhpUnitTestList::FILE);
    }

    /** A run that lists the fixture's tests into the file a listing command names, as Pest does. */
    public static function listed(Command $command): Ran
    {
        TestLists::wrote($command->arguments(), self::RUN_LISTING);

        return Ran::finished(succeeded: true, output: '');
    }

    /**
     * A coverage map in a project's root, covering these lines of its files, by the one test RUN_ADDS.
     *
     * @param array<non-empty-string, array<positive-int, list<int<0, max>>>> $lines
     * @param array<non-empty-string, float>                                $durations
     */
    public static function map(string $root, string $file, array $lines, array $durations): void
    {
        CoverageMaps::write(sprintf('%s/%s', $root, $file), sprintf('%s/', $root), $lines, [self::RUN_ADDS], $durations);
    }

    /** A request to mutate src/Money.php against the whole suite. */
    public static function money(): MutationRequest
    {
        return MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests());
    }

    /** The results file every run of a project records to. */
    public static function results(Project $at): string
    {
        return sprintf('%s/.mutation-gate/pest/results.jsonl', $at->root());
    }

    /** Pest patched with the canary group mutation-canary. */
    public static function canary(): Patching
    {
        return Patching::on(Group::named('mutation-canary'));
    }

    /** Pest's command lines in a project that installs its packages in `vendor`. */
    public static function invocation(): Invocation
    {
        return Invocation::installedIn(Path::of('vendor'));
    }

    /** A project in a new directory, by its real path, holding src/Money.php, that installs its packages in `vendor` or another directory. */
    public static function project(string $vendor = 'vendor'): Project
    {
        $root = (string) realpath(Scratch::directory());
        Scratch::write($root, 'src/Money.php', "<?php\n\nfinal class Money\n{\n}\n");

        return Project::at($root, Paths::of(Path::of('tests')), Path::of('.mutation-gate'), Path::of($vendor));
    }

    /** What Pest and the plugin leave when a run mutates src/Money.php's line 11 once, and a test it names kills it. */
    public static function killed(Command $command, Project $project): Ran
    {
        $results = sprintf('%s', $command->environment()['MUTATION_GATE_RESULTS'] ?? '');

        if (is_file($results)) {
            return Ran::finished(succeeded: false, output: 'an earlier run\'s results were left in place');
        }

        $money = sprintf('%s/src/Money.php', $project->root());
        $map = Recorder::coverageBeside($results);
        CoverageMaps::write($map, sprintf('%s/', $project->root()), ['src/Money.php' => [11 => [0]]], [self::RUN_ADDS], []);
        PestRun::write($results, [
            PestRun::planned('n1', $money, 11, self::RUN_PLUS, 'return $a + $b;', 'return $a - $b;'),
            PestRun::made(1),
            PestRun::killed('n1', self::RUN_ADDS),
            PestRun::finished('n1', PestStatus::Tested, 0.25),
            PestRun::end(),
        ]);

        return Ran::finished(succeeded: true, output: '  Mutations: 1 tested');
    }

    /** The mutant that run reports. */
    public static function mutant(): Mutant
    {
        $diff = Diff::fromPest("\n  <fg=red>-        return \$a + \$b;</>\n  <fg=green>+        return \$a - \$b;</>\n");
        $path = Path::of('src/Money.php');

        return Mutant::of(
            MutantId::hash($path, self::RUN_PLUS, $diff, 0),
            'n1',
            Location::of($path, Line::of(11), Line::of(11)),
            Mutation::of(self::RUN_PLUS, MutatorFamily::Arithmetic, $diff),
            MutantStatus::Killed,
            Seconds::of(0.25),
        )->killedBy(TestIds::of(TestId::of(self::RUN_ADDS)));
    }

    /** A project with a patched copy of the installed pest-plugin-mutate, and the planning job's map. */
    public static function patched(string $vendor = 'vendor'): Project
    {
        $at = self::project($vendor);

        MutatePlugin::pristine()->into(sprintf('%s/%s', $at->root(), $vendor));
        Patch::applyIn(sprintf('%s/%s', $at->root(), $vendor));
        self::handedOver($at, 'planned', CoverageMap::of(CoveredLine::of(Path::of('src/Money.php'), 11, self::RUN_ADDS))
            ->timedEach(TimedTest::of(self::RUN_ADDS, 1.25), TimedTest::of('Tests\B::c', 2.0)));

        return $at;
    }

    /** The gate's own map, as another job hands it over in a directory of the project. */
    public static function handedOver(Project $at, string $directory, CoverageMap $map): void
    {
        Scratch::write($at->root(), CoverageMapFile::in(Path::of($directory))->value(), CoverageMapFile::encode($map, Unplaced::map()));
    }

    /**
     * A shell whose first mutation run kills src/Money.php's line 11 with no test named as its killer, as a run that
     * could not load its tests does, and whose next one, loading every test file, finds it survives.
     */
    public static function loadedNothing(Project $at, string ...$killers): ShellFake
    {
        return new ShellFake(static function (Command $command, int $before) use ($at, $killers): Ran {
            $results = sprintf('%s', $command->environment()[GateVariable::Results->value] ?? '');
            $money = sprintf('%s/src/Money.php', $at->root());
            CoverageMaps::write(Recorder::coverageBeside($results), sprintf('%s/', $at->root()), ['src/Money.php' => [11 => [0]]], [self::RUN_ADDS], []);
            $status = $before === 0 ? PestStatus::Tested : PestStatus::Untested;
            PestRun::write($results, [
                PestRun::planned('n1', $money, 11, self::RUN_PLUS, 'return $a + $b;', 'return $a - $b;'),
                PestRun::made(1),
                ...($before === 0 ? array_values($killers) : []),
                PestRun::finished('n1', $status, 0.25),
                PestRun::end(),
            ]);

            return Ran::finished(succeeded: true, output: sprintf('  Mutations: 1 %s', $status->value));
        });
    }

    /**
     * A patched run of src/Money.php in which a test kills the mutant of line 11 in its own run narrowed to
     * tests/MoneySpec.php, which it survives with every test file, and the tests of the narrowed files, alone on the
     * unmutated code, pass or fail.
     */
    public static function narrowedKill(Project $at, bool $passAlone): ShellFake
    {
        return new ShellFake(static function (Command $command) use ($at, $passAlone): Ran {
            $results = sprintf('%s', $command->environment()[GateVariable::Results->value] ?? '');

            if ($results === '') {
                return Ran::finished(succeeded: $passAlone, output: 'the narrowed files alone');
            }

            $narrowed = ($command->environment()[GateVariable::Narrow->value] ?? false) === '1';
            $status = $narrowed ? PestStatus::Tested : PestStatus::Untested;
            CoverageMaps::write(Recorder::coverageBeside($results), sprintf('%s/', $at->root()), ['src/Money.php' => [11 => [0]]], [self::RUN_ADDS], []);
            PestRun::write($results, [
                PestRun::planned('n1', sprintf('%s/src/Money.php', $at->root()), 11, self::RUN_PLUS, 'return $a + $b;', 'return $a - $b;'),
                PestRun::made(1),
                ...($narrowed ? [PestRun::killed('n1', self::RUN_ADDS), PestRun::narrowed('n1', [self::spec($at)])] : []),
                PestRun::finished('n1', $status, 0.25),
                PestRun::end(),
            ]);

            return Ran::finished(succeeded: true, output: sprintf('  Mutations: 1 %s', $status->value));
        });
    }

    /** A result as it would be with no step timed: what a run found, whatever the wall clock read. */
    public static function untimed(MutationResult|CannotJudge $result): MutationResult|CannotJudge
    {
        return $result instanceof MutationResult
            ? MutationResult::of($result->mutants(), $result->skipped())->withWarnings($result->warnings())
            : $result;
    }

    /** The test file a narrowed run of the project loads. */
    public static function spec(Project $at): string
    {
        return sprintf('%s/tests/MoneySpec.php', $at->root());
    }

    /**
     * The status of each mutant a result holds, or why there is none.
     *
     * @return list<MutantStatus>|CannotJudge
     */
    public static function statuses(MutationResult|CannotJudge $result): array|CannotJudge
    {
        return $result instanceof MutationResult
            ? array_map(static fn(Mutant $mutant): MutantStatus => $mutant->status(), [...$result->mutants()])
            : $result;
    }

    /**
     * A patched run of src/Money.php in which a test kills the mutants of lines 11 and 16, each in its own run
     * narrowed to a file of its own, and both survive with every test file; the tests of the narrowed files, alone on
     * the unmutated code, pass for these files only.
     */
    public static function narrowedKills(Project $at, string ...$passAlone): ShellFake
    {
        return new ShellFake(static function (Command $command) use ($at, $passAlone): Ran {
            $results = sprintf('%s', $command->environment()[GateVariable::Results->value] ?? '');
            $arguments = $command->arguments();

            if ($results === '') {
                return Ran::finished(succeeded: in_array(end($arguments), $passAlone, strict: true), output: 'the narrowed files alone');
            }

            $narrowed = ($command->environment()[GateVariable::Narrow->value] ?? false) === '1';
            $status = $narrowed ? PestStatus::Tested : PestStatus::Untested;
            $money = sprintf('%s/src/Money.php', $at->root());
            CoverageMaps::write(Recorder::coverageBeside($results), sprintf('%s/', $at->root()), ['src/Money.php' => [11 => [0], 16 => [0]]], [self::RUN_ADDS], []);
            PestRun::write($results, [
                PestRun::planned('n1', $money, 11, self::RUN_PLUS, 'return $a + $b;', 'return $a - $b;'),
                PestRun::planned('n2', $money, 16, self::RUN_PLUS, 'return $a + $c;', 'return $a - $c;'),
                PestRun::made(2),
                ...($narrowed ? [
                    PestRun::killed('n1', self::RUN_ADDS),
                    PestRun::narrowed('n1', [self::spec($at)]),
                    PestRun::killed('n2', self::RUN_ADDS),
                    PestRun::narrowed('n2', [sprintf('%s/tests/OtherSpec.php', $at->root())]),
                ] : []),
                PestRun::finished('n1', $status, 0.25),
                PestRun::finished('n2', $status, 0.25),
                PestRun::end(),
            ]);

            return Ran::finished(succeeded: true, output: sprintf('  Mutations: 2 %s', $status->value));
        });
    }

    /** The file a project's listing run names its tests in. */
    public static function names(Project $at): string
    {
        return sprintf('%s/.mutation-gate/pest/names.json', $at->root());
    }

    /** A listing run in which the plugin names a Pest test, rows and all, and a PHPUnit test of the same suite. */
    public static function named(Command $command, Project $at): Ran
    {
        $names = sprintf('%s', $command->environment()[GateVariable::Names->value] ?? '');
        file_put_contents($names, (string) json_encode([
            ['test' => 'P\\Tests\\MoneySpec::__pest_evaluable_it_adds', 'file' => sprintf('%s/tests/MoneySpec.php', $at->root()), 'description' => 'it adds'],
            ['test' => 'LegacySpec::decrements', 'file' => sprintf('%s/tests/LegacySpec.php', $at->root()), 'description' => 'decrements'],
            ['file' => 'tests/Broken.php'],
            'not a test',
        ]));

        return Ran::finished(succeeded: true, output: '   INFO  Available tests:');
    }

    /**
     * A patched run of src/Money.php in which RUN_ADDS kills the mutant of line 11, in its own run narrowed to
     * tests/MoneySpec.php and again with every test file, naming itself the killer, or naming none; every run of tests
     * outside a mutation run passes, but one with a file served through Pest's override fails where the tests are
     * sensitive to it, as a test that stats a dangling link through the override is.
     */
    public static function overrideSensitive(Project $at, bool $sensitive, bool $named = true): ShellFake
    {
        return new ShellFake(static function (Command $command) use ($at, $sensitive, $named): Ran {
            $results = sprintf('%s', $command->environment()[GateVariable::Results->value] ?? '');

            if ($results === '') {
                $served = ($command->environment()[Recorder::MUTATED] ?? false) !== false;

                return Ran::finished(succeeded: !$served || !$sensitive, output: 'the control');
            }

            $narrowed = ($command->environment()[GateVariable::Narrow->value] ?? false) === '1';
            CoverageMaps::write(Recorder::coverageBeside($results), sprintf('%s/', $at->root()), ['src/Money.php' => [11 => [0]]], [self::RUN_ADDS], []);
            PestRun::write($results, [
                PestRun::planned('n1', sprintf('%s/src/Money.php', $at->root()), 11, self::RUN_PLUS, 'return $a + $b;', 'return $a - $b;'),
                PestRun::made(1),
                ...($named ? [PestRun::killed('n1', self::RUN_ADDS)] : []),
                ...($narrowed ? [PestRun::narrowed('n1', [self::spec($at)])] : []),
                PestRun::finished('n1', PestStatus::Tested, 0.25),
                PestRun::end(),
            ]);

            return Ran::finished(succeeded: true, output: '  Mutations: 1 tested');
        });
    }

    /**
     * A shell whose narrowed run kills the one mutant of src/Money.php with the
     * run's first test, its order and arguments recorded, and whose replay of
     * that run, unmutated, runs that test in the same order or another.
     */
    public static function replayed(Project $at, bool $sameOrder): ShellFake
    {
        $order = OrderDigest::of(TestId::of(self::RUN_ADDS))->value();

        return new ShellFake(static function (Command $command) use ($at, $sameOrder, $order): Ran {
            $environment = $command->environment();
            $results = sprintf('%s', $environment[GateVariable::Results->value] ?? '');

            if (($environment[GateVariable::StopAfter->value] ?? false) !== false) {
                $copy = sprintf('%s', $environment[Recorder::MUTATED] ?? '');
                file_put_contents($results, sprintf(
                    '%s%s',
                    RecordLine::ran($copy, 1),
                    RecordLine::stopped($copy, 1, $sameOrder ? $order : OrderDigest::of(TestId::of('T::other'))->value()),
                ));

                return Ran::finished(succeeded: true, output: 'the replay');
            }

            CoverageMaps::write(Recorder::coverageBeside($results), sprintf('%s/', $at->root()), ['src/Money.php' => [11 => [0]]], [self::RUN_ADDS], []);
            PestRun::write($results, [
                PestRun::planned('n1', sprintf('%s/src/Money.php', $at->root()), 11, self::RUN_PLUS, 'return $a + $b;', 'return $a - $b;'),
                PestRun::made(1),
                PestRun::killedAt('n1', self::RUN_ADDS, Placed::at(1, $order, PestRun::RUN)),
                PestRun::narrowed('n1', [self::spec($at)]),
                rtrim(RecordLine::arguments(PestRun::mutated('n1'), ['vendor/bin/pest', '--bail', self::spec($at)])),
                PestRun::finished('n1', PestStatus::Tested, 0.25),
                PestRun::end(),
            ]);

            return Ran::finished(succeeded: true, output: '  Mutations: 1 tested');
        });
    }

    /**
     * The runs of tests a shell ran outside a mutation run: each control.
     *
     * @return list<Command>
     */
    public static function controls(ShellFake $shell): array
    {
        return array_values(array_filter(
            $shell->commands(),
            static fn(Command $command): bool => ($command->environment()[GateVariable::Results->value] ?? false) === false,
        ));
    }
}
