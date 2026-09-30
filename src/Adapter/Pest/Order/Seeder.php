<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Order;

use function array_key_exists;
use function file_get_contents;
use function getcwd;
use function getenv;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Pest\CoverageFile;
use NightWorksIO\MutationGate\Adapter\Pest\Identities;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Off;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Adapter\Pest\Records;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Order\Enclosing;
use NightWorksIO\MutationGate\Core\Php\Functions;
use Pest\Mutate\Event\Facade;
use Pest\Mutate\MutationSuite;

use function Pest\version;
use function sprintf;

/**
 * What the Pest plugin writes in Pest's own process once every mutant is
 * made and before any runs: each mutant's order, from the kill history the
 * adapter planned and the opening run's coverage map, where the mutant's own
 * process reads it (ADR-0013, decision 3). Where it has no map, it writes
 * none, and each mutant runs its tests in Pest's own order.
 *
 * @phpstan-import-type Planned from Records
 */
final readonly class Seeder
{
    /** The variable the adapter names the order directory in. */
    public const string ORDER = 'MUTATION_GATE_ORDER';

    private function __construct(private string $directory, private string $results, private Root $root)
    {
    }

    /** Ordering where the adapter asked for it, outside a mutant's own process. */
    public static function fromEnvironment(): self|Off
    {
        return self::listening(
            getenv(self::ORDER),
            getenv(Recorder::RESULTS),
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

    /** Writes the order of every mutant of a suite, as the plugin reads it in each mutant's own process. */
    public function seed(MutationSuite $suite): void
    {
        $coverage = CoverageFile::at(Recorder::coverageBeside($this->results));

        if (! $coverage instanceof CoverageFile) {
            return;
        }

        $history = Plan::read($this->directory);
        $planned = $this->plannedIn($suite);
        $ids = Identities::of($this->root, $planned);
        $functions = [];

        foreach ($planned as $native => $mutant) {
            $file = $mutant['file'];
            $functions[$file] = array_key_exists($file, $functions)
                ? $functions[$file]
                : Functions::in(Contents::of(sprintf('%s', file_get_contents($file))));
            $in = Enclosing::of($this->root->relative($file), $functions[$file]->around(Line::of($mutant['start'])));

            Seed::write(
                Seed::directoryOf($this->directory, $mutant['mutated']),
                version(),
                $history->likelyKillers($ids[$native], $in),
                $coverage->testsCovering($file, $mutant['start'], $mutant['end']),
                $coverage,
            );
        }
    }

    /** @return array<string, Planned> every mutant of a suite as the recorder plans it, by native id */
    private function plannedIn(MutationSuite $suite): array
    {
        $planned = [];

        foreach ($suite->repository->all() as $collection) {
            foreach ($collection->tests() as $test) {
                $planned[$test->getId()] = [
                    'file' => sprintf('%s', $test->mutation->file->getRealPath()),
                    'start' => $test->mutation->startLine,
                    'end' => $test->mutation->endLine,
                    'mutator' => $test->mutation->mutator,
                    'diff' => $test->mutation->diff,
                    'mutated' => $test->mutation->modifiedSourcePath,
                ];
            }
        }

        return $planned;
    }
}
