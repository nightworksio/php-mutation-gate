<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Control;

use function array_map;

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\OwnTime;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Hold\HeldCovered;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\MutantLimit;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * An unmutated control of a mutant's run (ADR-0008): these
 * tests, in this order, run with the file the mutant changes served
 * unmutated, as the runner served the mutant, and allowed the limit its run
 * was. Two controls of the same file, tests and limit are one control.
 */
final readonly class Control
{
    /** The directory a runner keeps the files of its controls in, among its own. */
    public const string DIRECTORY = 'unmutated';

    /** Why a control's file cannot be served unmutated: the gate cannot read it. */
    public const string UNREAD = 'The gate cannot read %s to serve it unmutated.';

    private function __construct(private Path $file, private TestIds $tests, private Seconds $limit)
    {
    }

    public static function of(Path $file, TestIds $tests, Seconds $limit): self
    {
        return new self($file, $tests, $limit);
    }

    /** The control of these tests on this file, allowed the standard mutant limit of their time in a map. */
    public static function standard(Path $file, TestIds $tests, CoverageMap $map, LimitBounds $bounds): self
    {
        return new self($file, $tests, MutantLimit::standard()->of(OwnTime::of($map, $tests), $bounds));
    }

    /**
     * The tests that judge a mutant: those covering its lines as a map names
     * them, and of a held unit only those that hold it.
     */
    public static function judging(Mutant $mutant, CoverageMap $map, HeldCovered $held): TestIds
    {
        $location = $mutant->location();

        return $held->judgingAmong(
            $location->file(),
            $map->testsCovering($location->file(), $location->start(), $location->last()),
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
        return $this->tests;
    }

    /** The seconds the control is allowed: the limit of the mutant's run. */
    public function limit(): Seconds
    {
        return $this->limit;
    }

    /** What tells this control from another, so each runs once: its file, its tests in their order and its limit. */
    public function key(): string
    {
        return JsonText::compact([
            $this->file->value(),
            array_map(static fn(TestId $test): string => $test->value(), [...$this->tests]),
            $this->limit->seconds(),
        ]);
    }
}
