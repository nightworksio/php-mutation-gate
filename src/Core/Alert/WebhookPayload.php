<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Alert;

use NightWorksIO\MutationGate\Core\Baseline\Lowered;
use NightWorksIO\MutationGate\Core\Ci\CiRun;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;

/**
 * An alert as the `webhook` reporter posts it, format 1: the event, the
 * repository, ref, commit and run, the verdict, each tree's floor, score and
 * previous score with its lowered floor where it went down, and why the run
 * cannot judge. A field a tree has no value for is left out. Its schema is
 * `resources/webhook.schema.json` (ADR-0016, decision 12).
 *
 * @phpstan-type Lowering array{from: float, reason?: string}
 * @phpstan-type TreeEntry array{path: string, floor?: float, score?: float, previous?: float, lowered?: Lowering}
 */
final readonly class WebhookPayload
{
    /** The version of this format, which changes only when a reader would misread the new one. */
    public const int FORMAT = 1;

    public static function json(Alert $alert, CiRun $run): string
    {
        $trees = [];

        foreach ($alert->verdict()->trees() as $tree) {
            $trees[] = self::tree($alert, $tree);
        }

        $reasons = [];

        foreach ($alert->verdict()->obstacles() as $obstacle) {
            $reasons[] = $obstacle->why();
        }

        return JsonText::encode([
            'format' => self::FORMAT,
            'event' => $alert->event()->value,
            'repository' => $run->repository(),
            'ref' => $run->ref(),
            'commit' => $run->commit(),
            'run' => $run->url(),
            'verdict' => $alert->verdict()->judgement()->value,
            'trees' => $trees,
            'cannotJudge' => $reasons,
        ]);
    }

    /** @return TreeEntry */
    private static function tree(Alert $alert, TreeVerdict $tree): array
    {
        $path = $tree->tree()->path();
        $floor = $tree->floor();
        $score = $tree->score();
        $previous = $alert->previous()->scoreOf($path);
        $lowered = self::lowered($alert, $path);

        return [
            'path' => $path->value(),
            ...$floor instanceof Floor ? ['floor' => $floor->percent()] : [],
            ...$score instanceof Score ? ['score' => $score->percent()] : [],
            ...$previous instanceof Score ? ['previous' => $previous->percent()] : [],
            ...$lowered === [] ? [] : ['lowered' => $lowered],
        ];
    }

    /**
     * The floor a tree went down from, and why; nothing where it did not.
     *
     * @return Lowering|array{}
     */
    private static function lowered(Alert $alert, Path $tree): array
    {
        foreach ($alert->lowered() as $floor) {
            if ($floor->tree()->equals($tree)) {
                $lowering = $floor->lowering();

                return [
                    'from' => $floor->from()->percent(),
                    ...$lowering instanceof Lowered ? ['reason' => $lowering->reason()] : [],
                ];
            }
        }

        return [];
    }
}
