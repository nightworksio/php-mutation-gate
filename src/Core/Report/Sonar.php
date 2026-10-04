<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function array_key_exists;
use function implode;

use NightWorksIO\MutationGate\Core\Cluster\Unplaced;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\ThisPackage;
use NightWorksIO\MutationGate\Core\Troubleshooting\Guide;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;

use function sprintf;

/**
 * The verdict as SonarQube's generic external-issues format, as SonarQube
 * Server 10.3 and later and SonarQube Cloud read it from
 * `sonar.externalIssuesReportPaths`: five rules of the engine
 * `mutation-gate`, and an issue for every mutant the score counts as not
 * killed, at its file, lines and columns, with SARIF's message
 * (ADR-0028, decisions 7 to 9). An issue leaves its effort out, so
 * SonarQube's default of none applies.
 *
 * @phpstan-type Range array{startLine: int, endLine?: int, startColumn?: int, endColumn?: int}
 * @phpstan-type Issue array{
 *     ruleId: value-of<SonarRule>,
 *     primaryLocation: array{message: string, filePath: string, textRange: Range},
 * }
 */
final readonly class Sonar
{
    /** The attribute of clean code every rule's mutants lack. */
    private const string ATTRIBUTE = 'TESTED';

    /** Every rule's severity, which SonarQube reads per rule and never per issue. */
    private const string SEVERITY = 'MEDIUM';

    /** How far before the gate's column, counted from 1, SonarQube's is, counted from 0. */
    private const int FROM_ZERO = 1;

    private const string UNDER = '%d under %s';

    private const string ISSUES = 'Issues by top directory: %s.';

    public static function json(Verdict $verdict, Sources $sources, Guide $guide): string
    {
        $overview = Overview::of($verdict);
        $survivors = $overview->survivors();
        $columns = FileColumns::of($survivors, $sources);
        $rules = [];
        $issues = [];

        foreach (SonarRule::cases() as $rule) {
            $rules[] = [
                'id' => $rule->value,
                'name' => $rule->title(),
                'description' => $rule->description($guide),
                'engineId' => ThisPackage::NAME,
                'cleanCodeAttribute' => self::ATTRIBUTE,
                'impacts' => [['softwareQuality' => $rule->quality()->value, 'severity' => self::SEVERITY]],
            ];
        }

        foreach ($survivors as $judged) {
            $issues[] = self::issue($judged, $columns->in($judged->mutant()->location()->file()), $overview);
        }

        return JsonText::encode(['rules' => $rules, 'issues' => $issues]);
    }

    /**
     * How many issues the report holds under each directory at the root,
     * in the order their first issue comes, which says how many SonarQube
     * drops where `sonar.sources` leaves a directory out; nothing where it
     * holds none (ADR-0028, decision 10).
     */
    public static function tally(Verdict $verdict): string
    {
        $counts = [];

        foreach (Overview::of($verdict)->survivors() as $judged) {
            $top = $judged->mutant()->location()->file()->top()->value();
            $counts[$top] = array_key_exists($top, $counts) ? $counts[$top] + 1 : 1;
        }

        $under = [];

        foreach ($counts as $top => $count) {
            $under[] = sprintf(self::UNDER, $count, $top);
        }

        return $under === [] ? '' : sprintf(self::ISSUES, implode(', ', $under));
    }

    /** @return Issue */
    private static function issue(JudgedMutant $judged, Columns $columns, Overview $overview): array
    {
        $mutant = $judged->mutant();
        $rule = SonarRule::of(ResultRule::of($judged->judgement()));

        return [
            'ruleId' => ($overview->isSecurity($judged) ? $rule->ofSecurity() : $rule)->value,
            'primaryLocation' => [
                'message' => MutantText::message($judged),
                'filePath' => $mutant->location()->file()->value(),
                'textRange' => self::range($judged, $columns),
            ],
        ];
    }

    /**
     * The mutant's lines, and where its tokens are found, the columns of its
     * change. SonarQube counts columns from 0 and ends a range before the
     * column it names, where the gate counts from 1.
     *
     * @return Range
     */
    private static function range(JudgedMutant $judged, Columns $columns): array
    {
        $location = $judged->mutant()->location();
        $end = $location->end();
        $found = $columns->found($judged->mutant());

        return $found instanceof Unplaced
            ? [
                'startLine' => $location->start()->number(),
                ...$end instanceof Line ? ['endLine' => $end->number()] : [],
            ]
            : [
                'startLine' => $found['start']['line'],
                'startColumn' => $found['start']['column'] - self::FROM_ZERO,
                'endLine' => $found['end']['line'],
                'endColumn' => $found['end']['column'] - self::FROM_ZERO,
            ];
    }
}
