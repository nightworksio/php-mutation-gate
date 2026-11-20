<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitHub;

use Closure;

use function getenv;
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
use NightWorksIO\MutationGate\Core\Runner\RunningProcesses;
use NightWorksIO\MutationGate\Core\Runner\SparedProcesses;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function pcntl_async_signals;
use function pcntl_fork;
use function pcntl_signal;
use function pcntl_waitpid;
use function posix_getpid;
use function posix_getppid;

use Psr\Clock\ClockInterface;

use function putenv;

use const SIGKILL;
use const SIGTERM;

use function sprintf;
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

    /**
     * @param array{int, RunningProcesses}|NotGiven $reporter the reporter's process, and the processes it is
     *                                                  stopped among
     */
    private function __construct(private array|NotGiven $reporter, private string $said, private Seconds $grace)
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
     * @param Closure(int): never $ending how the reporter ends itself, by a signal
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
        RunningProcesses $processes,
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
            $processes,
            Seconds::of(self::GRACE),
        );
    }

    /**
     * @param Closure(int): never $ending how the reporter ends itself, by a signal
     * @param Seconds             $grace  how long the gate waits for its reporter once the shard ends
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
        RunningProcesses $processes,
        Seconds $grace,
    ): self {
        $reached = $service instanceof ArtifactAccess ? RunArtifacts::of($service, $exchange) : $service;
        $said = $reached instanceof RunArtifacts && $every->seconds() > 0 && Forking::isAvailable()
            ? tempnam(sys_get_temp_dir(), self::KEPT)
            : false;

        if (! $reached instanceof RunArtifacts || ! is_string($said)) {
            return self::none();
        }

        putenv(sprintf('%s=%s', ProcessLog::KEPT, $said));
        $gate = posix_getpid();
        $reporter = pcntl_fork();

        if ($reporter === 0) {
            try {
                self::report(
                    $every,
                    $reached->forked(),
                    $clock,
                    $gate,
                    static fn(RunArtifacts $artifacts): ProgressArtifacts => ProgressArtifacts::of(
                        $artifacts,
                        $plan,
                        RunArtifacts::attempt($environment),
                        $shard,
                    ),
                    ProgressReport::of($shard->number(), $gauge, $said),
                    $processes,
                );
            } finally {
                $ending(SIGKILL);
            }
        }

        return $reporter > 0
            ? new self([$reporter, $processes], $said, $grace)
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

        [$reporter, $processes] = $this->reporter;
        $spared = SparedProcesses::toldOr($this->told(), $processes);
        $signals = $spared->signal($reporter, SIGTERM);
        $waited = 0.0;

        while (pcntl_waitpid($reporter, $status, WNOHANG) === 0 && $waited < $this->grace->seconds()) {
            usleep((int) (self::LOOK * Seconds::MICROSECONDS));
            $waited += self::LOOK;
        }

        if ($signals && $waited >= $this->grace->seconds()) {
            $spared->signal($reporter, SIGKILL);
            pcntl_waitpid($reporter, $status);
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
     */
    private static function report(
        Seconds $every,
        RunArtifacts $reached,
        ClockInterface $clock,
        int $gate,
        Closure $artifacts,
        ProgressReport $report,
        RunningProcesses $processes,
    ): void {
        $stopped = false;
        pcntl_async_signals(enable: true);
        pcntl_signal(SIGTERM, static function () use (&$stopped): void {
            $stopped = true;
        });
        $left = $artifacts($reached);
        $started = (float) $clock->now()->format('U.u');
        $next = $started;

        while (! $stopped && posix_getppid() === $gate) {
            $now = (float) $clock->now()->format('U.u');

            if ($now >= $next) {
                $left->leave($report->at(Seconds::of($now - $started), $processes->listed()));
                $next += $every->seconds();
            }

            usleep((int) (min(self::NAP, $every->seconds()) * Seconds::MICROSECONDS));
        }

        if ($stopped) {
            $left->clear();
        }
    }

    /** What the gate's own process was told it spares; nothing where it was told none. */
    private function told(): string
    {
        $told = getenv(SparedProcesses::VARIABLE);

        return is_string($told) ? $told : '';
    }
}
