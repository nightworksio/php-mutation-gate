<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Control;

use function array_map;

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\OwnTime;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Hold\HeldCovered;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\MutantLimit;
use NightWorksIO\MutationGate\Core\Runner\RunSelection;
use NightWorksIO\MutationGate\Core\Runner\RunTests;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * An unmutated control of a mutant's run (ADR-0008): these
 * tests, in this order, selected as the run selected them, run with the file
 * the mutant changes served unmutated, as the runner served the mutant, and
 * allowed the limit its run was. Two controls of the same file, tests,
 * selection and limit are one control.
 */
final readonly class Control
{
    /** The directory a runner keeps the files of its controls in, among its own. */
    public const string DIRECTORY = 'unmutated';

    /** Why a control's file cannot be served unmutated: the gate cannot read it. */
    public const string UNREAD = 'The gate cannot read %s to serve it unmutated.';

    /** How a control's key spells a selection of every test file. */
    private const string EVERY_FILE = '*';

    private function __construct(private Path $file, private RunTests $run, private Seconds $limit)
    {
    }

    /** The control of these tests on this file, as the runner selects any set of tests, allowed this limit. */
    public static function of(Path $file, TestIds $tests, Seconds $limit): self
    {
        return new self($file, RunTests::of($tests), $limit);
    }

    /** The control of a run's tests on this file, selected as the run selected them, allowed this limit. */
    public static function running(Path $file, RunTests $run, Seconds $limit): self
    {
        return new self($file, $run, $limit);
    }

    /**
     * The control of these tests, or of a run's, on this file, allowed the
     * standard mutant limit of their time in a map.
     */
    public static function standard(Path $file, TestIds|RunTests $tests, CoverageMap $map, LimitBounds $bounds): self
    {
        $run = $tests instanceof RunTests ? $tests : RunTests::of($tests);

        return new self($file, $run, MutantLimit::standard()->of(OwnTime::of($map, $run->tests()), $bounds));
    }

    /**
     * The tests a mutant's own run runs, and how, as the runner selects them
     * from those that judge it: the tests covering its lines as a map names
     * them, and of a held unit only those that hold it; or those of the test
     * files its run loaded with no filter, or of every one.
     */
    public static function judging(
        Mutant $mutant,
        CoverageMap $map,
        HeldCovered $held,
        RunSelection $run,
        Paths|WholeSuite|NotGiven $unfiltered,
    ): RunTests {
        $location = $mutant->location();

        return $run->of(
            $held->judgingAmong(
                $location->file(),
                $map->testsCovering($location->file(), $location->start(), $location->last()),
            ),
            $map,
            $unfiltered,
        );
    }

    /** The file the mutant changes, served unmutated. */
    public function file(): Path
    {
        return $this->file;
    }

    /** The tests the control runs, in the order it runs them. */
    public function tests(): TestIds
    {
        return $this->run->tests();
    }

    /** How the control selects its tests, as its mutant's run did: a filter, test files, every one, or not given. */
    public function selected(): Filter|Paths|WholeSuite|NotGiven
    {
        return $this->run->by();
    }

    /** The seconds the control is allowed: the limit of the mutant's run. */
    public function limit(): Seconds
    {
        return $this->limit;
    }

    /**
     * What tells this control from another, so each runs once: its file, its
     * tests in their order, how it selects them and its limit.
     */
    public function key(): string
    {
        return JsonText::compact([
            $this->file->value(),
            array_map(static fn(TestId $test): string => $test->value(), [...$this->tests()]),
            ...$this->selectionOf($this->run->by()),
            $this->limit->seconds(),
        ]);
    }

    /**
     * How a control selects its tests, as its key spells it: a filter's
     * pattern, its test files, or every one; nothing where it selects them
     * as the runner selects any set of tests.
     *
     * @return list<string|list<string>>
     */
    private function selectionOf(Filter|Paths|WholeSuite|NotGiven $by): array
    {
        return match (true) {
            $by instanceof Filter => [$by->pattern()],
            $by instanceof Paths => [array_map(static fn(Path $file): string => $file->value(), [...$by])],
            $by instanceof WholeSuite => [self::EVERY_FILE],
            default => [],
        };
    }
}
