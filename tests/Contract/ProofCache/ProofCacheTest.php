<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Outbreak\Outbreak;
use NightWorksIO\MutationGate\Core\Config\CacheStore;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Proof\CacheClass;
use NightWorksIO\MutationGate\Core\Proof\CachePack;
use NightWorksIO\MutationGate\Core\Proof\CacheRequest;
use NightWorksIO\MutationGate\Core\Proof\LedgerLimits;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Proofs;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

// What the binary does with the gate's packs of proofs (ADR-0131): a pack it
// merges and pulls gives back each proof exactly as the gate reads it, the
// newer entry under a key kept. Outbreak's core is the binary OUTBREAK_BINARY
// names; with none, it pulls and pushes nothing.

afterEach(function (): void {
    Scratch::sweep();
});

$base = Digest::of(str_repeat('b', 64));
$proofAt = static fn(string $key, string $unit, string $run) => Proof::of(
    Digest::of($key),
    Path::of($unit),
    Mutants::none(),
    Run::of($run, Instant::at(new DateTimeImmutable('2026-11-09T20:00:00Z')), Digest::of(str_repeat('b', 64))),
);

it('merges a verdict\'s entries into the restored pack, and pulls back each proof as the gate reads it', function () use ($base, $proofAt): void {
    $root = Scratch::directory();
    $binary = getenv('OUTBREAK_BINARY');
    $cache = is_string($binary) ? Outbreak::at($binary, $root) : Outbreak::none($root);
    $older = $proofAt(str_repeat('1', 64), 'src/Old.php', 'github:1/1');
    $kept = $proofAt(str_repeat('2', 64), 'src/Kept.php', 'github:1/1');
    $newer = $proofAt(str_repeat('1', 64), 'src/New.php', 'github:2/1');
    mkdir(sprintf('%s/restored', $root), 0o700, true);
    file_put_contents(sprintf('%s/restored/trusted.pack', $root), CachePack::bytes(CacheClass::Trusted, $base, Proofs::of($older, $kept)));
    file_put_contents(sprintf('%s/entries.pack', $root), CachePack::bytes(CacheClass::Trusted, $base, Proofs::of($newer)));
    $request = static fn(string $pack): CacheRequest => CacheRequest::to(CacheStore::Actions, NotGiven::value(), $base, LedgerLimits::standard())
        ->for(CacheClass::Trusted, Path::of($pack), Path::of('restored'));

    $pushed = $cache->pushed($request('entries.pack'));
    $pulled = $cache->pulled($request('pulled.pack'));

    if (! $cache->isInUse()) {
        expect([$pushed, $pulled])->each->toBeInstanceOf(NotWritten::class);

        return;
    }

    $read = CachePack::read((string) file_get_contents(sprintf('%s/pulled.pack', $root)), CacheClass::Trusted, $base, LedgerLimits::standard());

    expect([$pushed, $pulled])->each->toBeInstanceOf(Written::class)
        ->and($read)->toBeInstanceOf(Proofs::class)
        ->and($read instanceof Proofs ? $read->proofFor($newer->key())->unit()->value() : '')->toBe('src/New.php')
        ->and($read instanceof Proofs ? $read->proofFor($kept->key())->unit()->value() : '')->toBe('src/Kept.php')
        ->and($read instanceof Proofs ? $read->count() : 0)->toBe(2);
});
