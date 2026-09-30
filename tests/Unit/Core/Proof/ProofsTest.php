<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Proof\NeverProved;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Proofs;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Unproved;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Tests\Support\Growth;
use NightWorksIO\MutationGate\Tests\Support\Moment;

$proof = static fn(string $key, string $unit): Proof => Proof::of(
    Digest::of($key),
    Path::of($unit),
    Mutants::none(),
    Run::of('local', Instant::at(new DateTimeImmutable('2026-09-29T20:48:17Z')), Digest::of(str_repeat('b', 64))),
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

it('keeps the proof it holds when one more proves the same key', function () use ($proof, $units): void {
    $proofs = Proofs::of($proof('b', 'src/B.php'));

    expect($proofs->with($proof('b', 'src/C.php')))->toBe($proofs)
        ->and($units($proofs->with($proof('1', 'src/A.php'))))->toBe(['src/B.php', 'src/A.php']);
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

it('collects proofs in time linear in their number, keeping the first of a key', function () use ($proof): void {
    $made = static fn(int $size, string $unit): array => array_map(static fn(int $at): Proof => $proof(hash('sha256', sprintf('%d', $at)), $unit), range(1, $size));
    $proofs = static function (int $size) use ($made): Closure {
        $first = $made($size, 'src/First.php');
        $later = $made($size, 'src/Later.php');

        return static fn(): Proofs => Proofs::of(...$first, ...$later);
    };

    expect($proofs(10)())->toHaveCount(10)
        ->and($proofs(10)()->proofFor(Digest::of(hash('sha256', '1'))))->toEqual($made(1, 'src/First.php')[0])
        ->and(Growth::of(2500, $proofs))->toBeLessThan(Growth::LINEAR);
});

it('answers the newest proof of a path whatever its key, the first of two as new, and none for a path never proved', function (): void {
    $at = static fn(string $key, string $unit, string $instant): Proof => Proof::of(
        Digest::of($key),
        Path::of($unit),
        Mutants::none(),
        Run::of($key, Moment::at($instant), Digest::of(str_repeat('b', 64))),
    );
    $proofs = Proofs::of(
        $at('old', 'src/A.php', '2026-09-28T10:00:00Z'),
        $at('new', 'src/A.php', '2026-09-29T10:00:00Z'),
        $at('same', 'src/A.php', '2026-09-29T10:00:00Z'),
        $at('older', 'src/A.php', '2026-09-27T10:00:00Z'),
        $at('other', 'src/B.php', '2026-09-30T10:00:00Z'),
    );
    $newest = $proofs->newest()->of(Path::of('src/A.php'));

    expect($newest instanceof Proof ? $newest->key() : $newest)->toEqual(Digest::of('new'))
        ->and($proofs->newest()->of(Path::of('src/C.php')))->toEqual(NeverProved::unit(Path::of('src/C.php')))
        ->and(NeverProved::unit(Path::of('src/C.php'))->path())->toEqual(Path::of('src/C.php'));
});

it('answers the newest proof of every path in time linear in the proofs and the paths', function () use ($proof): void {
    $asked = static function (int $size) use ($proof): Closure {
        $proofs = Proofs::of(...array_map(
            static fn(int $at): Proof => $proof(hash('sha256', sprintf('%d', $at)), sprintf('src/%d.php', $at)),
            range(1, $size),
        ));
        $paths = array_map(static fn(int $at): Path => Path::of(sprintf('src/%d.php', $at)), range(1, $size));

        return static function () use ($proofs, $paths): int {
            $newest = $proofs->newest();

            return count(array_filter($paths, static fn(Path $path): bool => $newest->of($path) instanceof Proof));
        };
    };

    expect($asked(10)())->toBe(10)
        ->and(Growth::of(1000, $asked))->toBeLessThan(Growth::LINEAR);
});
