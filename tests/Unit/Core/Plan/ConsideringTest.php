<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Plan\Considering;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Proofs;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Reach\Reach;
use NightWorksIO\MutationGate\Core\Reach\Reason;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\UnitResult;
use NightWorksIO\MutationGate\Core\Verdict\UnitResults;
use NightWorksIO\MutationGate\Tests\Support\Growth;
use NightWorksIO\MutationGate\Tests\Support\Judged;
use NightWorksIO\MutationGate\Tests\Support\Moment;

$proof = static fn(string $unit, string $run, string $instant): Proof => Proof::of(
    Digest::of(sprintf('%s-%s', $unit, $run)),
    Path::of($unit),
    Mutants::of(Judged::mutant($run, MutantJudgement::Killed)->mutant()),
    Run::of($run, Moment::at($instant), Digest::of(str_repeat('b', 64))),
);
$paths = static fn(Units $units): array => array_map(static fn(Unit $unit): string => $unit->path()->value(), [...$units]);
$carried = static fn(UnitResults $results): array => array_map(
    static fn(UnitResult $result): string => sprintf('%s %s', $result->unit()->path()->value(), $result->origin()->value),
    [...$results],
);
$units = Units::of(Unit::file(Path::of('src/A.php')), Unit::file(Path::of('src/B.php')), Unit::file(Path::of('src/C.php')));
$reachingA = Reach::nothing(Packages::of(Trees::none()))->files(Paths::of(Path::of('src/A.php')), Reason::that('src/A.php changed.'));

it('considers what the change reaches, carries the newest result of the rest, and considers what has none', function () use ($units, $reachingA, $proof, $paths, $carried): void {
    $considering = Considering::of(
        $units,
        $reachingA,
        Proofs::of($proof('src/A.php', 'main', '2026-09-29T10:00:00Z'), $proof('src/B.php', 'main', '2026-09-29T10:00:00Z')),
        Proofs::none(),
    );

    expect($paths($considering->considered()))->toBe(['src/A.php', 'src/C.php'])
        ->and($carried($considering->carried()))->toBe(['src/B.php carried'])
        ->and($considering->ownScopeProofs())->toBe(0);
});

it('considers every unit a full run reaches', function () use ($units, $proof, $paths): void {
    $everywhere = Reach::nothing(Packages::of(Trees::none()))->everywhere(Reason::that('A full run.'));
    $considering = Considering::of($units, $everywhere, Proofs::of($proof('src/B.php', 'main', '2026-09-29T10:00:00Z')), Proofs::none());

    expect($paths($considering->considered()))->toBe(['src/A.php', 'src/B.php', 'src/C.php'])
        ->and($considering->carried())->toHaveCount(0);
});

it('carries the newer of the default branch\'s result and the run\'s own, and says when that was its own', function (
    string $trusted,
    string $own,
    string $taken,
    int $ownScope,
) use ($units, $reachingA, $proof): void {
    $considering = Considering::of(
        $units,
        $reachingA,
        Proofs::of($proof('src/B.php', 'main', $trusted)),
        Proofs::of($proof('src/B.php', 'pr', $own)),
    );
    $mutants = [...[...$considering->carried()][0]->mutants()];

    expect($considering->ownScopeProofs())->toBe($ownScope)
        ->and($mutants[0]->nativeId())->toBe($taken);
})->with([
    'the own scope\'s newer' => ['2026-09-28T10:00:00Z', '2026-09-29T10:00:00Z', 'pr', 1],
    'the default branch\'s newer' => ['2026-09-29T10:00:00Z', '2026-09-28T10:00:00Z', 'main', 0],
    'both as new' => ['2026-09-29T10:00:00Z', '2026-09-29T10:00:00Z', 'main', 0],
]);

it('carries the run\'s own result where the default branch has none', function () use ($units, $reachingA, $proof): void {
    $considering = Considering::of($units, $reachingA, Proofs::none(), Proofs::of($proof('src/B.php', 'pr', '2026-09-29T10:00:00Z')));

    expect($considering->carried())->toHaveCount(1)
        ->and($considering->ownScopeProofs())->toBe(1);
});

it('counts every result it carries from the run\'s own scope, and none from the default branch', function () use (
    $units,
    $proof,
    $carried,
): void {
    $reachingC = Reach::nothing(Packages::of(Trees::none()))
        ->files(Paths::of(Path::of('src/C.php')), Reason::that('src/C.php changed.'));
    $considering = Considering::of(
        $units,
        $reachingC,
        Proofs::of(
            $proof('src/A.php', 'main', '2026-09-28T10:00:00Z'),
            $proof('src/B.php', 'main', '2026-09-28T10:00:00Z'),
        ),
        Proofs::of(
            $proof('src/A.php', 'pr', '2026-09-29T10:00:00Z'),
            $proof('src/B.php', 'pr', '2026-09-29T10:00:00Z'),
        ),
    );

    expect($considering->ownScopeProofs())->toBe(2)
        ->and($carried($considering->carried()))->toBe(['src/A.php carried', 'src/B.php carried']);
});

it('considers and carries in time linear in the units and the proofs of both ledgers', function () use ($proof): void {
    $considered = static function (int $size) use ($proof): Closure {
        $range = range(1, $size);
        $units = Units::of(...array_map(
            static fn(int $at): Unit => Unit::file(Path::of(sprintf('src/%d.php', $at))),
            $range,
        ));
        $trusted = Proofs::of(...array_map(
            static fn(int $at): Proof => $proof(sprintf('src/%d.php', $at), 'main', '2026-09-28T10:00:00Z'),
            $range,
        ));
        $own = Proofs::of(...array_map(
            static fn(int $at): Proof => $proof(sprintf('src/%d.php', $at), 'pr', '2026-09-29T10:00:00Z'),
            $range,
        ));
        $reach = Reach::nothing(Packages::of(Trees::none()));

        return static fn(): Considering => Considering::of($units, $reach, $trusted, $own);
    };

    expect(count($considered(10)()->carried()))->toBe(10)
        ->and(Growth::of(1000, $considered))->toBeLessThan(Growth::LINEAR);
});
