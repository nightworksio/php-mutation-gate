<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use function intval;
use function is_string;

use NightWorksIO\MutationGate\Cli\Flow\Mode;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Plan\Cut;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Report\ProblemsShown;
use NightWorksIO\MutationGate\Core\Runner\CoverageRead;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
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

    public const string SHARDS = 'shards';

    public const string PLAN = 'plan';

    public const string SHARD = 'shard';

    public const string RESULTS = 'results';

    public const string OUTPUT = 'output';

    public const string ONLY = 'only';

    /** The option that has a command write what it would otherwise print: `baseline --write`, `stub --write`. */
    public const string WRITE = 'write';

    private const string OUTPUTS = '--output is console or problems, not "%s".';

    private const string ONLY_WHAT = '--only takes changed, not "%s".';

    private const string ONLY_WHERE = '--only=changed limits the problems output. Add --output=problems.';

    /** `plan`'s options, which the all-in-one run takes too. */
    public static function planning(Command $command): Command
    {
        return $command
            ->addOption(
                self::CHANGED_SINCE,
                mode: InputOption::VALUE_REQUIRED,
                description: 'Consider what changed since this ref, or since the last commit that passed',
            )
            ->addOption(self::FULL, mode: InputOption::VALUE_NONE, description: 'Consider every unit')
            ->addOption(
                self::COVERAGE,
                mode: InputOption::VALUE_REQUIRED,
                description: 'Read the coverage map an earlier job left here, instead of running the suite',
            )
            ->addOption(self::SHARDS, mode: InputOption::VALUE_REQUIRED, description: 'Cut exactly this many shards');
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

    public static function mode(InputInterface $input): Mode|CannotJudge
    {
        $since = self::text($input, self::CHANGED_SINCE);
        $full = $input->hasOption(self::FULL) && $input->getOption(self::FULL) === true;

        return match (true) {
            $full && $since !== '' => CannotJudge::because(
                '--full and --changed-since ask for different runs. Give one of them.',
            ),
            $since !== '' => Mode::since($since),
            default => Mode::full(),
        };
    }

    /** Whether the options ask for a full run: no `--changed-since`. */
    public static function isFull(InputInterface $input): bool
    {
        return self::text($input, self::CHANGED_SINCE) === '';
    }

    public static function coverage(InputInterface $input): CoverageRun|CoverageRead
    {
        $from = self::text($input, self::COVERAGE);

        return $from === ''
            ? CoverageRun::of(WholeSuite::tests(), Workspace::coverage())
            : CoverageRead::from(Path::of($from));
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
            : Cut::bySize((int) $shards->seconds()->seconds(), $shards->max());
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
