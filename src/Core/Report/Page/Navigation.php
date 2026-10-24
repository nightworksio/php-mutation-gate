<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report\Page;

use function array_flip;
use function array_key_exists;
use function array_merge;
use function array_unique;
use function array_values;
use function count;
use function json_encode;

use const JSON_HEX_AMP;
use const JSON_HEX_TAG;

use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Report\JsonReport;
use NightWorksIO\MutationGate\Core\Report\MutantJson;

/**
 * What the HTML report's navigation reads: each file with its counts, and,
 * for a file of a unit this run judged, each mutant with what it changed,
 * what it came to, the gate's own judgement, the tests that killed it, or
 * how many cover it and what would kill it. The tests are named once, in a
 * list the mutants point into. It is JSON in a script element of its own,
 * every `<`, `>` and `&` escaped, so nothing a diff or a test name holds can
 * end the element.
 *
 * @phpstan-import-type Report from JsonReport
 * @phpstan-import-type MutantEntry from MutantJson
 * @phpstan-import-type FileEntry from Mutants
 *
 * @phpstan-type Shown array{
 *     id: string,
 *     line: int,
 *     mutator: string,
 *     outcome: string,
 *     judgement: string,
 *     changed: bool,
 *     diff: string,
 *     killedBy: list<int>,
 *     covered: int,
 *     reason?: string,
 *     hint: string,
 *     reproduce: string,
 * }
 */
final readonly class Navigation
{
    /** @param Report $report */
    public static function json(array $report, Mutants $mutants): string
    {
        $listed = $mutants->files();
        $positions = self::killers($listed);
        $names = [];
        $files = [];

        foreach ($positions as $test => $position) {
            $names[$position] = $report['tests'][$test]['name'];
        }

        foreach ($listed as $at => $file) {
            $shown = [];

            foreach ($file['mutants'] as $mutant) {
                $shown[] = self::mutant($mutant, $positions);
            }

            $files[] = self::file($at, $file, $shown);
        }

        return json_encode(['tests' => $names, 'files' => $files], JsonText::FLAGS | JSON_HEX_TAG | JSON_HEX_AMP);
    }

    /**
     * Where each test that killed a mutant the page shows goes in its list of
     * tests, by the test's place in the report's.
     *
     * @param  list<FileEntry> $files
     * @return array<int, int>
     */
    private static function killers(array $files): array
    {
        $killers = [];

        foreach ($files as $file) {
            foreach ($file['mutants'] as $mutant) {
                $killers[] = $mutant['killedBy'];
            }
        }

        return array_flip(array_values(array_unique(array_merge([], ...$killers))));
    }

    /**
     * @param FileEntry   $file
     * @param list<Shown> $shown
     *
     * @return array{
     *     at: int,
     *     path: string,
     *     carried: bool,
     *     survivors: int,
     *     counts: array<string, int>,
     *     mutants: list<Shown>,
     * }
     */
    private static function file(int $at, array $file, array $shown): array
    {
        return [
            'at' => $at,
            'path' => $file['path'],
            'carried' => $file['carried'],
            'survivors' => $file['survivors'],
            'counts' => $file['counts'],
            'mutants' => $shown,
        ];
    }

    /**
     * @param MutantEntry     $mutant
     * @param array<int, int> $positions where each killer goes in the page's list of tests
     *
     * @return Shown
     */
    private static function mutant(array $mutant, array $positions): array
    {
        $killers = [];

        foreach ($mutant['killedBy'] as $test) {
            $killers[] = $positions[$test];
        }

        return [
            'id' => $mutant['id'],
            'line' => $mutant['line'],
            'mutator' => $mutant['mutator'],
            'outcome' => Mutants::outcomeOf($mutant)->value,
            'judgement' => $mutant['judgement'],
            'changed' => $mutant['changedLine'],
            'diff' => array_key_exists('diff', $mutant) ? $mutant['diff'] : '',
            'killedBy' => $killers,
            'covered' => count($mutant['coveredBy']),
            ...array_key_exists('reason', $mutant) ? ['reason' => $mutant['reason']] : [],
            'hint' => $mutant['hint'],
            'reproduce' => $mutant['reproduce'],
        ];
    }
}
