<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Proof\Agreement;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Proofs;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Unkeyed;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Verdict\Origin;
use NightWorksIO\MutationGate\Core\Verdict\UnitResult;
use NightWorksIO\MutationGate\Core\Verdict\UnitResults;
use NightWorksIO\MutationGate\Tests\Support\Moment;

$mutant = static fn(string $file, MutantStatus $status): Mutant => Mutant::of(
    MutantId::hash(Path::of($file), 'LessThan', '', 0),
    '1',
    Location::of(Path::of($file), Line::of(1), Line::of(1)),
    Mutation::of('LessThan', MutatorFamily::Boundary, ''),
    $status,
    Unmeasured::duration(),
);
$proof = static fn(string $key, string $file, MutantStatus $status): Proof => Proof::of(
    Digest::of($key),
    Path::of($file),
    Mutants::of($mutant($file, $status)),
    Run::of('run', Moment::at('2026-09-29T10:00:00Z'), Digest::of(str_repeat('b', 64))),
);
$ran = static fn(string $file, MutantStatus $status): UnitResult => UnitResult::of(
    Unit::file(Path::of($file)),
    Origin::Run,
    Mutants::of($mutant($file, $status)),
);

it('marks flaky the mutants a proof under a result\'s key disagrees on, in either ledger', function () use (
    $proof,
    $ran,
    $mutant,
): void {
    $keys = Keys::none()
        ->with(Path::of('src/A.php'), Digest::of('a'))
        ->with(Path::of('src/B.php'), Digest::of('b'))
        ->with(Path::of('src/C.php'), Unkeyed::because('No key.'))
        ->with(Path::of('src/D.php'), Digest::of('d'));

    $checked = [...Agreement::checked(
        UnitResults::of(
            $ran('src/A.php', MutantStatus::Survived),
            $ran('src/B.php', MutantStatus::Killed),
            $ran('src/C.php', MutantStatus::Survived),
            $ran('src/D.php', MutantStatus::Survived),
        ),
        $keys,
        Proofs::of($proof('a', 'src/A.php', MutantStatus::Killed), $proof('b', 'src/B.php', MutantStatus::Killed)),
        Proofs::of($proof('d', 'src/D.php', MutantStatus::Killed)),
    )];

    expect(array_map(static fn(UnitResult $result): MutantIds => $result->flaky(), $checked))->toEqual([
        MutantIds::of($mutant('src/A.php', MutantStatus::Killed)->id()),
        MutantIds::none(),
        MutantIds::none(),
        MutantIds::of($mutant('src/D.php', MutantStatus::Killed)->id()),
    ]);
});
