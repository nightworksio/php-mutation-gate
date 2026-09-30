<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Doctor\InstalledRunners;

it('leaves the choice open only where both runners are installed and nothing chooses', function (bool $pest, bool $infection, bool $chosen, bool $open): void {
    expect(InstalledRunners::of(pest: $pest, infection: $infection, chosen: $chosen)->leaveTheChoiceOpen())->toBe($open);
})->with([
    'both, unchosen' => [true, true, false, true],
    'both, chosen' => [true, true, true, false],
    'Pest alone' => [true, false, false, false],
    'Infection alone' => [false, true, false, false],
]);
