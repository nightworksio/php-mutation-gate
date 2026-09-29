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
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

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
        ->and($mutant->duration())->toEqual(Seconds::of(0.4))
        ->and($mutant->limit())->toEqual(Unmeasured::duration());
});

it('carries the seconds its runner allowed it, keeping the rest of its record', function (): void {
    $id = MutantId::hash(Path::of('src/Money.php'), 'LessThan', "-<\n+<=", 0);
    $location = Location::of(Path::of('src/Money.php'), Line::of(42), Line::of(42));
    $mutation = Mutation::of('LessThan', MutatorFamily::Boundary, "-<\n+<=");
    $mutant = Mutant::of($id, '9a0b7e', $location, $mutation, MutantStatus::TimedOut, Seconds::of(0.4));
    $limited = $mutant->withLimit(Seconds::of(5.0));

    expect($limited->limit())->toEqual(Seconds::of(5.0))
        ->and($limited->id())->toBe($id)
        ->and($limited->nativeId())->toBe('9a0b7e')
        ->and($limited->location())->toBe($location)
        ->and($limited->mutation())->toBe($mutation)
        ->and($limited->status())->toBe(MutantStatus::TimedOut)
        ->and($limited->duration())->toEqual(Seconds::of(0.4))
        ->and($mutant->limit())->toEqual(Unmeasured::duration());
});
