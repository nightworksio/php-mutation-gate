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
 * runs; each test's outcome as it finishes; each test skipped or marked
 * incomplete, alone or with its whole suite, since one set aside before it
 * was prepared never finishes; and each test class whose `setUpBeforeClass`
 * failed or errored. A test that started and ended none of these ways is one
 * whose process died while it ran. The outcome is forgotten as each test
 * starts and finishes, so none is written against another test.
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
                new OnSkipped($recorder),
                new OnIncomplete($recorder),
                new OnSuiteSkipped($recorder),
                new OnBeforeClassErrored($recorder),
                new OnBeforeClassFailed($recorder),
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

    /** Records a test skipped or marked incomplete as ended, neither passed nor failed. */
    public function setAside(string $test): void
    {
        $this->write(Outcome::Neither->line($test));
    }

    /** Records a test class whose `setUpBeforeClass` failed or errored. */
    public function classFailed(string $class): void
    {
        $this->write(Outcome::ClassFailed->line($class));
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
