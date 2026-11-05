<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Doctor\Fix\Edit;
use NightWorksIO\MutationGate\Core\Doctor\Fix\FixText;
use NightWorksIO\MutationGate\Core\Doctor\Fix\Repair;
use NightWorksIO\MutationGate\Core\Doctor\Fix\Repairs;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

it('names every finding it cannot fix, each with what to do in its place', function (): void {
    $repairs = Repairs::of(
        Repair::refused(Slug::NoCoverageDriver, 'Install pcov.'),
        Repair::refused(Slug::CoverageEmpty, 'Add a PHPUnit config.'),
    );

    expect(FixText::preview($repairs, []))->toBe(implode("\n\n", [
        FixText::NOTHING,
        implode("\n", [
            'doctor --fix cannot fix:',
            '- no-coverage-driver: Install pcov.',
            '- coverage-empty: Add a PHPUnit config.',
        ]),
    ]))
        ->and(FixText::question($repairs, [Edit::of('a', '', 'b')]))->toBe('Apply 0 changes to 1 file?');
});
