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
 * What the Pest plugin writes in a mutant's own process: each test that fails
 * or errors there, with the mutated copy Pest serves, one JSON line at a time
 * in the results file the process inherits. Pest stops the process at the
 * first, so the first line a mutant has names the test that killed it. It
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

    /** Naming killers where this is a mutant's own process of a run the adapter records. */
    public static function fromEnvironment(): self|Off
    {
        return self::listening(getenv(GateVariable::Results->value), getenv(Recorder::MUTATED), Facade::instance());
    }

    /**
     * Naming killers, subscribed to PHPUnit's events, where a results file and
     * a mutated copy are named and PHPUnit still takes subscribers. A mutant
     * it cannot name a killer for is killed by a test nobody knows.
     */
    public static function listening(string|false $results, string|false $mutated, Facade $events): self|Off
    {
        if (! is_string($results) || $results === '' || ! is_string($mutated) || $mutated === '') {
            return Off::NamingKillers;
        }

        $killers = new self($results, $mutated);

        try {
            $events->registerSubscribers(new OnFailed($killers), new OnErrored($killers));
        } catch (EventFacadeIsSealedException|UnknownSubscriberTypeException) {
            return Off::NamingKillers;
        }

        $killers->loggingErrors();

        return $killers;
    }

    /** Writes a test that failed or errored, by its id, as the coverage map names it. */
    public function killedBy(string $test): void
    {
        file_put_contents($this->results, RecordLine::killed($this->mutated, $test), FILE_APPEND | LOCK_EX);
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
