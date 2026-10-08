<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

use function dirname;
use function file_put_contents;
use function getenv;
use function getmypid;
use function ini_set;
use function is_string;
use function is_writable;

use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Core\Runner\InertSetting;
use PHPUnit\Event\EventFacadeIsSealedException;
use PHPUnit\Event\Facade;
use PHPUnit\Event\UnknownSubscriberTypeException;

/**
 * What the Pest plugin writes in a mutant's own process: whether the process
 * had loaded the original file before the mutant was in its place, each
 * test that fails or errors there, with where it stood in the order the
 * process started its tests in (see RunOrder), and how many tests it ran,
 * with the mutated copy Pest serves, one JSON line at a time in the results
 * file the process inherits. Pest stops the process at the first failure,
 * so the first killer line a mutant has names the test that killed it. It
 * logs PHP's errors in that process to a file of the mutant's own, which
 * the recorder reads for a fatal error Pest's own handling would lose, such
 * as running out of memory (ADR-0004, decision 9). A test or config that
 * sets `error_log` itself logs elsewhere.
 */
final readonly class Killers
{
    private function __construct(private string $results, private string $mutated, private RunOrder $order)
    {
    }

    /**
     * Naming killers where this is a mutant's own process of a run the
     * adapter records, given the files it loaded before Pest's override
     * started.
     *
     * @param list<string> $loaded
     */
    public static function fromEnvironment(array $loaded): self|Off
    {
        return self::listening(
            getenv(GateVariable::Results->value),
            getenv(Recorder::MUTATED),
            Facade::instance(),
            getenv(Recorder::MUTANT),
            Loaded::of($loaded),
            Heartbeat::onErrorOutput(),
        );
    }

    /**
     * Naming killers, counting the tests the process runs, and beating
     * through the heartbeat as its tests begin and as each finishes,
     * subscribed to PHPUnit's events, where a results file and a mutated copy
     * are named and PHPUnit still takes subscribers. A mutant it cannot name
     * a killer for is killed by a test nobody knows, and its run writes no
     * count. Where the process had loaded the original before the override
     * started, which then cannot put the mutant in its place, it writes that
     * first.
     */
    public static function listening(
        string|false $results,
        string|false $mutated,
        Facade $events,
        string|false $original,
        Loaded $loaded,
        Heartbeat $heartbeat,
        RunIssues $issues = new IssueKillers(),
    ): self|Off {
        if (! is_string($results) || $results === '' || ! is_string($mutated) || $mutated === '') {
            return Off::NamingKillers;
        }

        $killers = new self($results, $mutated, new RunOrder((int) getmypid()));

        if (is_string($original) && $loaded->has($original)) {
            file_put_contents($results, RecordLine::preloaded($mutated), FILE_APPEND | LOCK_EX);
        }

        try {
            $ran = new RanTests($results, $mutated);
            $events->registerSubscribers(
                new OnPreparationStarted($killers->order),
                new OnFailed($killers),
                new OnErrored($killers),
                new OnTestFinished($ran, $heartbeat),
                new OnExecutionStarted($heartbeat),
                new OnExecutionFinished($ran, $killers, $issues),
            );
        } catch (EventFacadeIsSealedException|UnknownSubscriberTypeException) {
            return Off::NamingKillers;
        }

        $killers->loggingErrors();

        return $killers;
    }

    /** Writes a test that failed an assertion, by its id, as the coverage map names it, and where it stood. */
    public function killedBy(string $test): void
    {
        $line = RecordLine::killed($this->mutated, $test, $this->order->placed($test));
        file_put_contents($this->results, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Writes each test that raised an issue the run fails on, where no test
     * failed or errored (see IssueKillers), by its id, and where it stood.
     */
    public function issuedBy(string ...$tests): void
    {
        foreach ($tests as $test) {
            $this->killedBy($test);
        }
    }

    /** Writes a test that errored, by its id, as the coverage map names it, and where it stood. */
    public function erroredBy(string $test): void
    {
        $line = RecordLine::errored($this->mutated, $test, $this->order->placed($test));
        file_put_contents($this->results, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Logs PHP's errors in this process to the mutant's own log, emptied of
     * any an earlier run left; where it cannot be emptied, logs nothing there.
     */
    private function loggingErrors(): void
    {
        $log = Recorder::errorsBeside($this->results, $this->mutated);

        if (! is_writable(dirname($log)) || file_put_contents($log, '') === false) {
            return;
        }

        ini_set(InertSetting::LogErrors->value, '1');
        ini_set(InertSetting::ErrorLog->value, $log);
    }
}
