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

use function rawurlencode;
use function sprintf;

/**
 * Appends each test's outcome to the results file as the test finishes: the
 * outcome, a space, and the test's id, encoded so a data set's name that holds
 * a space or a line break stays on its line.
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
                new OnPassed($recorder),
                new OnFailed($recorder),
                new OnErrored($recorder),
                new OnFinished($recorder),
            );
        } catch (EventFacadeIsSealedException|UnknownSubscriberTypeException) {
            return;
        }
    }

    public function ended(Outcome $outcome): void
    {
        $this->outcome = $outcome;
    }

    public function finished(string $test): void
    {
        file_put_contents(
            $this->results,
            sprintf("%s %s\n", $this->outcome->value, rawurlencode($test)),
            FILE_APPEND | LOCK_EX,
        );
        $this->outcome = Outcome::Neither;
    }
}
