<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Cost\NoHistory;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;

/**
 * `summary.json`, `"format": 1`: what an organisation's dashboard reads of a
 * repository's latest verdict on its default branch (ADR-0024): the commit
 * and time, the verdict, the project's score, each tree's floor and score,
 * the floor for new code, and the mutation time saved over the last 30 days
 * in seconds. A set with nothing to mutate has no score, a tree held to no
 * floor no floor, and a run with no history of what it saved no savings.
 * `resources/summary.schema.json` describes it, and it is public API.
 *
 * @phpstan-type TreeSummary array{path: string, floor?: float, score?: float}
 * @phpstan-type Document array{
 *     format: int,
 *     commit: string,
 *     time: string,
 *     verdict: string,
 *     score?: float,
 *     trees: list<TreeSummary>,
 *     newCode: array{floor: float},
 *     savings?: array{last30Days: float},
 * }
 */
final readonly class Summary
{
    public const int FORMAT = 1;

    /** The file it is written to, beside the badge. */
    public const string FILE = 'summary.json';

    /** @return Document */
    public static function of(
        Verdict $verdict,
        Revision $commit,
        Instant $time,
        Floor $newCode,
        Seconds|NoHistory $saved,
    ): array {
        $score = Overview::of($verdict)->score();
        $trees = [];

        foreach ($verdict->trees() as $tree) {
            $floor = $tree->floor();
            $treeScore = $tree->score();
            $trees[] = [
                'path' => $tree->tree()->path()->value(),
                ...$floor instanceof Floor ? ['floor' => $floor->percent()] : [],
                ...$treeScore instanceof Score ? ['score' => $treeScore->percent()] : [],
            ];
        }

        return [
            'format' => self::FORMAT,
            'commit' => $commit->name(),
            'time' => $time->value(),
            'verdict' => $verdict->judgement()->value,
            ...$score instanceof Score ? ['score' => $score->percent()] : [],
            'trees' => $trees,
            'newCode' => ['floor' => $newCode->percent()],
            ...$saved instanceof Seconds ? ['savings' => ['last30Days' => $saved->seconds()]] : [],
        ];
    }

    public static function json(
        Verdict $verdict,
        Revision $commit,
        Instant $time,
        Floor $newCode,
        Seconds|NoHistory $saved,
    ): string {
        return JsonText::encode(self::of($verdict, $commit, $time, $newCode, $saved));
    }
}
