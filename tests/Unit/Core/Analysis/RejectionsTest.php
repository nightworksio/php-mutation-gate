<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Analysis\Finding;
use NightWorksIO\MutationGate\Core\Analysis\Rejection;
use NightWorksIO\MutationGate\Core\Analysis\Rejections;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;

it('keeps what rejected each mutant as more are rejected', function (): void {
    $first = MutantId::parse('aaaaaaaaaaaa');
    $second = MutantId::parse('bbbbbbbbbbbb');
    $rejection = Rejection::by('phpstan', Finding::error(Path::of('src/Money.php'), 'return.type', 'Wrong type.'));
    $rejections = $first instanceof MutantId && $second instanceof MutantId
        ? Rejections::none()->with($first, $rejection)->with($second, $rejection)
        : Rejections::none();

    expect($first instanceof MutantId ? $rejections->of($first) : null)->toBe($rejection)
        ->and($rejections)->toHaveCount(2);
});
