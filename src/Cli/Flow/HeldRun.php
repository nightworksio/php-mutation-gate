<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_values;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestListing;
use NightWorksIO\MutationGate\Core\Test\TestName;
use NightWorksIO\MutationGate\Core\Test\TestNames;

/**
 * The run of a held unit's holding tests alone under coverage (ADR-0005):
 * a group's run loads only the test files that declare its tests, as the
 * runner lists them, so the same tests run without the rest of the suite
 * loaded; where the runner cannot place every one of them in a file, or that
 * run fails, the run loads its suites as before (ADR-0161).
 *
 * @internal
 */
final readonly class HeldRun
{
    public function __construct(private Adapters $adapters)
    {
    }

    /** The map of these tests' run alone into this directory, or why they cannot run on their own. */
    public function coverage(Group|Filter $tests, Path $into): CoverageMap|CannotJudge
    {
        $run = $this->adapters->covering(CoverageRun::of($tests, $into));
        $files = $tests instanceof Group ? $this->filesOf($tests) : NotGiven::value();
        $narrowed = $files instanceof Paths ? $this->adapters->runner->coverage($run->within($files)) : $files;

        return $narrowed instanceof CoverageMap ? $narrowed : $this->adapters->runner->coverage($run);
    }

    /** The test files that declare every test of the group; none where the runner cannot place one of them. */
    private function filesOf(Group $group): Paths|NotGiven
    {
        $adapters = $this->adapters;
        $listing = $adapters->runner->listing($adapters->withheld, $adapters->narrowing->suitesFor($group));
        $tests = $listing instanceof TestListing ? $listing->inGroup($group) : TestIds::none();
        $names = $tests->count() === 0 ? NotGiven::value() : $adapters->runner->names($tests, $adapters->withheld);

        if (! $names instanceof TestNames) {
            return NotGiven::value();
        }

        $files = [];

        foreach ($tests as $test) {
            $name = $names->testOf($test);

            if (! $name instanceof TestName) {
                return NotGiven::value();
            }

            $files[$name->file()->value()] = $name->file();
        }

        return Paths::of(...array_values($files));
    }
}
