<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\GateCommand;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;

/**
 * The verdict as GitLab's Code Quality JSON, so a merge request shows each
 * mutant counted as not killed on its line: one issue per mutant, under the
 * rule SARIF reports it by, fingerprinted by the gate's id, `major` in a set
 * that failed and `minor` otherwise (ADR-0016).
 *
 * @internal
 *
 * @phpstan-type Issue array{
 *     description: string,
 *     check_name: value-of<ResultRule>,
 *     fingerprint: string,
 *     severity: 'major'|'minor',
 *     location: array{path: string, lines: array{begin: int}},
 * }
 */
final readonly class CodeQuality
{
    public static function json(Verdict $verdict): string
    {
        $overview = Overview::of($verdict);
        $issues = [];

        foreach ($overview->survivors() as $mutant) {
            $issues[] = self::issue($mutant, $overview->isFailing($mutant), $overview->gate());
        }

        return JsonText::encode($issues);
    }

    /** @return Issue */
    private static function issue(JudgedMutant $judged, bool $failing, GateCommand $gate): array
    {
        $mutant = $judged->mutant();

        return [
            'description' => MutantText::message($judged, $gate),
            'check_name' => ResultRule::of($judged->judgement())->value,
            'fingerprint' => $mutant->id()->value(),
            'severity' => $failing ? 'major' : 'minor',
            'location' => [
                'path' => $mutant->location()->file()->value(),
                'lines' => ['begin' => $mutant->location()->start()->number()],
            ],
        ];
    }
}
