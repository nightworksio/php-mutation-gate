<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Analysis\Finding;
use NightWorksIO\MutationGate\Core\Analysis\Rejection;

it('names the analyser that rejected a mutant and the error it found', function (): void {
    $finding = Finding::error('return.type', 'Method Money::of() should return int but returns string.');
    $rejection = Rejection::by('phpstan', $finding);

    expect($rejection->analyser())->toBe('phpstan')
        ->and($rejection->finding())->toBe($finding);
});
