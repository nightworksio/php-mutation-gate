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
use NightWorksIO\MutationGate\Core\Runner\CoverageRequest;
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

    public static function coverage(InputInterface $input): CoverageRequest
    {
        $from = self::text($input, self::COVERAGE);

        return $from === ''
            ? CoverageRequest::running(WholeSuite::tests(), Workspace::coverage())
            : CoverageRequest::reading(Path::of($from));
    }

    public static function cut(InputInterface $input, Settings $settings): Cut|CannotJudge
    {
        $shards = self::text($input, self::SHARDS);
        $target = $settings->shards()->target();

        return match (true) {
            $shards === '' && $target instanceof Seconds => Cut::toTarget(
                $target,
                $settings->shards()->setup(),
                $settings->shards()->max(),
            ),
            $shards === '' => Cut::bySize(
                (int) $settings->shards()->seconds()->seconds(),
                $settings->shards()->max(),
            ),
            WholeNumber::isPositive($shards) => Cut::exactly(intval($shards)),
            default => CannotJudge::because(sprintf('--shards=%s is not a number of shards.', $shards)),
        };
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
