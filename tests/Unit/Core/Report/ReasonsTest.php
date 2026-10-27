<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Report\Reasons;
use NightWorksIO\MutationGate\Core\Report\SetText;
use NightWorksIO\MutationGate\Tests\Support\Owners;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

it('says each set below its floor, each failure, that the run stopped short, then the project\'s score', function (): void {
    $why = [
        'src scores 44.44%, below its floor of 80.00%. That is -2.50 against the base.',
        'New code scores 0.00%, below its floor of 100.00%.',
        'The ignore of 3f9a1c2b7d04 matched no mutant. Remove it.',
    ];

    expect(Reasons::of(Verdicts::failing()))->toBe([...$why, 'The project scores 44.44%.'])
        ->and(Reasons::of(Verdicts::failing()->cutShort()))->toBe([...$why, SetText::CUT_SHORT, 'The project scores 44.44%.']);
});

it('says every tree below its floor', function (): void {
    expect(Reasons::of(Verdicts::crowded()))->toHaveCount(31);
});

it('says each owner below its floor after the trees, and nothing of one that meets it', function (): void {
    expect(Reasons::of(Owners::floored()))->toBe([
        'lib scores 0.00%, below its floor of 50.00%.',
        'Code owned by @acme/payments scores 33.33%, below its floor of 50.00%.',
        'The project scores 50.00%.',
    ]);
});
