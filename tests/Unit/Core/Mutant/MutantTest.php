<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Time\Seconds;

it('is one mutant as its runner reported it', function (): void {
    $id = MutantId::hash(Path::of('src/Money.php'), 'LessThan', "-<\n+<=", 0);
    $location = Location::of(Path::of('src/Money.php'), Line::of(42), Line::of(42));
    $mutation = Mutation::of('LessThan', MutatorFamily::Boundary, "-<\n+<=");
    $mutant = Mutant::of($id, '9a0b7e', $location, $mutation, MutantStatus::Survived, Seconds::of(0.4));

    expect($mutant->id())->toBe($id)
        ->and($mutant->nativeId())->toBe('9a0b7e')
        ->and($mutant->location())->toBe($location)
        ->and($mutant->mutation())->toBe($mutation)
        ->and($mutant->status())->toBe(MutantStatus::Survived)
        ->and($mutant->duration())->toEqual(Seconds::of(0.4));
});
