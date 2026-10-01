<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Assertion;

use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Matrix\KillMatrix;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Php\Functions;
use NightWorksIO\MutationGate\Core\Php\Nameless;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestName;
use NightWorksIO\MutationGate\Core\Verdict\Findings;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;

/**
 * The weak tests behind the survivors an assertion of value would kill: a
 * survivor of the Return value, Literal, Arithmetic, Collection or Unwrap
 * family, and each of its judging tests whose every assertion checks only
 * existence or shape, as the kill matrix names the tests that judge it. A
 * test the runner named no file for is not assessed (ADR-0025, decisions 5
 * and 6). A surviving removal reads its judging tests too (decision 11).
 */
final readonly class Weakness
{
    /** The files the judging tests of those survivors, and of each surviving removal, are in. */
    public static function testFiles(TreeVerdicts $verdicts, KillMatrix $matrix): Paths
    {
        $files = [];

        foreach (self::readingTheirTests($verdicts) as $survivor) {
            foreach (self::judgedBy($survivor, $matrix) as $test) {
                $named = $matrix->names()->testOf($test);

                if ($named instanceof TestName) {
                    $files[] = $named->file();
                }
            }
        }

        return Paths::of(...$files);
    }

    /**
     * What each of those survivors says of its weak tests, read from the
     * test files `testFiles()` names and the survivors' own files.
     *
     * @param ByPath<Contents> $sources the survivors' own files
     */
    public static function findings(
        TreeVerdicts $verdicts,
        KillMatrix $matrix,
        ByPath $sources,
        TestFiles $tests,
    ): Findings {
        $findings = Findings::none();

        foreach (self::seenByValue($verdicts) as $survivor) {
            $weak = self::weakList(self::judgedBy($survivor, $matrix), $matrix, $tests);

            if ($weak !== []) {
                $finding = WeaklyAsserted::by(self::functionOf($survivor, $sources), ...$weak);
                $findings = $findings->with($survivor->mutant()->id(), $finding);
            }
        }

        return $findings;
    }

    /** Those of these tests that are weak, in their order. */
    public static function weakAmong(TestIds $tests, KillMatrix $matrix, TestFiles $files): WeakTests
    {
        return WeakTests::of(...self::weakList($tests, $matrix, $files));
    }

    /** The tests that cover a survivor and judge it: every one, or its held unit's group. */
    public static function judgedBy(JudgedMutant $survivor, KillMatrix $matrix): TestIds
    {
        $judging = TestIds::none();

        foreach ($matrix->coveredBy($survivor) as $test) {
            $judging = $matrix->judges($survivor, $test) ? $judging->with($test) : $judging;
        }

        return $judging;
    }

    /** @return list<JudgedMutant> the survivors a value assertion would kill */
    private static function seenByValue(TreeVerdicts $verdicts): array
    {
        $seen = [];

        foreach (self::readingTheirTests($verdicts) as $survivor) {
            if ($survivor->mutant()->mutation()->family()->isSeenByValue()) {
                $seen[] = $survivor;
            }
        }

        return $seen;
    }

    /** @return list<JudgedMutant> the survivors whose judging tests are read: those a value would kill, and removals */
    private static function readingTheirTests(TreeVerdicts $verdicts): array
    {
        $reading = [];

        foreach ($verdicts as $tree) {
            foreach ($tree->survivors() as $survivor) {
                $family = $survivor->mutant()->mutation()->family();

                if (
                    $survivor->judgement() === MutantJudgement::Survived
                    && ($family->isSeenByValue() || $family === MutatorFamily::RemovedCall)
                ) {
                    $reading[] = $survivor;
                }
            }
        }

        return $reading;
    }

    /** @return list<WeakTest> those of these tests that are weak, in their order */
    private static function weakList(TestIds $tests, KillMatrix $matrix, TestFiles $files): array
    {
        $weak = [];

        foreach ($tests as $test) {
            $named = $matrix->names()->testOf($test);

            if (! $named instanceof TestName) {
                continue;
            }

            foreach ($files->assertionsOf($named)->weakAs($test, $named) as $found) {
                $weak[] = $found;
            }
        }

        return $weak;
    }

    /** @param ByPath<Contents> $files */
    private static function functionOf(JudgedMutant $survivor, ByPath $files): string|Nameless
    {
        $location = $survivor->mutant()->location();
        $source = $files->at($location->file(), Missing::at($location->file()));

        return $source instanceof Contents ? Functions::in($source)->around($location->start()) : Nameless::code();
    }
}
