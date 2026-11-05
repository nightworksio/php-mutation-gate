<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_keys;
use function count;
use function implode;

use NightWorksIO\MutationGate\Adapter\Pest\Warm\Pool;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Seat;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\WarmDirectory;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlots;

use function sprintf;

/**
 * The sets of warm workers one side-by-side run of the verification tail
 * forks from (ADR-0070, ADR-0075): an unbooted set, and a booted set for each
 * set of suites a run of every test file names, each started, one worker in
 * each slot, in a directory of its own with nothing an earlier run left, as
 * a run first needs it, and all of them ended once the runs are done.
 *
 * @internal
 */
final class TailWorkers
{
    /** The file the directory of a booted set is named beside, by its place among the booted sets. */
    private const string BOOTED = '%s-booted-%d';

    /** What the unbooted set's directory is named beside, once it is started. */
    private string|NotGiven $unbooted;

    /** @var array<string, string> what each booted set's directory is named beside, by its suites */
    private array $booted = [];

    public function __construct(
        private readonly WorkerSlots $slots,
        private readonly string $beside,
        private readonly Project|NotGiven $booting,
    ) {
        $this->unbooted = NotGiven::value();
    }

    /**
     * What the directory of the set a run forks from is named beside: the
     * booted set of these suites, or the unbooted set where there are none;
     * started where it is not yet.
     *
     * @param list<string>|NotGiven $suites
     */
    public function besideFor(array|NotGiven $suites): string
    {
        return $suites instanceof NotGiven ? $this->unbootedBeside() : $this->bootedBeside($suites);
    }

    /** Every set ended, its directory cleared. */
    public function cleared(): void
    {
        foreach ([...$this->booted, ...($this->unbooted instanceof NotGiven ? [] : [$this->unbooted])] as $beside) {
            WarmDirectory::cleared($beside);
        }
    }

    private function unbootedBeside(): string
    {
        $this->unbooted = $this->unbooted instanceof NotGiven ? $this->started($this->beside) : $this->unbooted;

        return $this->unbooted;
    }

    /** @param list<string> $suites */
    private function bootedBeside(array $suites): string
    {
        $key = implode(',', $suites);
        $this->booted[$key] ??= $this->started(sprintf(self::BOOTED, $this->beside, count($this->booted) + 1), $suites);

        return $this->booted[$key];
    }

    /**
     * A set started, one worker in each slot, in the directory named beside
     * this, booting these suites where the tail boots: what it is named beside.
     *
     * @param list<string>|NotGiven $suites
     */
    private function started(string $beside, array|NotGiven $suites = new NotGiven()): string
    {
        WarmDirectory::cleared($beside);
        $directory = WarmDirectory::made($beside);

        if ($this->booting instanceof Project && ! $suites instanceof NotGiven) {
            Invocation::booting($this->booting, $suites)->leftIn($directory);
        }

        foreach (array_keys([...$this->slots]) as $at) {
            Pool::started(Seat::at($directory, $at + 1));
        }

        return $beside;
    }
}
