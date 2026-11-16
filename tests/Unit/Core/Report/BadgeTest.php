<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Cost\NoHistory;
use NightWorksIO\MutationGate\Core\Cost\SavedLately;
use NightWorksIO\MutationGate\Core\Cost\SavingsWindow;
use NightWorksIO\MutationGate\Core\Report\Badge;
use NightWorksIO\MutationGate\Core\Report\BadgeColors;
use NightWorksIO\MutationGate\Core\Report\BadgeLogo;
use NightWorksIO\MutationGate\Core\Score\NothingToMutate;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Decoded;

it('writes the project score for shields.io\'s endpoint badge', function (): void {
    expect(json_decode(Badge::json(Score::ofHundredths(8_741), BadgeColors::defaults(), BadgeLogo::none()), associative: true))->toBe([
        'schemaVersion' => 1,
        'label' => 'mutation score',
        'message' => '87.41%',
        'color' => 'green',
    ]);
});

it('says nothing to mutate rather than 100%', function (): void {
    expect(json_decode(Badge::json(NothingToMutate::found(), BadgeColors::defaults(), BadgeLogo::none()), associative: true))
        ->toMatchArray(['message' => 'nothing to mutate', 'color' => 'lightgrey']);
});

it('says what the gate saved in its window, or that there is no history yet', function (): void {
    expect(Decoded::at(Badge::savings(SavedLately::of(Seconds::of(147_600.0), SavingsWindow::standard()), BadgeLogo::none())))->toBe([
        'schemaVersion' => 1,
        'label' => 'mutation time saved',
        'message' => '41h / 30 days',
        'color' => 'blue',
    ])
        ->and(Decoded::at(Badge::savings(NoHistory::yet(), BadgeLogo::none())))->toBe([
            'schemaVersion' => 1,
            'label' => 'mutation time saved',
            'message' => 'no history yet',
            'color' => 'lightgrey',
        ])
        ->and(Decoded::at(Badge::savings(SavedLately::of(Seconds::of(3_600.0), SavingsWindow::of(Seconds::days(1))), BadgeLogo::none())))
        ->toMatchArray(['message' => '1h / 1 day']);
});

it('shows a logo beside the label as shields.io\'s `logoSvg`, trimmed, on both badges', function (): void {
    $logo = BadgeLogo::drawn("<svg/>\n");

    expect(Decoded::at(Badge::json(Score::ofHundredths(9_000), BadgeColors::defaults(), $logo)))
        ->toMatchArray(['logoSvg' => '<svg/>'])
        ->and(Decoded::at(Badge::savings(NoHistory::yet(), $logo)))->toMatchArray(['logoSvg' => '<svg/>'])
        ->and(Decoded::at(Badge::json(Score::ofHundredths(9_000), BadgeColors::defaults(), BadgeLogo::none())))
        ->not->toHaveKey('logoSvg');
});
