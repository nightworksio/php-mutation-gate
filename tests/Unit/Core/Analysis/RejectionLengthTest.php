<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Analysis\Finding;
use NightWorksIO\MutationGate\Core\Analysis\RejectionLength;
use NightWorksIO\MutationGate\Core\File\Path;

it('cuts a finding\'s code to 128 characters and its message to 1,024, each ending in an ellipsis, and keeps its file', function (): void {
    $cut = RejectionLength::standard()->cut(Finding::lesser(Path::of('src/Money.php'), str_repeat('é', 129), str_repeat('x', 1025)));

    expect($cut)->toEqual(Finding::error(Path::of('src/Money.php'), sprintf('%s…', str_repeat('é', 127)), sprintf('%s…', str_repeat('x', 1023))));
});

it('keeps a finding within its lengths whole', function (): void {
    expect(RejectionLength::standard()->cut(Finding::error(Path::of('src/Money.php'), 'return.type', 'Method Money::of() should return int.')))
        ->toEqual(Finding::error(Path::of('src/Money.php'), 'return.type', 'Method Money::of() should return int.'));
});
