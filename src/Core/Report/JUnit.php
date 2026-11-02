<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function array_filter;
use function array_map;
use function count;
use function implode;

use NightWorksIO\MutationGate\Core\GateCommand;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Verdict\Judgement;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\NewCodeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\OwnerFloorVerdict;
use NightWorksIO\MutationGate\Core\Verdict\SecurityVerdict;
use NightWorksIO\MutationGate\Core\Verdict\Survivors;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;

use function sprintf;

/**
 * The verdict as JUnit XML: a suite per tree, one for new code and one for
 * the packages' security sets, each with one test case per floor that fails
 * exactly when the gate fails that set, a
 * `run` suite with a test case for each thing that kept the run from judging
 * and each failure that belongs to no floor, and an `ignored` suite with a
 * skipped test case for each mutant an ignore left out, with why
 * (ADR-0008). JUnit failures match gate failures one to one (ADR-0009).
 *
 * @internal
 */
final readonly class JUnit
{
    private const string DECLARATION = '<?xml version="1.0" encoding="UTF-8"?>';

    private const string SUITES = '<testsuites name="mutation-gate" tests="%d" failures="%d">';

    private const string SUITE = '  <testsuite name="%s" tests="%d" failures="%d">';

    private const string CASE = '    <testcase name="%s" classname="%s">%s</testcase>';

    private const string FAILED = '<failure type="%s" message="%s">%s</failure>';

    private const string SKIPPED = '<skipped message="%s"/>';

    private const string OUTPUT = '<system-out>%s</system-out>';

    private const string NEW_CODE = 'new code';

    private const string SECURITY = 'security';

    private const string OWNERS = 'owners';

    /** The case of a set's floor. */
    private const string FLOOR = 'floor';

    public static function xml(Verdict $verdict): string
    {
        $suites = [];

        $names = $verdict->matrix()->names();
        $gate = Overview::of($verdict)->gate();

        foreach ($verdict->trees() as $tree) {
            $suites[] = self::suite($tree->tree()->path()->value(), [self::tree($tree, $names, $gate)]);
        }

        $sets = $verdict->sets();
        $suites = [
            ...$suites,
            ...self::named(
                self::NEW_CODE,
                array_map(
                    static fn(NewCodeVerdict $set): array => self::newCode($set, $names, $gate),
                    [...$sets->newCode()],
                ),
            ),
            ...self::named(
                self::SECURITY,
                array_map(
                    static fn(SecurityVerdict $set): array => self::security($set, $names, $gate),
                    [...$sets->security()],
                ),
            ),
            ...self::named(
                self::OWNERS,
                array_map(
                    static fn(OwnerFloorVerdict $owner): array => self::ownerFloor($owner, $names, $gate),
                    [...$sets->ownerFloors()],
                ),
            ),
            ...self::named('run', self::run($verdict)),
            ...self::named(MutantJudgement::Ignored->value, self::ignored($verdict)),
        ];

        $tests = 0;
        $failures = 0;

        foreach ($suites as [, $count, $failed]) {
            $tests += $count;
            $failures += $failed;
        }

        return implode("\n", [
            self::DECLARATION,
            sprintf(self::SUITES, $tests, $failures),
            ...array_map(static fn(array $suite): string => $suite[0], $suites),
            '</testsuites>',
            '',
        ]);
    }

    /**
     * The suite of these test cases, where there are any; none where there are not.
     *
     * @param  list<array{string, bool}>     $cases
     * @return list<array{string, int, int}>
     */
    private static function named(string $name, array $cases): array
    {
        return $cases === [] ? [] : [self::suite($name, $cases)];
    }

    /**
     * A suite of test cases, each written with whether it failed.
     *
     * @param  list<array{string, bool}> $cases
     * @return array{string, int, int}   the suite written, its tests, its failures
     */
    private static function suite(string $name, array $cases): array
    {
        $failures = count(array_filter($cases, static fn(array $case): bool => $case[1]));

        return [
            implode("\n", [
                sprintf(self::SUITE, Xml::text($name), count($cases), $failures),
                ...array_map(static fn(array $case): string => $case[0], $cases),
                '  </testsuite>',
            ]),
            count($cases),
            $failures,
        ];
    }

    /** @return array{string, bool} */
    private static function tree(TreeVerdict $tree, TestNames $names, GateCommand $gate): array
    {
        return self::case(
            self::FLOOR,
            $tree->tree()->path()->value(),
            $tree->judgement(),
            SetText::tree($tree),
            $tree->survivors(),
            $names,
            $gate,
        );
    }

    /** @return array{string, bool} a package's security set, as the `floor` case of the package */
    private static function security(SecurityVerdict $set, TestNames $names, GateCommand $gate): array
    {
        return self::case(
            self::FLOOR,
            $set->package()->path()->value(),
            $set->judgement(),
            SetText::security($set),
            $set->survivors(),
            $names,
            $gate,
        );
    }

    /** @return array{string, bool} an owner held to a floor, as the `floor` case of the owner */
    private static function ownerFloor(OwnerFloorVerdict $owner, TestNames $names, GateCommand $gate): array
    {
        return self::case(
            self::FLOOR,
            $owner->owner()->value(),
            $owner->judgement(),
            SetText::ownerFloor($owner),
            $owner->survivors(),
            $names,
            $gate,
        );
    }

    /** @return array{string, bool} */
    private static function newCode(NewCodeVerdict $set, TestNames $names, GateCommand $gate): array
    {
        return self::case(
            $set->package()->path()->value(),
            self::NEW_CODE,
            $set->judgement(),
            SetText::newCode($set),
            $set->survivors(),
            $names,
            $gate,
        );
    }

    /**
     * A failing test case for each thing that kept the run from judging, and
     * for each set of tests that fails without any mutant, the mutants it
     * leaves out in its body; then for each failure that belongs to no floor.
     *
     * @return list<array{string, bool}>
     */
    private static function run(Verdict $verdict): array
    {
        $cases = [];

        foreach ($verdict->obstacles()->met() as $obstacle) {
            $cases[] = self::failure(Judgement::CannotJudge->value, $obstacle->why());
        }

        foreach ($verdict->obstacles()->unmutatedFailures() as $failing) {
            $cases[] = self::failure(Judgement::CannotJudge->value, $failing->line(), ...FailingText::lines($failing));
        }

        foreach ($verdict->failures() as $failure) {
            $cases[] = self::failure('run', $failure->text());
        }

        return $cases;
    }

    /**
     * A skipped test case for each mutant an ignore left out, named by its
     * heading, with why it is ignored.
     *
     * @return list<array{string, bool}>
     */
    private static function ignored(Verdict $verdict): array
    {
        $cases = [];

        foreach (Overview::of($verdict)->ignored() as $mutant) {
            $cases[] = [sprintf(
                self::CASE,
                Xml::text(MutantText::heading($mutant)),
                MutantJudgement::Ignored->value,
                sprintf(self::SKIPPED, Xml::text(MutantText::ignoredBecause($mutant))),
            ), false];
        }

        return $cases;
    }

    /** @return array{string, bool} */
    private static function failure(string $type, string $text, string ...$body): array
    {
        $message = Xml::text($text);
        $said = $body === [] ? $message : Xml::text(implode("\n", $body));

        return [sprintf(self::CASE, $message, 'run', sprintf(self::FAILED, $type, $message, $said)), true];
    }

    /** @return array{string, bool} */
    private static function case(
        string $name,
        string $class,
        Judgement $judgement,
        string $said,
        Survivors $survivors,
        TestNames $names,
        GateCommand $gate,
    ): array {
        [$body, $failed] = match ($judgement) {
            Judgement::Failed, Judgement::CannotJudge => [
                sprintf(
                    self::FAILED,
                    'floor',
                    Xml::text($said),
                    Xml::text(self::listing($said, $survivors, $names, $gate)),
                ),
                true,
            ],
            Judgement::Undecided => [sprintf(self::FAILED, 'undecided', Xml::text($said), Xml::text($said)), true],
            Judgement::Exempt => [sprintf(self::SKIPPED, Xml::text($said)), false],
            Judgement::Passed, Judgement::NothingToMutate => [sprintf(self::OUTPUT, Xml::text($said)), false],
        };

        return [sprintf(self::CASE, Xml::text($name), Xml::text($class), $body), $failed];
    }

    /** What a failed floor says, then every mutant it counts as not killed. */
    private static function listing(string $said, Survivors $survivors, TestNames $names, GateCommand $gate): string
    {
        $blocks = [$said];

        foreach ($survivors as $survivor) {
            $blocks[] = MutantText::block($survivor, $names, $gate);
        }

        return implode("\n\n", $blocks);
    }
}
