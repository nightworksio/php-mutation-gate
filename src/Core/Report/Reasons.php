<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use NightWorksIO\MutationGate\Core\Verdict\Judgement;
use NightWorksIO\MutationGate\Core\Verdict\NewCodeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\OwnerFloorVerdict;
use NightWorksIO\MutationGate\Core\Verdict\SecurityVerdict;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;

/**
 * Why a verdict is what it is, a sentence each, in the JSON report's `why`
 * and so in every report rendered from it (ADR-0009). What decides it comes
 * first: each set below its floor, as every report says it, what kept the
 * run from judging, and each failure no floor decides; then that the run was
 * cut short, where it was, and last the project's score.
 *
 * @internal
 */
final readonly class Reasons
{
    /** @return list<string> */
    public static function of(Verdict $verdict): array
    {
        $why = [
            ...self::failed($verdict->trees(), SetText::tree(...)),
            ...self::failed($verdict->sets()->newCode(), SetText::newCode(...)),
            ...self::failed($verdict->sets()->security(), SetText::security(...)),
            ...self::failed($verdict->sets()->ownerFloors(), SetText::ownerFloor(...)),
        ];

        foreach ($verdict->obstacles() as $obstacle) {
            $why[] = $obstacle->why();
        }

        foreach ($verdict->failures() as $failure) {
            $why[] = $failure->text();
        }

        $why = $verdict->wasCutShort() ? [...$why, SetText::CUT_SHORT] : $why;

        return [...$why, SetText::project(Overview::of($verdict)->score())];
    }

    /**
     * What every report says of each set below its floor.
     *
     * @template T of TreeVerdict|NewCodeVerdict|SecurityVerdict|OwnerFloorVerdict
     *
     * @param  iterable<T>         $sets
     * @param  callable(T): string $said
     * @return list<string>
     */
    private static function failed(iterable $sets, callable $said): array
    {
        $failed = [];

        foreach ($sets as $set) {
            $failed = $set->judgement() === Judgement::Failed ? [...$failed, $said($set)] : $failed;
        }

        return $failed;
    }
}
