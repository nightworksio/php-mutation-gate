<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Flow\TrendBefore;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Cost\RunAccount;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Report\Trend;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** The runs a verdict on this scope, with main the default branch, reads from a published trend of one run. */
function trendBeforeOn(Scope $scope): Trend|string
{
    $project = Scratch::directory();
    Scratch::write($project, 'publish/trend.json', '{"format": 1, "runs": [
        {"commit": "b", "time": "2026-09-10T00:00:00Z", "trees": {}, "runnerSeconds": 60, "fullRunSeconds": 3660}
    ]}');

    $previous = TrendBefore::on(RunAccount::none(), RunOn::at($scope, Scope::branch('main')), Directory::at($project), Path::of('publish'))->previous();

    return $previous instanceof Trend ? $previous : $previous::class;
}

it('reads the published trend on the default branch, and none off it', function (): void {
    $on = trendBeforeOn(Scope::branch('main'));

    expect($on instanceof Trend ? $on->json() : $on)->toContain('"commit": "b"')
        ->and(trendBeforeOn(Scope::branch('feature')))->toBeString();
});
