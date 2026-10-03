<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\IgnoredMutant;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Tests\Support\Judged;

it('names one mutant by its id, and is named by it', function (): void {
    $mutant = Judged::mutant('a', MutantJudgement::Survived)->mutant();
    $ignored = IgnoredMutant::of($mutant->id(), 'Both branches build the same list', Absent::setting());

    expect($ignored->matches($mutant))->toBeTrue()
        ->and($ignored->matches(Judged::mutant('a', MutantJudgement::Survived, 'src/Ledger.php')->mutant()))->toBeFalse()
        ->and($ignored->named())->toBe($mutant->id()->value());
});
