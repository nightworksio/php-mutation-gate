<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Report\Badge;
use NightWorksIO\MutationGate\Core\Report\BadgeColors;
use NightWorksIO\MutationGate\Core\Score\NothingToMutate;
use NightWorksIO\MutationGate\Core\Score\Score;

it('writes the project score for shields.io\'s endpoint badge', function (): void {
    expect(json_decode(Badge::json(Score::ofHundredths(8_741), BadgeColors::defaults()), associative: true))->toBe([
        'schemaVersion' => 1,
        'label' => 'mutation score',
        'message' => '87.41%',
        'color' => 'green',
    ]);
});

it('says nothing to mutate rather than 100%', function (): void {
    expect(json_decode(Badge::json(NothingToMutate::found(), BadgeColors::defaults()), associative: true))
        ->toMatchArray(['message' => 'nothing to mutate', 'color' => 'lightgrey']);
});
