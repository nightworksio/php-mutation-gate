<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Plan\Proving;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Proofs;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Unkeyed;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\UnitResult;
use NightWorksIO\MutationGate\Tests\Support\Judged;
use NightWorksIO\MutationGate\Tests\Support\Moment;

$proof = static fn(string $key, string $unit, string $run): Proof => Proof::of(
    Digest::of($key),
    Path::of($unit),
    Mutants::of(Judged::mutant($run, MutantJudgement::Killed)->mutant()),
    Run::of($run, Moment::at('2026-09-29T10:00:00Z'), Digest::of(str_repeat('b', 64))),
);
$paths = static fn(Units $units): array => array_map(static fn(Unit $unit): string => $unit->path()->value(), [...$units]);
$units = Units::of(Unit::file(Path::of('src/A.php')), Unit::file(Path::of('src/B.php')), Unit::file(Path::of('src/C.php')));
$keys = Keys::none()
    ->with(Path::of('src/A.php'), Digest::of('a'))
    ->with(Path::of('src/B.php'), Digest::of('b'))
    ->with(Path::of('src/C.php'), Unkeyed::because('The runner cannot name its tests.'));

it('takes the proof whose key still matches, and runs the rest, a unit with no key always', function () use ($units, $keys, $proof, $paths): void {
    $proving = Proving::of(
        $units,
        $keys,
        Proofs::of($proof('a', 'src/A.php', 'main'), $proof('stale', 'src/B.php', 'main')),
        Proofs::none(),
    );
    $proved = [...$proving->proved()];

    expect(array_map(static fn(UnitResult $result): string => sprintf('%s %s', $result->unit()->path()->value(), $result->origin()->value), $proved))
        ->toBe(['src/A.php proved'])
        ->and([...$proved[0]->mutants()][0]->nativeId())->toBe('main')
        ->and($paths($proving->toRun()))->toBe(['src/B.php', 'src/C.php'])
        ->and($proving->ownScopeProofs())->toBe(0);
});

it('takes the default branch\'s proof before the run\'s own, and says when it took its own', function () use ($units, $keys, $proof): void {
    $proving = Proving::of(
        $units,
        $keys,
        Proofs::of($proof('a', 'src/A.php', 'main')),
        Proofs::of($proof('a', 'src/A.php', 'pr'), $proof('b', 'src/B.php', 'pr')),
    );
    $proved = [...$proving->proved()];

    expect([...$proved[0]->mutants()][0]->nativeId())->toBe('main')
        ->and([...$proved[1]->mutants()][0]->nativeId())->toBe('pr')
        ->and($proving->toRun())->toHaveCount(1)
        ->and($proving->ownScopeProofs())->toBe(1);
});

it('says it took nothing of its own where the default branch proved all it took', function () use ($units, $keys, $proof): void {
    $proving = Proving::of($units, $keys, Proofs::of($proof('a', 'src/A.php', 'main')), Proofs::of($proof('a', 'src/A.php', 'pr')));

    expect($proving->ownScopeProofs())->toBe(0);
});

it('counts every proof it took from the run\'s own scope', function () use ($units, $keys, $proof, $paths): void {
    $own = Proofs::of($proof('a', 'src/A.php', 'pr'), $proof('b', 'src/B.php', 'pr'));
    $proving = Proving::of($units, $keys, Proofs::none(), $own);

    expect($proving->ownScopeProofs())->toBe(2)
        ->and($proving->proved())->toHaveCount(2)
        ->and($paths($proving->toRun()))->toBe(['src/C.php']);
});
