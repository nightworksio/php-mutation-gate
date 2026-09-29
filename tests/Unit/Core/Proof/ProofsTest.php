<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Proofs;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Unproved;
use NightWorksIO\MutationGate\Core\Time\Instant;

$proof = static fn(string $key, string $unit): Proof => Proof::of(
    Digest::of($key),
    Path::of($unit),
    Mutants::none(),
    Run::of('local', Instant::at(new DateTimeImmutable('2026-09-29T20:48:17Z'))),
);
$units = static fn(Proofs $proofs): array => array_map(
    static fn(Proof $proof): string => $proof->unit()->value(),
    iterator_to_array($proofs, preserve_keys: true),
);

it('holds nothing to begin with', function (): void {
    expect(Proofs::none())->toHaveCount(0);
});

it('keeps one proof per key, the first of two', function () use ($proof, $units): void {
    $proofs = Proofs::of($proof('b', 'src/B.php'), $proof('1', 'src/A.php'), $proof('b', 'src/C.php'));

    expect($units($proofs))->toBe(['src/B.php', 'src/A.php'])
        ->and($proofs)->toHaveCount(2);
});

it('adds a proof without changing the proofs it came from', function () use ($proof): void {
    $proofs = Proofs::none();

    expect($proofs->with($proof('a', 'src/A.php')))->toHaveCount(1)
        ->and($proofs)->toHaveCount(0);
});

it('says whether a key is proved', function () use ($proof): void {
    $proofs = Proofs::of($proof('a', 'src/A.php'));

    expect($proofs->has(Digest::of('a')))->toBeTrue()
        ->and($proofs->has(Digest::of('b')))->toBeFalse();
});

it('answers the proof under a key, and says where there is none', function () use ($proof): void {
    $held = $proof('a', 'src/A.php');
    $proofs = Proofs::of($held);

    expect($proofs->proofFor(Digest::of('a')))->toBe($held)
        ->and($proofs->proofFor(Digest::of('b')))->toEqual(Unproved::key(Digest::of('b')));
});

it('drops the proof under a key, leaving the others and the proofs it came from', function () use ($proof, $units): void {
    $proofs = Proofs::of($proof('a', 'src/A.php'), $proof('b', 'src/B.php'));

    expect($units($proofs->without(Digest::of('a'))))->toBe(['src/B.php'])
        ->and($units($proofs->without(Digest::of('c'))))->toBe(['src/A.php', 'src/B.php'])
        ->and($proofs)->toHaveCount(2);
});
