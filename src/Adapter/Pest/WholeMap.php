<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use NightWorksIO\MutationGate\Adapter\Pest\Unexecutable\Selector;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\Handed;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;

use function sprintf;

/**
 * The plan's whole map, which a shard is handed beside the map of its own
 * files: a line that reads a value a mutant changes may be in any file, so
 * the tests that run it are found there, read once for each directory.
 */
final readonly class WholeMap
{
    /** What separates the parts of the key a scan is kept by. */
    private const string BETWEEN = "\n";

    public function __construct(private Project $project, private Remembered $remembered)
    {
    }

    /**
     * The coverage that finds the tests that run a line reading a value a
     * mutant changes: the whole map where the run opened on its shard's own,
     * or else the run's own map, which holds every file.
     */
    public function covering(
        MutationRequest $request,
        Covering $own,
        CoverageMap|Unshared $shared,
    ): Covering|CannotJudge {
        $handed = $request->coverage();

        if (! $shared instanceof CoverageMap || ! $handed instanceof Handed) {
            return $own;
        }

        $whole = $handed->whole();
        $reading = fn(): CoverageMap|CannotJudge => SharedCoverage::in($this->project, $whole);
        $map = $this->remembered->map($whole, $reading);

        return $map instanceof CannotJudge ? $map : new HandedOver($map, $this->project);
    }

    /**
     * The scan of the codebase that finds the tests that read the values a
     * run's files declare: made once for the whole map and those files where
     * the run opened on its shard's own map, and afresh from the run's own
     * coverage otherwise.
     */
    public function selector(MutationRequest $request, Covering $reading, CoverageMap|Unshared $shared): Selector
    {
        $handed = $request->coverage();
        $scanning = fn(): Selector => Selector::over($this->project, $reading, $request->files());
        $files = PathList::of($request->files())->joined(self::BETWEEN);
        $key = static fn(Handed $whole): string => sprintf('%s%s%s', $whole->whole()->value(), self::BETWEEN, $files);

        return $shared instanceof CoverageMap && $handed instanceof Handed
            ? $this->remembered->selector($key($handed), $scanning)
            : $scanning();
    }
}
