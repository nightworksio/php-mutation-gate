<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\Survivors;
use NightWorksIO\MutationGate\Tests\Support\Judged;

it('holds survivors in the order given, numbered from nought', function (): void {
    $first = Judged::mutant('first', MutantJudgement::Survived);
    $second = Judged::mutant('second', MutantJudgement::Uncovered);

    expect(Survivors::of())->toHaveCount(0)
        ->and(Survivors::of($first, $second))->toHaveCount(2)
        ->and(iterator_to_array(Survivors::of($first, $second), preserve_keys: true))->toBe([0 => $first, 1 => $second]);
});
