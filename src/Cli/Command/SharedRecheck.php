<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use function count;
use function is_string;

use NightWorksIO\MutationGate\Cli\Flow\Composed;
use NightWorksIO\MutationGate\Cli\Flow\Rechecking;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Recheck\NoRecheck;
use NightWorksIO\MutationGate\Core\Recheck\RecheckJobs;
use NightWorksIO\MutationGate\Core\Recheck\RecheckShare;
use NightWorksIO\MutationGate\Core\Recheck\ShareOf;

use function sprintf;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A re-check of the last run's survivors shared among jobs (ADR-0106):
 * `survivors --jobs` prints the jobs a CI's matrix runs it in, as
 * {@see RecheckJobs} writes them, and re-checks nothing; `--share=<i>
 * --of=<n>` re-checks one job's share of the files and leaves what it made
 * in `.mutation-gate/survivors/<i>.json`; and `--from=<directory> --of=<n>`
 * pairs the survivors with what the shares left there, a share that left
 * nothing not reached, and reports them as one re-check does.
 *
 * @internal
 */
final readonly class SharedRecheck
{
    private const string JOBS = 'jobs';

    private const string SHARE = 'share';

    private const string OF = 'of';

    private const string WROTE = 'Share %d of the re-check: files run again %d, not reached %d. Wrote %s.';

    public static function options(Command $command): Command
    {
        return $command
            ->addOption(
                self::JOBS,
                mode: InputOption::VALUE_NONE,
                description: 'Print the jobs a CI shares the re-check among, as JSON, and re-check nothing',
            )
            ->addOption(
                self::SHARE,
                mode: InputOption::VALUE_REQUIRED,
                description: 'Re-check this share of the files, counted from 1, and leave what it made for --from',
            )
            ->addOption(self::OF, mode: InputOption::VALUE_REQUIRED, description: 'How many jobs share the re-check')
            ->addOption(
                FlowOptions::FROM,
                mode: InputOption::VALUE_REQUIRED,
                description: 'Report the survivors with what the shares left in this directory',
            );
    }

    /** Whether the options ask for a part of a shared re-check rather than the whole of it. */
    public static function isAsked(InputInterface $input): bool
    {
        return $input->getOption(self::JOBS) === true
            || self::text($input, self::SHARE) !== ''
            || self::text($input, FlowOptions::FROM) !== '';
    }

    /**
     * What the part the options ask for says, a line each; or why it cannot
     * be done. Where nothing reads the re-checked state, no job runs it and
     * a share or the pairing re-checks nothing.
     *
     * @return list<string>|CannotJudge
     */
    public static function said(
        Composed $composed,
        Plan $plan,
        InputInterface $input,
        OutputInterface $aside,
        bool $isRead,
    ): array|CannotJudge {
        if (! $isRead) {
            return $input->getOption(self::JOBS) === true
                ? [RecheckJobs::none($composed->settings->triage()->survivorsFirst())->encode()]
                : [SurvivorsCommand::UNREAD];
        }

        $rechecking = new Rechecking($composed->adapters, $composed->settings, $composed->setup);
        $from = self::text($input, FlowOptions::FROM);
        $of = self::text($input, self::OF);

        return match (true) {
            $input->getOption(self::JOBS) === true => [self::jobs($composed, $rechecking->jobs($plan), $aside)],
            $from !== '' => self::combined($composed, $plan, $rechecking, Path::of($from), ShareOf::every($of)),
            default => self::shared($composed, $rechecking, $plan, self::share($input, $of)),
        };
    }

    /** The jobs as JSON; one where the survivors cannot be told, and why aside. */
    private static function jobs(Composed $composed, RecheckJobs|CannotJudge $jobs, OutputInterface $aside): string
    {
        if ($jobs instanceof CannotJudge) {
            $aside->writeln(sprintf(SurvivorsCommand::UNCHECKED, $jobs->why()), OutputInterface::OUTPUT_RAW);
        }

        $first = $composed->settings->triage()->survivorsFirst();

        return ($jobs instanceof RecheckJobs ? $jobs : RecheckJobs::unsure($first))->encode();
    }

    /** @return list<string>|CannotJudge */
    private static function shared(
        Composed $composed,
        Rechecking $rechecking,
        Plan $plan,
        ShareOf|CannotJudge $share,
    ): array|CannotJudge {
        if ($share instanceof CannotJudge) {
            return $share;
        }

        $ran = $rechecking->share($plan, $share);

        if (! $ran instanceof RecheckShare) {
            return $ran instanceof NoRecheck ? [$ran->why()] : $ran;
        }

        $file = Workspace::survivorShare(Workspace::survivors(), $share);
        $written = $composed->adapters->project->write($file, Contents::of($ran->encode()));

        return $written instanceof CannotJudge ? $written : [sprintf(
            self::WROTE,
            $share->share(),
            count($ran->ran()),
            count($ran->unreached()),
            $file->value(),
        )];
    }

    /**
     * @param  list<ShareOf>|CannotJudge $shares
     * @return list<string>|CannotJudge
     */
    private static function combined(
        Composed $composed,
        Plan $plan,
        Rechecking $rechecking,
        Path $from,
        array|CannotJudge $shares,
    ): array|CannotJudge {
        if ($shares instanceof CannotJudge) {
            return $shares;
        }

        $left = RecheckShare::none();

        foreach ($shares as $share) {
            $contents = $composed->adapters->project->read(Workspace::survivorShare($from, $share));
            $read = $contents instanceof Contents ? RecheckShare::decode($contents->text()) : RecheckShare::none();
            $left = $left->and($read);
        }

        $rechecked = $rechecking->combined($plan, $left);

        return $rechecked instanceof CannotJudge
            ? $rechecked
            : SurvivorsCommand::reported($composed, $plan, $rechecked);
    }

    private static function share(InputInterface $input, string $of): ShareOf|CannotJudge
    {
        return ShareOf::parse(self::text($input, self::SHARE), $of);
    }

    private static function text(InputInterface $input, string $option): string
    {
        $value = $input->getOption($option);

        return is_string($value) ? $value : '';
    }
}
