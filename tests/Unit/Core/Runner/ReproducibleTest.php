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
use NightWorksIO\MutationGate\Core\Mutant\ProvedKill;
use NightWorksIO\MutationGate\Core\Runner\Reproducible;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

it('takes the id, the file and the mutator of a mutant a run reported, and of a kill a ledger proved', function (): void {
    $id = MutantId::hash(Path::of('src/Money.php'), 'Plus', '-a', 0);
    $reported = Reproducible::of(Mutant::of(
        $id,
        'n1',
        Location::of(Path::of('src/Money.php'), Line::of(11), Line::of(11)),
        Mutation::of('Plus', MutatorFamily::Arithmetic, '-a'),
        MutantStatus::Survived,
        Unmeasured::duration(),
    ));
    $proved = Reproducible::of(ProvedKill::of($id, Path::of('src/Held.php'), Line::of(3), 'Minus', TestIds::none()));

    expect([$reported->id(), $reported->file(), $reported->mutator()])->toEqual([$id, Path::of('src/Money.php'), 'Plus'])
        ->and([$proved->id(), $proved->file(), $proved->mutator()])->toEqual([$id, Path::of('src/Held.php'), 'Minus']);
});
