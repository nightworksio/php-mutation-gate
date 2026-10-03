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
use NightWorksIO\MutationGate\Core\Order\Enclosing;
use NightWorksIO\MutationGate\Core\Order\Lesson;
use NightWorksIO\MutationGate\Core\Php\Nameless;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

it('holds a mutant and the function it is in, or nameless code', function (): void {
    $mutant = Mutant::of(
        MutantId::hash(Path::of('src/Money.php'), 'Plus', "-a\n+b", 0),
        'n',
        Location::of(Path::of('src/Money.php'), Line::of(3), Line::of(3)),
        Mutation::of('Plus', MutatorFamily::Arithmetic, "-a\n+b"),
        MutantStatus::Killed,
        Unmeasured::duration(),
    );
    $add = Enclosing::named(Path::of('src/Money.php'), 'add');

    expect(Lesson::of($mutant, $add)->mutant())->toBe($mutant)
        ->and(Lesson::of($mutant, $add)->in())->toBe($add)
        ->and(Lesson::of($mutant, Nameless::code())->in())->toEqual(Nameless::code());
});
