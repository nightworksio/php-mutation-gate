<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function array_filter;
use function array_map;
use function count;
use function implode;
use function iterator_to_array;

use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\Judgement;
use NightWorksIO\MutationGate\Core\Verdict\NewCodeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;

use function sprintf;

/**
 * The verdict as JUnit XML: a suite per tree and one for new code, each with
 * one test case per floor that fails exactly when the gate fails that set,
 * and a `run` suite with a test case for each thing that kept the run from
 * judging and each failure that belongs to no floor. JUnit failures match
 * gate failures one to one (ADR-0009, decision 2).
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


    public static function xml(Verdict $verdict): string
    {
        $suites = [];

        foreach ($verdict->trees() as $tree) {
            $suites[] = self::suite($tree->tree()->path()->value(), [self::tree($tree)]);
        }

        if (count($verdict->newCode()) > 0) {
            $sets = iterator_to_array($verdict->newCode(), preserve_keys: false);
            $suites[] = self::suite(self::NEW_CODE, array_map(self::newCode(...), $sets));
        }

        $run = self::run($verdict);

        if ($run !== []) {
            $suites[] = self::suite('run', $run);
        }

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
    private static function tree(TreeVerdict $tree): array
    {
        $path = $tree->tree()->path()->value();

        return self::case('floor', $path, $tree->judgement(), SetText::tree($tree), $tree->survivors());
    }

    /** @return array{string, bool} */
    private static function newCode(NewCodeVerdict $set): array
    {
        return self::case(
            $set->package()->path()->value(),
            self::NEW_CODE,
            $set->judgement(),
            SetText::newCode($set),
            $set->survivors(),
        );
    }

    /**
     * A failing test case for each thing that kept the run from judging, then
     * for each failure that belongs to no floor.
     *
     * @return list<array{string, bool}>
     */
    private static function run(Verdict $verdict): array
    {
        $cases = [];

        foreach ($verdict->obstacles() as $obstacle) {
            $cases[] = self::failure(Judgement::CannotJudge->value, $obstacle->why());
        }

        foreach ($verdict->failures() as $failure) {
            $cases[] = self::failure('run', $failure->text());
        }

        return $cases;
    }

    /** @return array{string, bool} */
    private static function failure(string $type, string $text): array
    {
        $message = Xml::text($text);

        return [sprintf(self::CASE, $message, 'run', sprintf(self::FAILED, $type, $message, $message)), true];
    }

    /** @return array{string, bool} */
    private static function case(
        string $name,
        string $class,
        Judgement $judgement,
        string $said,
        JudgedMutants $survivors,
    ): array {
        [$body, $failed] = match ($judgement) {
            Judgement::Failed, Judgement::CannotJudge => [
                sprintf(self::FAILED, 'floor', Xml::text($said), Xml::text(self::listing($said, $survivors))),
                true,
            ],
            Judgement::Exempt => [sprintf(self::SKIPPED, Xml::text($said)), false],
            Judgement::Passed, Judgement::NothingToMutate => [sprintf(self::OUTPUT, Xml::text($said)), false],
        };

        return [sprintf(self::CASE, Xml::text($name), Xml::text($class), $body), $failed];
    }

    /** What a failed floor says, then every mutant it counts as not killed. */
    private static function listing(string $said, JudgedMutants $survivors): string
    {
        return implode("\n\n", [
            $said,
            ...array_map(MutantText::block(...), iterator_to_array($survivors, preserve_keys: false)),
        ]);
    }
}
