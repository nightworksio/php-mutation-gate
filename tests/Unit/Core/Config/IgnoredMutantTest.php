<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\IgnoredMutant;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\Time\Day;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Tests\Support\Judged;

it('names one mutant by its id, and is named by it', function (): void {
    $mutant = Judged::mutant('a', MutantJudgement::Survived)->mutant();
    $ignored = IgnoredMutant::of($mutant->id(), 'Both branches build the same list', Absent::setting());

    expect($ignored->matches($mutant))->toBeTrue()
        ->and($ignored->matches(Judged::mutant('a', MutantJudgement::Survived, 'src/Ledger.php')->mutant()))->toBeFalse()
        ->and($ignored->named())->toBe($mutant->id()->value());
});

it('writes itself as the builder calls it, with the day it expires where it has one', function (): void {
    $id = Judged::mutant('a', MutantJudgement::Survived)->mutant()->id();
    $call = sprintf("Ignore::mutant('%s', because: 'Both build one list'", $id->value());

    expect([
        IgnoredMutant::of($id, 'Both build one list', Day::on(new DateTimeImmutable('2026-12-29')))->php(ProjectRoot::origin()),
        IgnoredMutant::of($id, 'Both build one list', Absent::setting())->php(ProjectRoot::origin()),
    ])->toBe([sprintf("%s, until: '2026-12-29')", $call), sprintf('%s)', $call)]);
});
