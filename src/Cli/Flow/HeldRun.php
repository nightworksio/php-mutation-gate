<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_key_exists;
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
use NightWorksIO\MutationGate\Core\Unit\Units;

/**
 * The runs of held units' holding tests alone under coverage (ADR-0005): a
 * group's run loads only the test files that declare its tests, as the
 * runner lists the groups and names their tests' files, once for all the
 * units, so the same tests run without the rest of the suite loaded; where
 * the runner cannot place every one of a group's tests in a file, or that
 * run fails, the run loads its suites as before (ADR-0161).
 *
 * @internal
 */
final readonly class HeldRun
{
    /** @param array<string, Paths> $files the test files that declare every test of each group, by its name */
    private function __construct(private Adapters $adapters, private array $files)
    {
    }

    /** The runs of these units' holding tests, each group's files found once for all of them. */
    public static function of(Adapters $adapters, Units $units): self
    {
        $groups = [];

        foreach ($units as $unit) {
            $judgedBy = $unit->judgedBy();
            $groups += $judgedBy instanceof Group ? [$judgedBy->name() => $judgedBy] : [];
        }

        return new self($adapters, self::filesOf($adapters, array_values($groups)));
    }

    /** The map of these tests' run alone into this directory, or why they cannot run on their own. */
    public function coverage(Group|Filter $tests, Path $into): CoverageMap|CannotJudge
    {
        $run = $this->adapters->covering(CoverageRun::of($tests, $into));
        $narrowed = $tests instanceof Group && array_key_exists($tests->name(), $this->files)
            ? $this->adapters->runner->coverage($run->within($this->files[$tests->name()]))
            : $tests;

        return $narrowed instanceof CoverageMap ? $narrowed : $this->adapters->runner->coverage($run);
    }

    /**
     * The test files that declare every test of each of these groups, by its
     * name; none for a group the runner lists no test of, or one of whose
     * tests it places in no file.
     *
     * @param  list<Group>          $groups
     * @return array<string, Paths>
     */
    private static function filesOf(Adapters $adapters, array $groups): array
    {
        $listed = self::listed($adapters, $groups);
        $all = TestIds::union($listed);
        $names = $all->count() === 0 ? NotGiven::value() : $adapters->runner->names($all, $adapters->withheld);
        $files = [];

        foreach ($listed as $name => $tests) {
            $placed = $names instanceof TestNames ? self::placed($tests, $names) : NotGiven::value();
            $files += $placed instanceof Paths ? [$name => $placed] : [];
        }

        return $files;
    }

    /**
     * The tests the runner lists in each of these groups, by its name, each
     * set of suites listed once.
     *
     * @param  list<Group>            $groups
     * @return array<string, TestIds>
     */
    private static function listed(Adapters $adapters, array $groups): array
    {
        $listings = [];
        $listed = [];

        foreach ($groups as $group) {
            $suites = $adapters->narrowing->suitesFor($group);
            $key = $suites->isAll() ? '' : $suites->joined();
            $listings[$key] = array_key_exists($key, $listings)
                ? $listings[$key]
                : $adapters->runner->listing($adapters->withheld, $suites);
            $listed[$group->name()] = $listings[$key] instanceof TestListing
                ? $listings[$key]->inGroup($group)
                : TestIds::none();
        }

        return $listed;
    }

    /** The files these tests are declared in; none where there are none, or the names place one in no file. */
    private static function placed(TestIds $tests, TestNames $names): Paths|NotGiven
    {
        $files = [];

        foreach ($tests as $test) {
            $name = $names->testOf($test);

            if (! $name instanceof TestName) {
                return NotGiven::value();
            }

            $files[$name->file()->value()] = $name->file();
        }

        return $files === [] ? NotGiven::value() : Paths::of(...array_values($files));
    }
}
