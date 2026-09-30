<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;

/**
 * The verdict as SARIF 2.1.0, for code scanning: one run of the tool
 * `mutation-gate`, four rules, and a result for every mutant the score counts
 * as not killed. A result is an error when its mutant is in a set that
 * failed, and a warning otherwise. The gate's id is its partial fingerprint,
 * so a result is matched across commits when code above it moves
 * (ADR-0009, decision 2).
 *
 * @phpstan-type Result array{
 *     ruleId: value-of<ResultRule>,
 *     ruleIndex: int,
 *     level: string,
 *     message: array{text: string},
 *     locations: list<array{physicalLocation: array{
 *         artifactLocation: array{uri: string, uriBaseId: string},
 *         region: array{startLine: int, endLine?: int},
 *     }}>,
 *     partialFingerprints: array{primaryLocationLineHash: string},
 *     properties: array{id: string, mutator: string, judgement: string, diff: string, reproduce: string},
 * }
 */
final readonly class Sarif
{
    private const string SCHEMA = 'https://json.schemastore.org/sarif-2.1.0.json';

    private const string VERSION = '2.1.0';

    private const string HOME = 'https://github.com/nightworksio/php-mutation-gate';

    private const string ROOT = '%SRCROOT%';

    /** The report as CI uploads it, with paths relative to a root it does not name. */
    public static function json(Verdict $verdict): string
    {
        return self::encoded($verdict, []);
    }

    /** The report for an editor on this machine, which names the root its paths are relative to. */
    public static function rootedAt(Verdict $verdict, SourceRoot $root): string
    {
        return self::encoded($verdict, ['uri' => $root->uri()]);
    }

    /** @param array{uri?: string} $root */
    private static function encoded(Verdict $verdict, array $root): string
    {
        $overview = Overview::of($verdict);
        $rules = [];
        $results = [];

        foreach (ResultRule::cases() as $rule) {
            $rules[] = ['id' => $rule->value, 'shortDescription' => ['text' => $rule->text()], 'helpUri' => self::HOME];
        }

        foreach ($overview->survivors() as $mutant) {
            $results[] = self::result($mutant, $overview->isFailing($mutant));
        }

        return JsonText::encode([
            '$schema' => self::SCHEMA,
            'version' => self::VERSION,
            'runs' => [[
                'tool' => ['driver' => ['name' => 'mutation-gate', 'informationUri' => self::HOME, 'rules' => $rules]],
                'originalUriBaseIds' => [self::ROOT => [...$root, 'description' => ['text' => 'The repository root']]],
                'results' => $results,
            ]],
        ]);
    }

    /** @return Result */
    private static function result(JudgedMutant $judged, bool $failing): array
    {
        $mutant = $judged->mutant();
        $rule = ResultRule::of($judged->judgement());
        $end = $mutant->location()->end();

        return [
            'ruleId' => $rule->value,
            'ruleIndex' => $rule->index(),
            'level' => $failing ? 'error' : 'warning',
            'message' => ['text' => MutantText::message($judged)],
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
}
