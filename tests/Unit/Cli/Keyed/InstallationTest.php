<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Keyed\Installation;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('lets a command start from the gate\'s own installation, and refuses the project\'s or Composer\'s proxy', function (): void {
    $project = Scratch::directory();
    $bare = Scratch::directory();
    Scratch::write($project, 'vendor/autoload.php', '<?php');
    $own = Scratch::directory();
    $refused = CannotJudge::because(
        "fetch runs from the gate's own installation, never the project's, so it loads none of the project's code.",
    );

    expect([
        Installation::of($project, $own, throughComposer: false)->refusing('fetch'),
        Installation::of($project, sprintf('%s/gone', $own), throughComposer: false)->refusing('fetch'),
        Installation::of($bare, sprintf('%s/gone', $own), throughComposer: false)->refusing('fetch'),
        Installation::of($project, sprintf('%s/vendor', $project), throughComposer: false)->refusing('fetch'),
        Installation::of($project, $own, throughComposer: true)->refusing('fetch'),
    ])->toEqual([NotGiven::value(), NotGiven::value(), NotGiven::value(), $refused, $refused]);
});
