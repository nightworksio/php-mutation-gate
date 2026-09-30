<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Assertion;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Matrix\KillMatrix;
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
 * and 6).
 */
final readonly class Weakness
{
    /** The files the judging tests of those survivors are in, which `findings()` reads. */
    public static function testFiles(TreeVerdicts $verdicts, KillMatrix $matrix): Paths
    {
        $files = [];

        foreach (self::seenByValue($verdicts) as $survivor) {
            foreach (self::judging($survivor, $matrix) as $test) {
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
     * test files `testFiles()` names, each read once, and the survivors' own
     * files.
     *
     * @param ByPath<Contents> $sources the survivors' own files
     * @param ByPath<Contents> $tests   the test files
     */
    public static function findings(
        TreeVerdicts $verdicts,
        KillMatrix $matrix,
        ByPath $sources,
        ByPath $tests,
    ): Findings {
        $findings = Findings::none();
        $readers = self::readers($tests);

        foreach (self::seenByValue($verdicts) as $survivor) {
            $weak = self::weakAmong(self::judging($survivor, $matrix), $matrix, $readers);

            if ($weak !== []) {
                $finding = WeaklyAsserted::by(self::functionOf($survivor, $sources), ...$weak);
                $findings = $findings->with($survivor->mutant()->id(), $finding);
            }
        }

        return $findings;
    }

    /** The tests that cover a survivor and judge it: every one, or its held unit's group. */
    private static function judging(JudgedMutant $survivor, KillMatrix $matrix): TestIds
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

        foreach ($verdicts as $tree) {
            foreach ($tree->survivors() as $survivor) {
                if (
                    $survivor->judgement() === MutantJudgement::Survived
                    && $survivor->mutant()->mutation()->family()->isSeenByValue()
                ) {
                    $seen[] = $survivor;
                }
            }
        }

        return $seen;
    }

    /**
     * Each test file read for its tests' assertions, once, by its path.
     *
     * @param  ByPath<Contents>              $tests
     * @return array<string, TestAssertions>
     */
    private static function readers(ByPath $tests): array
    {
        $readers = [];

        foreach ($tests as $file => $contents) {
            $readers[$file->value()] = TestAssertions::in($contents);
        }

        return $readers;
    }

    /**
     * @param  array<string, TestAssertions> $readers
     * @return list<WeakTest>                those of these tests that are weak, in their order
     */
    private static function weakAmong(TestIds $tests, KillMatrix $matrix, array $readers): array
    {
        $weak = [];

        foreach ($tests as $test) {
            $named = $matrix->names()->testOf($test);

            if (! $named instanceof TestName) {
                continue;
            }

            foreach (self::assertionsOf($named, $readers)->weakAs($test, $named) as $found) {
                $weak[] = $found;
            }
        }

        return $weak;
    }

    /**
     * The assertions of a named test, read from its file; none assessed where
     * its file was not read.
     *
     * @param array<string, TestAssertions> $readers
     */
    private static function assertionsOf(TestName $named, array $readers): Assertions
    {
        $file = $named->file()->value();

        return array_key_exists($file, $readers)
            ? $readers[$file]->of($named->description())
            : Assertions::notAssessed();
    }

    /** @param ByPath<Contents> $files */
    private static function functionOf(JudgedMutant $survivor, ByPath $files): string|Nameless
    {
        $location = $survivor->mutant()->location();
        $source = $files->at($location->file(), Missing::at($location->file()));

        return $source instanceof Contents ? Functions::in($source)->around($location->start()) : Nameless::code();
    }
}
