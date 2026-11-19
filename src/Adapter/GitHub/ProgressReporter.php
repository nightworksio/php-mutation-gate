<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitHub;

use Closure;

use function is_file;
use function is_string;
use function min;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Http\Exchange;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Runner\Forking;
use NightWorksIO\MutationGate\Core\Runner\Gauge;
use NightWorksIO\MutationGate\Core\Runner\ProcessLog;
use NightWorksIO\MutationGate\Core\Runner\ProcessUse;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function pcntl_async_signals;
use function pcntl_fork;
use function pcntl_signal;
use function pcntl_waitpid;
use function posix_getpid;
use function posix_getppid;
use function posix_kill;

use Psr\Clock\ClockInterface;

use function putenv;

use const SIGKILL;
use const SIGTERM;

use function sprintf;

use Symfony\Component\Process\Process;

use function sys_get_temp_dir;
use function tempnam;
use function unlink;
use function usleep;

use const WNOHANG;

/**
 * A CI shard's progress, left as an artifact of the run every
 * `shards.progress` from the moment the shard starts, by a process of its
 * own forked from the gate's, which reaches the artifact service the gate
 * was handed over an exchange of its own and outlives the gate by a second
 * at most. Stopped with the
 * shard, it deletes the artifact it left, which only a lost job keeps
 * (ADR-0157).
 *
 * @internal
 */
final readonly class ProgressReporter
{
    /** How long the gate waits, once the shard ends, for its reporter to delete its artifact. */
    private const float GRACE = 10.0;

    /** The longest the reporter sleeps between two looks at whether the gate still runs. */
    private const float NAP = 1.0;

    /** How long the gate sleeps between two looks at whether its reporter ended. */
    private const float LOOK = 0.05;

    private const string KEPT = 'mutation-gate-said-';

    private function __construct(private int|NotGiven $reporter, private string $said, private Seconds $grace)
    {
    }

    /** No progress: a shard outside CI, without a service, or with `shards.progress: 0s`. */
    public static function none(): self
    {
        return new self(NotGiven::value(), '', Seconds::of(self::GRACE));
    }

    /**
     * The progress of this shard, left through the service the gate was handed, every so long.
     *
     * @param Closure(int): void $ending how the reporter ends itself, by a signal
     */
    public static function started(
        Seconds $every,
        ArtifactAccess|CannotJudge|NotGiven $service,
        Variables $environment,
        Digest $plan,
        ShardId $shard,
        ClockInterface $clock,
        Gauge $gauge,
        Exchange $exchange,
        Closure $ending,
    ): self {
        return self::startedWith(
            $every,
            $service,
            $environment,
            $plan,
            $shard,
            $clock,
            $gauge,
            $exchange,
            $ending,
            static function (array $command): string {
                $process = new Process($command);
                $process->run();

                return $process->getOutput();
            },
            Seconds::of(self::GRACE),
        );
    }

    /**
     * @param Closure(int): void            $ending how the reporter ends itself, by a signal
     * @param Closure(list<string>): string $run    runs a command, and answers what it printed
     * @param Seconds                       $grace  how long the gate waits for its reporter once the shard ends
     */
    public static function startedWith(
        Seconds $every,
        ArtifactAccess|CannotJudge|NotGiven $service,
        Variables $environment,
        Digest $plan,
        ShardId $shard,
        ClockInterface $clock,
        Gauge $gauge,
        Exchange $exchange,
        Closure $ending,
        Closure $run,
        Seconds $grace,
    ): self {
        $said = $service instanceof ArtifactAccess && $every->seconds() > 0 && Forking::isAvailable()
            ? tempnam(sys_get_temp_dir(), self::KEPT)
            : false;

        if (! $service instanceof ArtifactAccess || ! is_string($said)) {
            return self::none();
        }

        putenv(sprintf('%s=%s', ProcessLog::KEPT, $said));
        $gate = posix_getpid();
        $reporter = pcntl_fork();

        if ($reporter === 0) {
            try {
                self::report(
                    $every,
                    $service,
                    $exchange,
                    $clock,
                    $gate,
                    static fn(RunArtifacts $artifacts): ProgressArtifacts => ProgressArtifacts::of(
                        $artifacts,
                        $plan,
                        RunArtifacts::attempt($environment),
                        $shard,
                    ),
                    ProgressReport::of($shard->number(), $gauge, $said),
                    $run,
                );
            } finally {
                $ending(SIGKILL);
            }
        }

        return $reporter > 0
            ? new self($reporter, $said, $grace)
            : new self(NotGiven::value(), $said, $grace)->stopped();
    }

    /** The reporter stopped, which deletes its artifact, killed where it takes too long, and the kept lines let go. */
    public function stop(): void
    {
        $this->stopped();
    }

    private function stopped(): self
    {
        if ($this->reporter instanceof NotGiven) {
            return $this->letGo();
        }

        posix_kill($this->reporter, SIGTERM);
        $waited = 0.0;

        while (pcntl_waitpid($this->reporter, $status, WNOHANG) === 0 && $waited < $this->grace->seconds()) {
            usleep((int) (self::LOOK * Seconds::MICROSECONDS));
            $waited += self::LOOK;
        }

        if ($waited >= $this->grace->seconds()) {
            posix_kill($this->reporter, SIGKILL);
            pcntl_waitpid($this->reporter, $status);
        }

        return $this->letGo();
    }

    private function letGo(): self
    {
        putenv(ProcessLog::KEPT);

        if ($this->said !== '' && is_file($this->said)) {
            unlink($this->said);
        }

        return $this;
    }

    /**
     * The reporter's loop: a report left at once and then every so long,
     * until the gate is gone or stops it; stopped, it deletes what it left.
     *
     * @param Closure(RunArtifacts): ProgressArtifacts $artifacts
     * @param Closure(list<string>): string              $run
     */
    private static function report(
        Seconds $every,
        ArtifactAccess $service,
        Exchange $exchange,
        ClockInterface $clock,
        int $gate,
        Closure $artifacts,
        ProgressReport $report,
        Closure $run,
    ): void {
        $stopped = false;
        pcntl_async_signals(enable: true);
        pcntl_signal(SIGTERM, static function () use (&$stopped): void {
            $stopped = true;
        });
        $reached = RunArtifacts::of($service, $exchange->forked());

        if ($reached instanceof CannotJudge) {
            return;
        }

        $left = $artifacts($reached);
        $started = (float) $clock->now()->format('U.u');
        $next = $started;

        while (! $stopped && posix_getppid() === $gate) {
            $now = (float) $clock->now()->format('U.u');

            if ($now >= $next) {
                $left->leave($report->at(Seconds::of($now - $started), $run(ProcessUse::LISTING)));
                $next += $every->seconds();
            }

            usleep((int) (min(self::NAP, $every->seconds()) * Seconds::MICROSECONDS));
        }

        if ($stopped) {
            $left->clear();
        }
    }
}
