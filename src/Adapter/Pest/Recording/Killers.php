<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

use function dirname;
use function file_put_contents;
use function getenv;
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
 * test that fails or errors there, and how many tests it ran, one line at a
 * time in the mutant's own killer file beside the results file the process
 * inherits, which Pest's own process takes once the mutant has ended (see
 * KillerFile). Pest stops the process at the first failure, so the first
 * test a mutant has names the one that killed it. It
 * logs PHP's errors in that process to a file of the mutant's own, which the
 * recorder reads for a fatal error Pest's own handling would lose, such as
 * running out of memory (ADR-0004, decision 9). A test or config that sets
 * `error_log` itself logs elsewhere.
 */
final readonly class Killers
{
    private function __construct(private string $results, private string $mutated)
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
        );
    }

    /**
     * Naming killers, and counting the tests the process runs, subscribed to
     * PHPUnit's events, where a results file and a mutated copy are named and
     * PHPUnit still takes subscribers. A mutant it cannot name a killer for
     * is killed by a test nobody knows, and its run writes no count. Where
     * the process had loaded the original before the override started,
     * which then cannot put the mutant in its place, it writes that first.
     */
    public static function listening(
        string|false $results,
        string|false $mutated,
        Facade $events,
        string|false $original,
        Loaded $loaded,
    ): self|Off {
        if (! is_string($results) || $results === '' || ! is_string($mutated) || $mutated === '') {
            return Off::NamingKillers;
        }

        $killers = new self($results, $mutated);

        if (is_string($original) && $loaded->has($original)) {
            $killers->append(KillerFile::preloaded());
        }

        try {
            $ran = new RanTests($results, $mutated);
            $events->registerSubscribers(
                new OnFailed($killers),
                new OnErrored($killers),
                new OnTestFinished($ran),
                new OnExecutionFinished($ran),
            );
        } catch (EventFacadeIsSealedException|UnknownSubscriberTypeException) {
            return Off::NamingKillers;
        }

        $killers->loggingErrors();

        return $killers;
    }

    /** Writes a test that failed an assertion, by its id, as the coverage map names it. */
    public function killedBy(string $test): void
    {
        $this->write(RecordEvent::Killed, $test);
    }

    /** Writes a test that errored, by its id, as the coverage map names it. */
    public function erroredBy(string $test): void
    {
        $this->write(RecordEvent::Errored, $test);
    }

    /** Writes a test to the mutant's killer file, by the record Pest's own process writes for it. */
    private function write(RecordEvent $event, string $test): void
    {
        $this->append(KillerFile::line($event, $test));
    }

    private function append(string $line): void
    {
        file_put_contents(KillerFile::beside($this->results, $this->mutated), $line, FILE_APPEND | LOCK_EX);
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
