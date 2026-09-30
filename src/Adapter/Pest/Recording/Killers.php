<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

use function file_put_contents;
use function getenv;
use function is_string;
use function json_encode;

use PHPUnit\Event\EventFacadeIsSealedException;
use PHPUnit\Event\Facade;
use PHPUnit\Event\UnknownSubscriberTypeException;

use function sprintf;

/**
 * What the Pest plugin writes in a mutant's own process: each test that fails
 * or errors there, with the mutated copy Pest serves, one JSON line at a time
 * in the results file the process inherits. Pest stops the process at the
 * first, so the first line a mutant has names the test that killed it.
 */
final readonly class Killers
{
    private const int FLAGS = JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

    private function __construct(private string $results, private string $mutated)
    {
    }

    /** Naming killers where this is a mutant's own process of a run the adapter records. */
    public static function fromEnvironment(): self|Off
    {
        return self::listening(getenv(Recorder::RESULTS), getenv(Recorder::MUTATED), Facade::instance());
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

        return $killers;
    }

    /** Writes a test that failed or errored, by its id, as the coverage map names it. */
    public function killedBy(string $test): void
    {
        $line = json_encode(['event' => 'killed', 'mutated' => $this->mutated, 'test' => $test], self::FLAGS);
        file_put_contents($this->results, sprintf("%s\n", $line), FILE_APPEND | LOCK_EX);
    }
}
