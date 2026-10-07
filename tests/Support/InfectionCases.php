<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_map;
use function array_slice;

use Closure;

use function dirname;
use function in_array;
use function iterator_to_array;
use function mb_strlen;
use function mb_substr;

use NightWorksIO\MutationGate\Adapter\Infection\Command;
use NightWorksIO\MutationGate\Adapter\Infection\Project;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\ExecutedMethod;
use NightWorksIO\MutationGate\Core\Coverage\Unplaced;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function realpath;
use function sprintf;
use function str_starts_with;

/**
 * Projects, shells and readings of runs the tests of the Infection adapter set up.
 *
 * @phpstan-import-type Lists from InfectionRun
 */
final readonly class InfectionCases
{
    /** A project in a scratch directory, with src/Money.php and its test, and the gate's directory in .gate. */
    public static function project(string $config = ''): Project
    {
        $root = (string) realpath(Scratch::directory());
        Scratch::write($root, 'src/Money.php', '<?php');
        Scratch::write($root, 'tests/MoneyTest.php', "<?php\nnamespace Tests;\nfinal class MoneyTest {}");

        if ($config !== '') {
            Scratch::write($root, 'infection.json5', $config);
        }

        return Project::at(Root::of($root), Paths::of(Path::of('tests')), Path::of('.gate'));
    }

    /**
     * A shell that answers as PHP, PHPUnit and Infection would: PHP describes
     * itself, a coverage run writes MoneyTest covering line 11 of src/Money.php,
     * and a run of Infection writes these lists of its logs.
     *
     * @param Lists $lists
     */
    public static function shell(Project $at, array $lists, bool $covers = true, bool $logs = true): InfectionShellFake
    {
        $running = self::running($at, $lists, $covers, $logs);

        return new InfectionShellFake(static fn(Command $command): Ran => in_array('-r', $command->arguments(), strict: true)
            ? Ran::finished(succeeded: true, output: Described::output())
            : $running($command));
    }

    /**
     * @param  Lists $lists
     * @return Closure(Command): Ran
     */
    public static function running(Project $at, array $lists, bool $covers, bool $logs): Closure
    {
        return static function (Command $command) use ($at, $lists, $covers, $logs): Ran {
            foreach ($command->arguments() as $argument) {
                if ($covers && str_starts_with($argument, '--log-junit=')) {
                    InfectionRun::coverage(
                        dirname(mb_substr($argument, mb_strlen('--log-junit='))),
                        $at->root(),
                        ['src/Money.php' => [11 => ['Tests\MoneyTest::adds']]],
                        ['Tests\MoneyTest' => 0.5],
                        ['Tests\MoneyTest::adds' => 0.5],
                    );
                }

                if ($logs && str_starts_with($argument, '--skip-initial-tests')) {
                    InfectionRun::log($at->own('logs/infection.json'), $lists);
                    InfectionRun::text($at->own('logs/infection.log'), []);
                }
            }

            return Ran::finished(succeeded: $covers, output: 'said');
        };
    }

    /**
     * Infection's lists with one kill, of line 11 of src/Money.php, whose
     * output names its killer as PHPUnit lists a failure.
     *
     * @return Lists
     */
    public static function killed(Project $at, string $mutator = 'Plus'): array
    {
        $entry = InfectionRun::entry($mutator, sprintf('%s/src/Money.php', $at->root()), 11, '$a + $b', '$a - $b');

        return ['killed' => [[...$entry, 'processOutput' => "There was 1 failure:\n\n1) Tests\\MoneyTest::adds\nFailed asserting that -1 is identical to 3.\n"]]];
    }

    /** @return list<list<string>> each command's arguments, without the PHP that runs it */
    public static function ran(InfectionShellFake $shell): array
    {
        return array_map(static fn(Command $command): array => array_slice($command->arguments(), 1), $shell->commands());
    }

    /** @return list<MutantStatus|CannotJudge> */
    public static function statuses(MutationResult|Mutants|CannotJudge $result): array
    {
        $mutants = $result instanceof MutationResult ? $result->mutants() : $result;

        return $mutants instanceof Mutants
            ? array_map(static fn(Mutant $mutant): MutantStatus => $mutant->status(), iterator_to_array($mutants, preserve_keys: false))
            : [$mutants];
    }

    /** The gate's own map another job handed on in a directory of a project: Money's line 11, which MoneyTest ran. */
    public static function handedOn(Project $at, string $directory): CoverageMap
    {
        $map = CoverageMap::empty()
            ->covered(Path::of('src/Money.php'), Line::of(11), TestId::of('Tests\MoneyTest::adds'))
            ->timed(TestId::of('Tests\MoneyTest::adds'), Seconds::of(0.5))
            ->executing(Path::of('src/Money.php'), ExecutedMethod::of('add', 9, 12));
        Scratch::write($at->root(), sprintf('%s/map.json.gz', $directory), CoverageMapFile::encode($map, Unplaced::map()));

        return $map;
    }

    /** A shell whose coverage run writes Money's line 11, which MoneyTest ran, and its line 12, which no test ran, and then does this to the directory. */
    public static function missing(Project $at, Closure $then): InfectionShellFake
    {
        return new InfectionShellFake(static function (Command $command) use ($at, $then): Ran {
            foreach ($command->arguments() as $argument) {
                if (str_starts_with($argument, '--log-junit=')) {
                    $directory = dirname(mb_substr($argument, mb_strlen('--log-junit=')));
                    InfectionRun::coverage(
                        $directory,
                        $at->root(),
                        ['src/Money.php' => [11 => ['Tests\MoneyTest::adds'], 12 => []]],
                        ['Tests\MoneyTest' => 0.5],
                        ['Tests\MoneyTest::adds' => 0.5],
                    );
                    $then($directory);
                }
            }

            return Ran::finished(succeeded: true, output: 'said');
        });
    }
}
