<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\SplitEarly;

it('starts a full split-stream run early as its value says', function (SplitEarly $early, bool $unproven, bool $anyMatch, bool $starts): void {
    expect($early->startsEarly($unproven, $anyMatch))->toBe($starts);
})->with([
    'no-match, under unproven' => [SplitEarly::NoMatch, true, true, true],
    'no-match, no proof may match' => [SplitEarly::NoMatch, false, false, true],
    'no-match, a proof may match' => [SplitEarly::NoMatch, false, true, false],
    'always' => [SplitEarly::Always, false, true, true],
    'unproven-only, under unproven' => [SplitEarly::UnprovenOnly, true, true, true],
    'unproven-only, otherwise' => [SplitEarly::UnprovenOnly, false, false, false],
    'never' => [SplitEarly::Never, true, false, false],
]);
