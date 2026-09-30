<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

use function copy;
use function dirname;
use function file_put_contents;
use function getenv;
use function is_dir;
use function is_string;
use function mkdir;

use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\PestStatus;
use NightWorksIO\MutationGate\Adapter\Pest\PlannedMutant;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
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
    /** The variable Pest sets in each mutant's own process, naming the file its mutant replaces. */
    public const string MUTANT = 'PEST_MUTATION_TESTING';

    /** The variable Pest sets beside it, naming the mutated copy it serves in the original's place. */
    public const string MUTATED = 'PEST_MUTATION_FILE';

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
        $results = getenv(GateVariable::Results->value);
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
                $this->write(RecordLine::planned(self::plannedOf($test)));
                $made++;
            }
        }

        $telemetry = $this->telemetry;
        $opening = $telemetry instanceof TelemetryRepository
            ? Seconds::of($telemetry->getInitialTestSuiteDuration())
            : Unmeasured::duration();
        $this->write(RecordLine::made($made, $opening));
    }

    /** A mutant Pest made, as the plugin records it. */
    public static function plannedOf(MutationTest $test): PlannedMutant
    {
        return PlannedMutant::of(
            $test->getId(),
            DiskPath::of(sprintf('%s', $test->mutation->file->getRealPath())),
            Line::of($test->mutation->startLine),
            Line::of($test->mutation->endLine),
            $test->mutation->mutator,
            $test->mutation->diff,
            DiskPath::of($test->mutation->modifiedSourcePath),
        );
    }

    public function outcome(MutationTest $test): void
    {
        $this->write(RecordLine::outcome($test->getId(), $this->statusOf($test)));
    }

    public function finished(MutationSuite $suite): void
    {
        foreach ($suite->repository->all() as $collection) {
            foreach ($collection->tests() as $test) {
                $this->write(RecordLine::finished(
                    $test->getId(),
                    $this->statusOf($test),
                    $test->duration(),
                ));
            }
        }

        $this->write(RecordLine::end());
    }

    /** A mutant's status as the plugin knows it, or as Pest names one it does not. */
    private function statusOf(MutationTest $test): PestStatus|string
    {
        return $this->known($test->result()->value);
    }

    /** A status Pest names, as the plugin knows it, or as Pest names it where a later Pest adds one. */
    private function known(string $word): PestStatus|string
    {
        return PestStatus::tryFrom($word) ?? $word;
    }

    /** @phpstan-assert-if-true non-empty-string $results */
    private static function asked(string|false $results, string|false $mutant): bool
    {
        return is_string($results) && $results !== '' && ! is_string($mutant);
    }

    private function write(string $line): void
    {
        file_put_contents($this->results, $line, FILE_APPEND | LOCK_EX);
    }
}
