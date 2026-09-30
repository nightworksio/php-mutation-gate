<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

use function copy;
use function dirname;
use function file_put_contents;
use function getenv;
use function is_dir;
use function is_string;
use function json_encode;
use function mkdir;

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
 * - `planned`, every mutant with its file, lines, mutator class, diff and the
 *   mutated copy Pest serves in a mutant's own process, once they are all
 *   made, and `made`, how many there are and the opening run's seconds;
 * - `outcome`, each mutant's status as Pest decides it;
 * - `finished`, every mutant's final status and duration, which Pest sets only
 *   after the outcome is announced, and `end`.
 */
final readonly class Recorder
{
    /** The variable the adapter names the results file in. */
    public const string RESULTS = 'MUTATION_GATE_RESULTS';

    /** The variable Pest sets in each mutant's own process, naming the file its mutant replaces. */
    public const string MUTANT = 'PEST_MUTATION_TESTING';

    /** The variable Pest sets beside it, naming the mutated copy it serves in the original's place. */
    public const string MUTATED = 'PEST_MUTATION_FILE';

    /** How the plugin writes JSON: a duration of whole seconds stays a float, so the adapter reads it as one. */
    public const int FLAGS = JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION;

    /**
     * @param string        $results   the file the lines are written to
     * @param string        $coverage  where Pest keeps the opening run's coverage map
     * @param object|string $telemetry what Pest's container holds as its telemetry
     */
    public function __construct(private string $results, private string $coverage, private object|string $telemetry)
    {
    }

    /**
     * Recording where the adapter asked for it, and outside a mutant's own
     * process. Anywhere else it reaches none of Pest's own objects.
     */
    public static function fromEnvironment(): self|Off
    {
        $results = getenv(self::RESULTS);
        $mutant = getenv(self::MUTANT);

        return self::asked($results, $mutant) ? self::listening(
            $results,
            $mutant,
            Facade::instance(),
            Coverage::getPath(),
            Container::getInstance()->get(TelemetryRepository::class),
        ) : Off::Recording;
    }

    /** Recording, subscribed to the events, when a results file is named and this is not a mutant's process. */
    public static function listening(
        string|false $results,
        string|false $mutant,
        Facade $events,
        string $coverage,
        object|string $telemetry,
    ): self|Off {
        if (! self::asked($results, $mutant)) {
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

    /**
     * Where the mutated copy of a mutant Pest ran no test on is kept, in the
     * directory of a results file.
     *
     * @return non-empty-string
     */
    public static function mutantBeside(string $results, string $id): string
    {
        return sprintf('%s/mutants/%s.php', dirname($results), $id);
    }

    /**
     * Keeps the mutated copy of a mutant Pest ran no test on, which the
     * adapter judges by the tests that read its value where its line is not
     * executable.
     */
    public function keepMutant(MutationTest $test): void
    {
        $kept = self::mutantBeside($this->results, $test->getId());

        if (! is_dir(dirname($kept))) {
            mkdir(dirname($kept), recursive: true);
        }

        copy($test->mutation->modifiedSourcePath, $kept);
    }

    /**
     * Every mutant Pest made, and then how many there are, with the opening
     * run's seconds, from which each mutant's limit follows. A run stopped
     * before that last line lost some of its mutants.
     */
    public function planned(MutationSuite $suite): void
    {
        $made = 0;

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
                    'mutated' => $test->mutation->modifiedSourcePath,
                ]);
                $made++;
            }
        }

        $telemetry = $this->telemetry;
        $this->write([
            'event' => 'made',
            'count' => $made,
            'opening' => $telemetry instanceof TelemetryRepository ? $telemetry->getInitialTestSuiteDuration() : false,
        ]);
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

        $this->write(['event' => 'end']);
    }

    /** @phpstan-assert-if-true non-empty-string $results */
    private static function asked(string|false $results, string|false $mutant): bool
    {
        return is_string($results) && $results !== '' && ! is_string($mutant);
    }

    /** @param array<string, mixed> $record */
    private function write(array $record): void
    {
        file_put_contents($this->results, sprintf("%s\n", json_encode($record, self::FLAGS)), FILE_APPEND | LOCK_EX);
    }
}
