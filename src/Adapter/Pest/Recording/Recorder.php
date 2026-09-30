<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

use function copy;
use function file_put_contents;
use function getenv;
use function is_string;
use function json_encode;

use Pest\Mutate\Event\Facade;
use Pest\Mutate\MutationSuite;
use Pest\Mutate\MutationTest;
use Pest\Mutate\Repositories\TelemetryRepository;
use Pest\Support\Container;
use Pest\Support\Coverage;

use function sprintf;

/**
 * What the Pest plugin writes for the adapter, one JSON line at a time as each
 * event arrives, so a run stopped at its deadline leaves every result it had:
 * - the opening run's coverage map, copied beside the results before Pest
 *   deletes it;
 * - `planned`, every mutant with its file, lines, mutator class and diff, once
 *   they are all made;
 * - `outcome`, each mutant's status as Pest decides it;
 * - `finished`, every mutant's final status and duration, which Pest sets only
 *   after the outcome is announced, and `end`, with the opening run's seconds.
 */
final readonly class Recorder
{
    /** The variable the adapter names the results file in. */
    public const string RESULTS = 'MUTATION_GATE_RESULTS';

    /** The variable Pest sets in each mutant's own process. */
    private const string MUTANT = 'PEST_MUTATION_TESTING';

    /** A duration of whole seconds stays a float, so the adapter reads it as one. */
    private const int FLAGS = JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION;

    /**
     * @param string        $results   the file the lines are written to
     * @param string        $coverage  where Pest keeps the opening run's coverage map
     * @param object|string $telemetry what Pest's container holds as its telemetry
     */
    public function __construct(private string $results, private string $coverage, private object|string $telemetry)
    {
    }

    /** Recording where the adapter asked for it, and outside a mutant's own process. */
    public static function fromEnvironment(): self|Off
    {
        return self::listening(
            getenv(self::RESULTS),
            getenv(self::MUTANT),
            Facade::instance(),
            Coverage::getPath(),
            Container::getInstance()->get(TelemetryRepository::class),
        );
    }

    /** Recording, subscribed to the events, when a results file is named and this is not a mutant's process. */
    public static function listening(
        string|false $results,
        string|false $mutant,
        Facade $events,
        string $coverage,
        object|string $telemetry,
    ): self|Off {
        if (! is_string($results) || $results === '' || is_string($mutant)) {
            return Off::Recording;
        }

        $recorder = new self($results, $coverage, $telemetry);
        $events->registerSubscribers(
            new OnStartMutationGeneration($recorder),
            new OnStartMutationSuite($recorder),
            new OnTested($recorder),
            new OnUntested($recorder),
            new OnTimeout($recorder),
            new OnUncovered($recorder),
            new OnFinishMutationSuite($recorder),
        );

        return $recorder;
    }

    /**
     * Where the opening run's coverage map is kept beside a results file.
     *
     * @return non-empty-string
     */
    public static function coverageBeside(string $results): string
    {
        return sprintf('%s.coverage.php', $results);
    }

    public function keepCoverage(): void
    {
        copy($this->coverage, self::coverageBeside($this->results));
    }

    public function planned(MutationSuite $suite): void
    {
        foreach ($suite->repository->all() as $collection) {
            foreach ($collection->tests() as $test) {
                $this->write([
                    'event' => 'planned',
                    'id' => $test->getId(),
                    'file' => $test->mutation->file->getRealPath(),
                    'start' => $test->mutation->startLine,
                    'end' => $test->mutation->endLine,
                    'mutator' => $test->mutation->mutator,
                    'diff' => $test->mutation->diff,
                ]);
            }
        }
    }

    public function outcome(MutationTest $test): void
    {
        $this->write(['event' => 'outcome', 'id' => $test->getId(), 'status' => $test->result()->value]);
    }

    public function finished(MutationSuite $suite): void
    {
        foreach ($suite->repository->all() as $collection) {
            foreach ($collection->tests() as $test) {
                $this->write([
                    'event' => 'finished',
                    'id' => $test->getId(),
                    'status' => $test->result()->value,
                    'duration' => $test->duration(),
                ]);
            }
        }

        $telemetry = $this->telemetry;
        $this->write([
            'event' => 'end',
            'opening' => $telemetry instanceof TelemetryRepository ? $telemetry->getInitialTestSuiteDuration() : false,
        ]);
    }

    /** @param array<string, mixed> $record */
    private function write(array $record): void
    {
        file_put_contents($this->results, sprintf("%s\n", json_encode($record, self::FLAGS)), FILE_APPEND);
    }
}
