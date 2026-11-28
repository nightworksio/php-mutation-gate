<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Order;

use Closure;

use function file_get_contents;
use function getcwd;
use function getenv;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Pest\CoverageFile;
use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\Identities;
use NightWorksIO\MutationGate\Adapter\Pest\LoadedMap;
use NightWorksIO\MutationGate\Adapter\Pest\PlannedMutant;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Off;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Adapter\Pest\Split\Streams;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Order\Enclosing;
use NightWorksIO\MutationGate\Core\Php\Functions;
use Pest\Mutate\Event\Facade;
use Pest\Mutate\MutationSuite;

use function Pest\version;
use function sprintf;

/**
 * What the Pest plugin writes in Pest's own process once every mutant is
 * made and before any runs on its own, where the run splits its streams
 * once they are done (see SeedsWaiting): each mutant's order, from the kill history the
 * adapter planned and the opening run's coverage map, where the mutant's own
 * process reads it (ADR-0013). Where it has no map, it writes
 * none, and each mutant runs its tests in Pest's own order.
 *
 * @internal
 */
final readonly class Seeder
{
    private function __construct(private string $directory, private string $results, private Root $root)
    {
    }

    /** Ordering where the adapter asked for it, outside a mutant's own process. */
    public static function fromEnvironment(): self|Off
    {
        return self::listening(
            getenv(GateVariable::Order->value),
            getenv(GateVariable::Results->value),
            getenv(Recorder::MUTANT),
            Facade::instance(),
            Root::of(sprintf('%s', getcwd())),
        );
    }

    /** Ordering, subscribed to Pest's events, where an order directory and a results file are named. */
    public static function listening(
        string|false $directory,
        string|false $results,
        string|false $mutant,
        Facade $events,
        Root $root,
    ): self|Off {
        $asked = is_string($directory) && $directory !== '' && is_string($results) && $results !== '';

        if (! $asked || is_string($mutant)) {
            return Off::Ordering;
        }

        $seeder = new self($directory, $results, $root);
        $events->registerSubscriber(new OnSeeding($seeder));

        return $seeder;
    }

    /**
     * Writes the order of every mutant of a suite, as the plugin reads it in
     * each mutant's own process; where the run splits its streams, of only
     * those the streams leave for their own runs, once the streams are done
     * (see SeedsWaiting).
     */
    public function seed(MutationSuite $suite): void
    {
        if (Streams::areOn()) {
            SeedsWaiting::hold($this, $suite);

            return;
        }

        $this->seeded($suite, static fn(): bool => true);
    }

    /**
     * Writes the order of each mutant of a suite this says to, every mutant
     * known by its place among all the suite's.
     *
     * @param Closure(string): bool $wanted whether a mutant's copy, by its path, is seeded
     */
    public function seeded(MutationSuite $suite, Closure $wanted): void
    {
        $loaded = LoadedMap::taken();
        $coverage = $loaded instanceof CoverageFile
            ? $loaded
            : CoverageFile::at(Recorder::coverageBeside($this->results));

        if (! $coverage instanceof CoverageFile) {
            return;
        }

        $history = Plan::read($this->directory);
        $planned = PlannedMutant::inOrder($this->plannedIn($suite));
        $ids = Identities::of($this->root, $planned);
        $functions = [];

        foreach ($planned as $at => $mutant) {
            if (! $wanted($mutant->mutated()->value())) {
                continue;
            }

            $file = $mutant->file()->value();
            $functions[$file] ??= Functions::in(Contents::of(sprintf('%s', file_get_contents($file))));
            $in = Enclosing::of($this->root->relative($file), $functions[$file]->around($mutant->start()));

            Seed::write(
                Seed::directoryOf($this->directory, $mutant->mutated()->value()),
                version(),
                $history->likelyKillers($ids[$at], $in),
                $coverage->testsCovering($mutant->file(), $mutant->start(), $mutant->end()),
                $coverage->secondsOf(...),
            );
        }
    }

    /**
     * Every mutant of a suite as the recorder plans it. Mutants that leave the
     * same source share one mutated copy, and so one seed.
     *
     * @return list<PlannedMutant>
     */
    private function plannedIn(MutationSuite $suite): array
    {
        $planned = [];

        foreach ($suite->repository->all() as $collection) {
            foreach ($collection->tests() as $test) {
                $planned[] = Recorder::madeOf($test->mutation);
            }
        }

        return $planned;
    }
}
