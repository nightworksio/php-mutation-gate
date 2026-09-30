<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function array_keys;
use function array_search;

use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;

use function sprintf;

/**
 * The verdict as SARIF 2.1.0, for code scanning: one run of the tool
 * `mutation-gate`, four rules, and a result for every mutant the score counts
 * as not killed. A result is an error when its mutant is in a set that
 * failed, and a warning otherwise. The gate's id is its partial fingerprint,
 * so a result is matched across commits when code above it moves
 * (ADR-0009, decision 2).
 */
final readonly class Sarif
{
    private const string SCHEMA = 'https://json.schemastore.org/sarif-2.1.0.json';

    private const string VERSION = '2.1.0';

    private const string HOME = 'https://github.com/nightworksio/php-mutation-gate';

    private const string ROOT = '%SRCROOT%';

    private const string MESSAGE = 'Mutant %s: %s. %s Reproduce: %s';

    /** Each rule, by its id, with what it reports. */
    private const array RULES = [
        'survived' => 'A mutant no test fails on.',
        'uncovered' => 'A mutant on a line no test runs.',
        'unjudged' => 'A mutant the run did not judge, or could not judge in its time limit.',
        'flaky' => 'A mutant its tests killed on one run and not on another.',
    ];

    public static function json(Verdict $verdict): string
    {
        $overview = Overview::of($verdict);
        $rules = [];
        $results = [];

        foreach (self::RULES as $id => $text) {
            $rules[] = ['id' => $id, 'shortDescription' => ['text' => $text], 'helpUri' => self::HOME];
        }

        foreach ($overview->survivors() as $mutant) {
            $results[] = self::result($mutant, $overview->isFailing($mutant));
        }

        return Json::encode([
            '$schema' => self::SCHEMA,
            'version' => self::VERSION,
            'runs' => [[
                'tool' => ['driver' => ['name' => 'mutation-gate', 'informationUri' => self::HOME, 'rules' => $rules]],
                'originalUriBaseIds' => [self::ROOT => ['description' => ['text' => 'The repository root']]],
                'results' => $results,
            ]],
        ]);
    }

    /** @return array<string, mixed> */
    private static function result(JudgedMutant $judged, bool $failing): array
    {
        $mutant = $judged->mutant();
        $rule = self::ruleOf($judged->judgement());
        $end = $mutant->location()->end();
        $mutator = Mutator::short($mutant->mutation()->mutator());

        return [
            'ruleId' => $rule,
            'ruleIndex' => array_search($rule, array_keys(self::RULES), strict: true),
            'level' => $failing ? 'error' : 'warning',
            'message' => ['text' => sprintf(
                self::MESSAGE,
                Label::of($judged->judgement()),
                $mutator,
                $judged->hint()->text(),
                $judged->reproduce(),
            )],
            'locations' => [[
                'physicalLocation' => [
                    'artifactLocation' => ['uri' => $mutant->location()->file()->value(), 'uriBaseId' => self::ROOT],
                    'region' => [
                        'startLine' => $mutant->location()->start()->number(),
                        ...$end instanceof Line ? ['endLine' => $end->number()] : [],
                    ],
                ],
            ]],
            'partialFingerprints' => ['primaryLocationLineHash' => $mutant->id()->value()],
            'properties' => [
                'id' => $mutant->id()->value(),
                'mutator' => $mutant->mutation()->mutator(),
                'judgement' => $judged->judgement()->value,
                'diff' => $mutant->mutation()->diff(),
                'reproduce' => $judged->reproduce(),
            ],
        ];
    }

    /** The rule a mutant counted as not killed is reported under; unjudged covers those too slow to judge. */
    private static function ruleOf(MutantJudgement $judgement): string
    {
        return match ($judgement) {
            MutantJudgement::Uncovered => 'uncovered',
            MutantJudgement::Unjudged, MutantJudgement::TooSlowToJudge => 'unjudged',
            MutantJudgement::Flaky => 'flaky',
            MutantJudgement::Survived,
            MutantJudgement::Killed,
            MutantJudgement::Errored,
            MutantJudgement::KilledByTimeout,
            MutantJudgement::Ignored,
            MutantJudgement::IgnoredByMarker => 'survived',
        };
    }
}
