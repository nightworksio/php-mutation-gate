<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use function array_key_exists;
use function intval;
use function is_string;

use NightWorksIO\MutationGate\Cli\Flow\Composed;
use NightWorksIO\MutationGate\Cli\Flow\Mode;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\Cut;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Report\ProblemsShown;
use NightWorksIO\MutationGate\Core\Runner\CoverageRan;
use NightWorksIO\MutationGate\Core\Runner\CoverageRead;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Test\SuiteName;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\WholeNumber;

use function sprintf;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/** The options the flows take on the command line, declared once and read into what each flow asks for. */
final readonly class FlowOptions
{
    public const string CHANGED_SINCE = 'changed-since';

    public const string FULL = 'full';

    public const string COVERAGE = 'coverage';

    /** The option naming the directory a command reads what an earlier step left from. */
    public const string FROM = 'from';

    public const string SHARDS = 'shards';

    public const string KILL_MATRIX = 'kill-matrix';

    public const string PLAN = 'plan';

    public const string SHARD = 'shard';

    public const string RESULTS = 'results';

    public const string OUTPUT = 'output';

    public const string ONLY = 'only';

    /** The option that has `plan` and `run` make mutants with the security mutators alone (ADR-0021, decision 20). */
    public const string SECURITY = 'security';

    /** The option that has `plan` and `run` judge the mutants by one suite's tests alone (ADR-0025, decision 9). */
    public const string SUITE = 'suite';

    /** The option that has a command write what it would otherwise print: `baseline --write`, `stub --write`. */
    public const string WRITE = 'write';

    /** What `--kill-matrix` takes, each for the kind of kill matrix it asks for. */
    private const array MATRICES = ['first' => MatrixKind::FirstKiller, 'full' => MatrixKind::Full];

    private const string SUITE_WITH_COVERAGE = <<<'SAID'
        --suite runs the coverage run with one suite's tests, so it takes no --coverage.
        The map --coverage names may hold every suite's tests.
        SAID;

    private const string OUTPUTS = '--output is console or problems, not "%s".';

    private const string ONLY_WHAT = '--only takes changed, not "%s".';

    private const string MATRIX_SAID = <<<'SAID'
        Record the first test that kills each mutant (first, the default), or every test that does (full)
        SAID;

    private const string MATRIX_WHAT = '--kill-matrix takes first or full, not "%s".';

    private const string ONLY_WHERE = '--only=changed limits the problems output. Add --output=problems.';

    /** `plan`'s options, which the all-in-one run takes too. */
    public static function planning(Command $command): Command
    {
        return $command
            ->addOption(
                self::CHANGED_SINCE,
                mode: InputOption::VALUE_REQUIRED,
                description: 'Consider what changed since this ref, last-passed (the default) or last-run',
            )
            ->addOption(self::FULL, mode: InputOption::VALUE_NONE, description: 'Consider every unit')
            ->addOption(
                self::COVERAGE,
                mode: InputOption::VALUE_REQUIRED,
                description: 'Read the coverage map an earlier job left here, instead of running the suite',
            )
            ->addOption(self::SHARDS, mode: InputOption::VALUE_REQUIRED, description: 'Cut exactly this many shards')
            ->addOption(
                self::KILL_MATRIX,
                mode: InputOption::VALUE_REQUIRED,
                description: self::MATRIX_SAID,
            )
            ->addOption(
                self::SECURITY,
                mode: InputOption::VALUE_NONE,
                description: 'Make mutants with the security mutators alone, and judge only the security sets',
            )
            ->addOption(
                self::SUITE,
                mode: InputOption::VALUE_REQUIRED,
                description: 'Judge the mutants by this test suite\'s tests alone, and hold no floor',
            );
    }

    /** Whether the options ask for mutants made with the security mutators alone: `--security`. */
    public static function isSecurityOnly(InputInterface $input): bool
    {
        return $input->getOption(self::SECURITY) === true;
    }

    /** Whether the options ask for one suite's tests alone to judge the mutants: `--suite`. */
    public static function isSuiteOnly(InputInterface $input): bool
    {
        return self::text($input, self::SUITE) !== '';
    }

    /**
     * What the flow runs with, narrowed to the security mutators (ADR-0021,
     * decision 20) and to one suite's tests (ADR-0025, decision 9) where the
     * options ask for it, or why it cannot run.
     */
    public static function narrowed(
        Composed|Invalid|CannotJudge $composed,
        InputInterface $input,
    ): Composed|Invalid|CannotJudge {
        $suite = self::text($input, self::SUITE);
        $secured = $composed instanceof Composed && self::isSecurityOnly($input)
            ? $composed->securityOnly()
            : $composed;

        return match (true) {
            ! $secured instanceof Composed || $suite === '' => $secured,
            self::text($input, self::COVERAGE) !== '' => CannotJudge::because(self::SUITE_WITH_COVERAGE),
            default => $secured->inSuite(SuiteName::of($suite)),
        };
    }

    /**
     * The options that print a verdict for an editor, which `run`, `watch`
     * and `pre-push` take (ADR-0015, decision 6).
     */
    public static function editing(Command $command): Command
    {
        return $command
            ->addOption(
                self::OUTPUT,
                mode: InputOption::VALUE_REQUIRED,
                description: 'Print the verdict as console, or as problems: one line per result, for editors',
            )
            ->addOption(
                self::ONLY,
                mode: InputOption::VALUE_REQUIRED,
                description: 'With --output=problems, print only the results on changed lines: changed',
            );
    }

    /** How the options ask for the verdict to be printed. */
    public static function printing(InputInterface $input): Printing|CannotJudge
    {
        $asked = self::text($input, self::OUTPUT);
        $output = VerdictOutput::tryFrom($asked === '' ? VerdictOutput::Console->value : $asked);
        $only = self::text($input, self::ONLY);

        return match (true) {
            ! $output instanceof VerdictOutput => CannotJudge::because(sprintf(self::OUTPUTS, $asked)),
            $only === '' => Printing::of($output, ProblemsShown::All),
            $only !== ProblemsShown::Changed->value => CannotJudge::because(sprintf(self::ONLY_WHAT, $only)),
            $output !== VerdictOutput::Problems => CannotJudge::because(self::ONLY_WHERE),
            default => Printing::of($output, ProblemsShown::Changed),
        };
    }

    /**
     * The run mode the options ask for: `--full`, or `--changed-since`; with
     * neither, every unit where `run.full` asks for it, and otherwise what
     * changed since the scope's last passing commit (ADR-0005, decision 2).
     */
    public static function mode(InputInterface $input, Settings $settings): Mode|CannotJudge
    {
        $since = self::text($input, self::CHANGED_SINCE);
        $full = $input->hasOption(self::FULL) && $input->getOption(self::FULL) === true;

        return match (true) {
            $full && $since !== '' => CannotJudge::because(
                '--full and --changed-since ask for different runs. Give one of them.',
            ),
            $full => Mode::full(),
            $since !== '' => Mode::since($since),
            $settings->reach()->isFullByDefault() => Mode::full(),
            default => Mode::since(Mode::LAST_PASSED),
        };
    }

    /** How much of the kill matrix the options ask the run to record: first killers, unless `--kill-matrix=full`. */
    public static function killMatrix(InputInterface $input): MatrixKind|CannotJudge
    {
        $asked = self::text($input, self::KILL_MATRIX);

        return match (true) {
            $asked === '' => MatrixKind::FirstKiller,
            array_key_exists($asked, self::MATRICES) => self::MATRICES[$asked],
            default => CannotJudge::because(sprintf(self::MATRIX_WHAT, $asked)),
        };
    }

    /** Whether the options, with `run.full`, ask for a full run. */
    public static function isFull(InputInterface $input, Settings $settings): bool
    {
        $mode = self::mode($input, $settings);

        return $mode instanceof Mode && $mode->isFull();
    }

    public static function coverage(InputInterface $input): CoverageRun|CoverageRead
    {
        $from = self::text($input, self::COVERAGE);

        return $from === ''
            ? CoverageRun::of(WholeSuite::tests(), Workspace::coverage())
            : CoverageRead::from(Path::of($from));
    }

    /** The reports this job's own coverage run left where `--from` says; none where it says nowhere. */
    public static function coverageRan(InputInterface $input): CoverageRan|NotGiven
    {
        $from = self::text($input, self::FROM);

        return $from === '' ? NotGiven::value() : CoverageRan::in(Path::of($from));
    }

    public static function cut(InputInterface $input, Settings $settings): Cut|CannotJudge
    {
        $shards = self::text($input, self::SHARDS);

        return match (true) {
            $shards === '' => self::configuredCut($settings),
            WholeNumber::isPositive($shards) => Cut::exactly(intval($shards)),
            default => CannotJudge::because(sprintf('--shards=%s is not a number of shards.', $shards)),
        };
    }

    /** The cut the config asks for: to `shards.target`, where it sets one, or by `shards.seconds`. */
    public static function configuredCut(Settings $settings): Cut
    {
        $shards = $settings->shards();
        $target = $shards->target();

        return $target instanceof Seconds
            ? Cut::toTarget($target, $shards->setup(), $shards->max())
            : Cut::bySize((int) $shards->seconds()->seconds(), $shards->setup(), $shards->max());
    }

    /** The shard `--shard` names, or none, when the CI's environment names it. */
    public static function shard(InputInterface $input): ShardId|Absent|CannotJudge
    {
        $shard = self::text($input, self::SHARD);

        return match (true) {
            $shard === '' => Absent::setting(),
            WholeNumber::isPositive($shard) => ShardId::of(intval($shard)),
            default => CannotJudge::because(sprintf('--shard=%s is not a shard number.', $shard)),
        };
    }

    /** A path an option names, or this one where it names none. */
    public static function path(InputInterface $input, string $option, Path $otherwise): Path
    {
        $path = self::text($input, $option);

        return $path === '' ? $otherwise : Path::of($path);
    }

    private static function text(InputInterface $input, string $option): string
    {
        $value = $input->hasOption($option) ? $input->getOption($option) : '';

        return is_string($value) ? $value : '';
    }
}
