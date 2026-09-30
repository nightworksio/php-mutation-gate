<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Core\Ci\CiRun;
use NightWorksIO\MutationGate\Core\Cost\RunAccount;
use NightWorksIO\MutationGate\Core\Report\Trend;

use function sprintf;

/** Trends a verdict on the default branch is handed, and the CI run an alert names. */
final class Previous
{
    /** A verdict's account after one run that judged this, holding `src` to this floor at this score. */
    public static function run(string $judgement, float $floor = 80.0, float $score = 81.0): RunAccount
    {
        return RunAccount::none()->after(Trend::decode(sprintf(
            '{"format": 1, "runs": [{"commit": "a", "time": "2026-09-29T10:00:00Z", "verdict": "%s", '
            . '"trees": {"src": %s}, "floors": {"src": %s}}]}',
            $judgement,
            $score,
            $floor,
        )));
    }

    /** A verdict's account on a default branch whose trend has no run yet. */
    public static function none(): RunAccount
    {
        return RunAccount::none()->after(Trend::none());
    }

    /** The CI run the alerts in the tests come from. */
    public static function ci(): CiRun
    {
        return CiRun::of(
            'octo/gate',
            'refs/heads/main',
            '5eeca8f0d2b1c4a7e9f3a6b8c0d2e4f6a8b0c2d4',
            'https://github.example/octo/gate/actions/runs/7',
        );
    }
}
