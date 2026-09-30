<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use const FILE_APPEND;

use function file_put_contents;
use function is_string;

use const LOCK_EX;

use PHPUnit\Event\EventFacadeIsSealedException;
use PHPUnit\Event\Facade;
use PHPUnit\Event\UnknownSubscriberTypeException;

/**
 * Appends to the results file each test that starts, before anything of it
 * runs, and each test's outcome as it finishes. A test that started and never
 * finished is one whose process died while it ran. The outcome is forgotten
 * as each test starts and finishes, so none is written against another test.
 */
final class Recorder
{
    private Outcome $outcome = Outcome::Neither;

    private function __construct(private readonly string $results)
    {
    }

    /**
     * Records to a results file, subscribed to PHPUnit's events, where a file
     * is named and PHPUnit still takes subscribers; otherwise records nothing,
     * and the gate reads the mutant's run as one no test ran in.
     */
    public static function listening(string|false $results, Facade $events): void
    {
        if (! is_string($results) || $results === '') {
            return;
        }

        $recorder = new self($results);

        try {
            $events->registerSubscribers(
                new OnStarted($recorder),
                new OnPassed($recorder),
                new OnFailed($recorder),
                new OnErrored($recorder),
                new OnFinished($recorder),
            );
        } catch (EventFacadeIsSealedException|UnknownSubscriberTypeException) {
            return;
        }
    }

    public function started(string $test): void
    {
        $this->outcome = Outcome::Neither;
        $this->write(Outcome::Started->line($test));
    }

    public function ended(Outcome $outcome): void
    {
        $this->outcome = $outcome;
    }

    public function finished(string $test): void
    {
        $this->write($this->outcome->line($test));
        $this->outcome = Outcome::Neither;
    }

    private function write(string $line): void
    {
        file_put_contents($this->results, $line, FILE_APPEND | LOCK_EX);
    }
}
