<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

use function copy;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function getenv;
use function is_dir;
use function is_file;
use function is_string;
use function mkdir;

use NightWorksIO\MutationGate\Adapter\Pest\Bridged;
use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\PestStatus;
use NightWorksIO\MutationGate\Adapter\Pest\PlannedMutant;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\Runner\Exhaustion;
use NightWorksIO\MutationGate\Core\Runner\FatalError;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use Pest\Mutate\Event\Facade;
use Pest\Mutate\MutationSuite;
use Pest\Mutate\MutationTest;
use Pest\Mutate\Repositories\TelemetryRepository;
use Pest\Support\Container;
use Pest\Support\Coverage;

use function sprintf;
use function unlink;

/**
 * What the Pest plugin writes for the adapter, one JSON line at a time as each
 * event arrives, so a run stopped at its deadline leaves every result it had:
 * - the opening run's coverage map, copied beside the results before Pest
 *   deletes it;
 * - `planned`, every mutant with its file, lines, mutator as the gate names
 *   it, diff and the mutated copy Pest serves in a mutant's own process,
 *   once they are all made, and `made`, how many there are and the opening
 *   run's seconds;
 * - `outcome`, each mutant's status as Pest decides it, then `killed` and
 *   `errored`, each test its own process wrote to its killer file as failing
 *   or erroring there, and `exhausted`, the memory limit a caught mutant's
 *   own process ran out of, where its output says it did;
 * - `finished`, every mutant's final status and duration, which Pest sets only
 *   after the outcome is announced, and `end`.
 */
final readonly class Recorder
{
    /** The variable Pest sets in each mutant's own process, naming the file its mutant replaces. */
    public const string MUTANT = 'PEST_MUTATION_TESTING';

    /** The variable Pest sets beside it, naming the mutated copy it serves in the original's place. */
    public const string MUTATED = 'PEST_MUTATION_FILE';

    /** A mutant's error log beside a results file: the results file's name, then the digest of the mutated copy. */
    private const string ERRORS = '%s.%s.log';

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
     * Where a mutant's own process logs PHP's errors, beside a results file,
     * by the mutated copy it runs on: PHP logs a fatal error as it happens,
     * before any shutdown function, so the log keeps one that Pest's and
     * PHPUnit's own handling of it would lose.
     *
     * @return non-empty-string
     */
    public static function errorsBeside(string $results, string $mutated): string
    {
        return sprintf(self::ERRORS, $results, Digest::sha256Of($mutated)->value());
    }

    /**
     * Every mutant's error log beside a results file, as a pattern `glob()` matches.
     *
     * @return non-empty-string
     */
    public static function everyErrorLogBeside(string $results): string
    {
        return sprintf(self::ERRORS, $results, '*');
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
            Bridged::nameOf($test->mutation->mutator),
            $test->mutation->diff,
            DiskPath::of($test->mutation->modifiedSourcePath),
        );
    }

    /**
     * A mutant's status as Pest decided it, once its own process has ended,
     * and each test that process wrote to its killer file as failing or
     * erroring there.
     */
    public function outcome(MutationTest $test): void
    {
        $mutated = $test->mutation->modifiedSourcePath;
        $this->write(RecordLine::outcome($test->getId(), $this->statusOf($test)));

        foreach (KillerFile::taken(KillerFile::beside($this->results, $mutated), $mutated) as $record) {
            $this->write($record);
        }
    }

    /**
     * The memory limit a mutant's own process ran out of, and that PHP
     * recorded a fatal error in it, where the errors it logged say so; the
     * log is removed once read.
     */
    public function exhausted(MutationTest $test): void
    {
        $mutated = $test->mutation->modifiedSourcePath;
        $log = self::errorsBeside($this->results, $mutated);

        if (! is_file($log)) {
            return;
        }

        $logged = (string) file_get_contents($log);
        $limit = Exhaustion::in($logged);
        unlink($log);

        if ($limit instanceof MemoryCap) {
            $this->write(RecordLine::exhausted($mutated, $limit));
        }

        if (FatalError::in($logged)) {
            $this->write(RecordLine::fatal($mutated));
        }
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
