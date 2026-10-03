<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\IgnoredPattern;
use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

/** A survivor of this file, made by this mutator of this family. */
function patternMutant(string $file, string $mutator, MutatorFamily $family): Mutant
{
    return Mutant::of(
        MutantId::hash(Path::of($file), $mutator, '-a', 0),
        'n',
        Location::of(Path::of($file), Line::of(3), Line::of(3)),
        Mutation::of($mutator, $family, '-a'),
        MutantStatus::Survived,
        Unmeasured::duration(),
    );
}

it('names the mutants of its mutator, by its full name or its family, in the paths its glob matches', function (string $mutator, Mutant $mutant, bool $matches): void {
    expect(IgnoredPattern::of(Glob::of('src/Log/**'), $mutator, 'Logged elsewhere', Absent::setting())->matches($mutant))
        ->toBe($matches);
})->with([
    'its mutator in a path the glob matches' => ['MethodCallRemoval', patternMutant('src/Log/Writer.php', 'MethodCallRemoval', MutatorFamily::RemovedCall), true],
    'its family' => ['removed-call', patternMutant('src/Log/Writer.php', 'MethodCallRemoval', MutatorFamily::RemovedCall), true],
    'another mutator' => ['MethodCallRemoval', patternMutant('src/Log/Writer.php', 'Plus', MutatorFamily::Arithmetic), false],
    'a path the glob does not match' => ['MethodCallRemoval', patternMutant('src/Money.php', 'MethodCallRemoval', MutatorFamily::RemovedCall), false],
]);

it('is named by its mutator and its glob', function (): void {
    expect(IgnoredPattern::of(Glob::of('src/Log/**'), 'MethodCallRemoval', 'Logged elsewhere', Absent::setting())->named())
        ->toBe('MethodCallRemoval in src/Log/**');
});
