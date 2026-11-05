<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_keys;
use function count;
use function implode;

use NightWorksIO\MutationGate\Adapter\Pest\Warm\OpcacheBlacklist;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Pool;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Seat;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\WarmDirectory;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlots;

use function sprintf;

/**
 * The sets of warm workers one side-by-side run of the verification tail
 * forks from (ADR-0070, ADR-0075): one for each set of suites a run of every
 * test file names, booted on them where the tail boots, or none, and each
 * value the runs set of what PHP reads as it starts, which its workers start
 * with. Each is started, one worker in each slot, in a directory of its own
 * with nothing an earlier run left, as a run first needs it, and all of them
 * are ended once the runs are done.
 *
 * @internal
 */
final class TailWorkers
{
    /** The file the directory of each set after the first is named beside, by its place among the sets. */
    private const string OTHER = '%s-%d';

    /** @var array<string, string> what each set's directory is named beside, by what tells it apart */
    private array $sets = [];

    public function __construct(
        private readonly WorkerSlots $slots,
        private readonly string $beside,
        private readonly Project|NotGiven $booting,
        private readonly OpcacheBlacklist|NotGiven $uncached,
    ) {
    }

    /**
     * What the directory of the set a run forks from is named beside: the one
     * booted on these suites, or the unbooted one where there are none, whose
     * workers start PHP with these variables; started where it is not yet.
     *
     * @param list<string>|NotGiven $suites
     * @param array<string, string> $startingWith
     */
    public function besideFor(array|NotGiven $suites, array $startingWith): string
    {
        $booted = $suites instanceof NotGiven ? '' : sprintf('booted %s', implode(',', $suites));
        $key = implode("\n", [$booted, ...$startingWith]);
        $this->sets[$key] ??= $this->started(
            $this->sets === [] ? $this->beside : sprintf(self::OTHER, $this->beside, count($this->sets)),
            $suites,
            $startingWith,
        );

        return $this->sets[$key];
    }

    /** Every set ended, its directory cleared. */
    public function cleared(): void
    {
        foreach ($this->sets as $beside) {
            WarmDirectory::cleared($beside);
        }
    }

    /**
     * A set started, one worker in each slot, starting PHP with these
     * variables, in the directory named beside this, booting these suites
     * where the tail boots: what it is named beside.
     *
     * @param list<string>|NotGiven $suites
     * @param array<string, string> $startingWith
     */
    private function started(string $beside, array|NotGiven $suites, array $startingWith): string
    {
        WarmDirectory::cleared($beside);
        $directory = WarmDirectory::made($beside);

        if ($this->uncached instanceof OpcacheBlacklist) {
            $this->uncached->leftIn($directory);
        }

        if ($this->booting instanceof Project && ! $suites instanceof NotGiven) {
            Invocation::booting($this->booting, $suites)->leftIn($directory);
        }

        foreach (array_keys([...$this->slots]) as $at) {
            Pool::started(Seat::at($directory, $at + 1), $startingWith);
        }

        return $beside;
    }
}
