<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Invoked;
use NightWorksIO\MutationGate\Core\Analysis\SurvivorChecks;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;

it('passes over, for the run\'s doom, survivors proven equivalent, those a check may yet kill and those judged by a part of the test files alone', function (): void {
    $id = static fn(int $line): MutantId => MutantId::hash(Path::of('src/Money.php'), 'Minus', '- a + b', $line);
    $invoked = new Invoked(
        MutationResult::skippingNone(Mutants::none()),
        MutantIds::none(),
        MutantIds::of($id(1)),
        SurvivorChecks::none(),
        MutantIds::none(),
        MutantIds::of($id(2)),
        MutantIds::of($id(3)),
    );

    expect($invoked->passedOver())->toEqual(MutantIds::of($id(1), $id(2), $id(3)));
});
