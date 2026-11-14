<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\QuickRound;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Order\Saved;

it('takes why the tests are unknown as the round\'s first answer, and the changed files alone where it knows no entries', function (): void {
    $round = QuickRound::after(Changes::of(Change::deleted(Path::of('src/Money.php'))));
    $unknown = CannotJudge::because('the map cannot be read');

    expect($round->saved($unknown, CoverageMap::of()))->toBe($unknown)
        ->and($round->saved(NotGiven::value(), CoverageMap::of()))
        ->toEqual(Saved::of(Paths::of(Path::of('src/Money.php')), Paths::none()));
});
