<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Extension\Checks\Records;
use NightWorksIO\MutationGate\Extension\LibraryChange;
use NightWorksIO\MutationGate\Extension\RunnerLibrary;

it('says a mutant whose end is unreported as ending on line 0', function (): void {
    $library = RunnerLibrary::spelling('Plus', 'GreaterThan', 'Minus', 'Decrement', 'Plus', testEnding: 'Spec.php');
    $id = MutantId::firstOf(LibraryChange::Adds->file(), 'Plus', LibraryChange::Adds->diff());

    expect(Records::expected($library->unmeasured(), LibraryChange::Adds))
        ->toBe([sprintf('%s killed src/Money.php:%d-0 Plus arithmetic', $id->value(), LibraryChange::Adds->line())]);
});

it('says why a map cannot be judged', function (): void {
    expect(Records::lines(CannotJudge::because('No map.')))->toBe(['nothing it can judge (No map.)']);
});

it('puts texts in order', function (): void {
    expect(Records::sorted(['b', 'a']))->toBe(['a', 'b']);
});
