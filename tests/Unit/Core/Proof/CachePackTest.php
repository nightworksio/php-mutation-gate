<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\CacheTrust;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Gzip;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Proof\CacheClass;
use NightWorksIO\MutationGate\Core\Proof\CachedProofs;
use NightWorksIO\MutationGate\Core\Proof\CachePack;
use NightWorksIO\MutationGate\Core\Proof\LedgerLimits;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Proofs;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Time\Instant;

$makeBase = static fn(): Digest => Digest::of(str_repeat('b', 64));
$cached = static fn(string $key, string $unit, Digest $at): Proof => Proof::of(
    Digest::of($key),
    Path::of($unit),
    Mutants::none(),
    Run::of('github:7/1', Instant::at(new DateTimeImmutable('2026-11-09T20:00:00Z')), $at),
);

it('reads back each proof it packs under its key, leaving out a proof of another base', function () use ($makeBase, $cached): void {
    $base = $makeBase();
    $here = $cached(str_repeat('1', 64), 'src/A.php', $base);
    $elsewhere = $cached(str_repeat('2', 64), 'src/B.php', Digest::of(str_repeat('c', 64)));
    $bytes = CachePack::bytes(CacheClass::Trusted, $base, Proofs::of($here, $elsewhere));

    $read = CachePack::read($bytes, CacheClass::Trusted, $base, LedgerLimits::standard());

    expect($read)->toBeInstanceOf(Proofs::class)
        ->and($read instanceof Proofs ? $read->count() : 0)->toBe(1)
        ->and($read instanceof Proofs ? $read->proofFor($here->key()) : null)->toBeInstanceOf(Proof::class)
        ->and($read instanceof Proofs ? $read->proofFor($here->key())->unit()->value() : '')->toBe('src/A.php');
});

it('takes nothing from a pack of another class or base, or past its limits', function () use ($makeBase, $cached): void {
    $base = $makeBase();
    $bytes = CachePack::bytes(CacheClass::Branch, $base, Proofs::of($cached(str_repeat('1', 64), 'src/A.php', $base)));

    expect(CachePack::read($bytes, CacheClass::Trusted, $base, LedgerLimits::standard()))->toBeInstanceOf(CannotJudge::class)
        ->and(CachePack::read($bytes, CacheClass::Branch, Digest::of(str_repeat('c', 64)), LedgerLimits::standard()))->toBeInstanceOf(CannotJudge::class)
        ->and(CachePack::read($bytes, CacheClass::Branch, $base, LedgerLimits::of(8, 8, 1.0)))->toBeInstanceOf(CannotJudge::class)
        ->and(CachePack::read(Gzip::packAt("{\"outbreak\":1}\n", 9), CacheClass::Branch, $base, LedgerLimits::standard()))->toBeInstanceOf(CannotJudge::class);
});

it('reads a branch\'s packs on a pull request, and the default branch\'s alone on it unless the repository is trusted', function (): void {
    expect(CacheClass::readBy(false, CacheTrust::DefaultBranch))->toBe([CacheClass::Trusted, CacheClass::Branch])
        ->and(CacheClass::readBy(true, CacheTrust::DefaultBranch))->toBe([CacheClass::Trusted])
        ->and(CacheClass::readBy(true, CacheTrust::Repository))->toBe([CacheClass::Trusted, CacheClass::Branch])
        ->and(CacheClass::writtenBy(true))->toBe(CacheClass::Trusted)
        ->and(CacheClass::writtenBy(false))->toBe(CacheClass::Branch)
        ->and(CacheClass::Branch->isOwnScope())->toBeTrue()
        ->and(CacheClass::Trusted->isOwnScope())->toBeFalse();
});

it('keeps a proof read first under its key, and holds each class apart', function () use ($makeBase, $cached): void {
    $base = $makeBase();
    $first = $cached(str_repeat('1', 64), 'src/First.php', $base);
    $later = $cached(str_repeat('1', 64), 'src/Later.php', $base);
    $held = CachedProofs::none()
        ->with(CacheClass::Trusted, Proofs::of($first))
        ->with(CacheClass::Trusted, Proofs::of($later))
        ->with(CacheClass::Branch, Proofs::of($later));

    expect($held->trusted()->proofFor($first->key())->unit()->value())->toBe('src/First.php')
        ->and($held->branch()->proofFor($first->key())->unit()->value())->toBe('src/Later.php')
        ->and($held->count())->toBe(2);
});
