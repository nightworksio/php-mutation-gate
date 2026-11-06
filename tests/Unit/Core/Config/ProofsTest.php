<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\PhpCalls;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\Config\Proofs;
use NightWorksIO\MutationGate\Core\Proof\Compression;
use NightWorksIO\MutationGate\Core\Proof\Copies;
use NightWorksIO\MutationGate\Core\Proof\TakenProofs;
use NightWorksIO\MutationGate\Tests\Support\Configs;

it('writes whether coverage is measured incrementally as the PHP config calls it', function (): void {
    expect([Proofs::of(incremental: true)->php(ProjectRoot::origin()), Proofs::of(incremental: false)->php(ProjectRoot::origin())])
        ->toEqual([PhpCalls::inWith('Coverage::incremental()'), PhpCalls::inWith('Coverage::full()')]);
});

it('writes the copy of a ledger a run reads as the PHP config and the JSON config name it, and the newest where none is named', function (): void {
    $origin = ProjectRoot::origin();

    expect([Proofs::of(copies: Copies::Newest)->php($origin), Proofs::of(copies: Copies::Together)->php($origin), Proofs::of(copies: Copies::Merged)->php($origin), Proofs::none()->php($origin)])
        ->toEqual([PhpCalls::inWith('Proofs::newestCopy()'), PhpCalls::inWith('Proofs::copiesTogether()'), PhpCalls::inWith('Proofs::mergedCopies()'), PhpCalls::inWith()])
        ->and(Proofs::of(copies: Copies::Together)->written($origin)->line())->toBe('{"proofs":{"copies":"together"}}')
        ->and(Proofs::none()->copies())->toBe(Copies::Newest)
        ->and(Proofs::of(copies: Copies::Merged)->over(Proofs::of(copies: Copies::Together))->copies())->toBe(Copies::Together)
        ->and(Proofs::of(copies: Copies::Merged)->over(Proofs::none())->copies())->toBe(Copies::Merged);
});

it('takes its own scope\'s proofs unless proofs.ownScope says not to, and writes it as the PHP config and the JSON config name it', function (): void {
    $origin = ProjectRoot::origin();

    expect([Proofs::of(ownScope: true)->php($origin), Proofs::of(ownScope: false)->php($origin)])
        ->toEqual([PhpCalls::inWith('Proofs::ownScope()'), PhpCalls::inWith('Proofs::defaultBranchOnly()')])
        ->and(Proofs::of(ownScope: false)->written($origin)->line())->toBe('{"proofs":{"ownScope":false}}')
        ->and([Proofs::none()->taken(), Proofs::standard()->taken(), Proofs::of(ownScope: false)->taken()])
        ->toBe([TakenProofs::Every, TakenProofs::Every, TakenProofs::DefaultBranchOnly])
        ->and(Proofs::of(ownScope: true)->over(Proofs::of(ownScope: false))->taken())->toBe(TakenProofs::DefaultBranchOnly)
        ->and(Proofs::of(ownScope: false)->over(Proofs::none())->taken())->toBe(TakenProofs::DefaultBranchOnly);
});

it('compresses a ledger as zstd where it is here, else gzip, at level 9 by default, laying each key a later layer sets', function (): void {
    $earlier = Proofs::of(compression: Compression::Zstd, compressionLevel: 15);
    $later = Proofs::of(compression: Compression::Gzip);

    expect([Proofs::none()->compression(), Proofs::none()->compressionLevel()])->toBe([Compression::Auto, 9])
        ->and([Proofs::standard()->compression(), Proofs::standard()->compressionLevel()])->toBe([Compression::Auto, 9])
        ->and([$earlier->over($later)->compression(), $earlier->over($later)->compressionLevel()])
        ->toBe([Compression::Gzip, 15])
        ->and($earlier->over(Proofs::none())->compression())->toBe(Compression::Zstd);
});

it('writes proofs.compression and proofs.compressionLevel as the JSON config and the PHP config name them, and nothing it does not set', function (): void {
    $set = Proofs::of(compression: Compression::Zstd, compressionLevel: 15);

    expect($set->written(ProjectRoot::origin())->line())->toBe('{"proofs":{"compression":"zstd","compressionLevel":15}}')
        ->and($set->php(ProjectRoot::origin()))->toEqual(PhpCalls::inWith("Proofs::compression('zstd')", 'Proofs::compressionLevel(15)'))
        ->and(Proofs::of(compressionLevel: 3)->written(ProjectRoot::origin())->line())->toBe('{"proofs":{"compressionLevel":3}}')
        ->and(Proofs::of(compression: Compression::Gzip)->php(ProjectRoot::origin()))->toEqual(PhpCalls::inWith("Proofs::compression('gzip')"))
        ->and(Proofs::none()->written(ProjectRoot::origin())->line())->toBe('{}');
});

it('reads proofs.compression and proofs.compressionLevel, and refuses a compression it does not offer and a level past zstd\'s', function (): void {
    $compressed = Configs::settings(['runner' => 'pest', 'proofs' => ['compression' => 'gzip', 'compressionLevel' => 1]])->proofs();

    expect([$compressed->compression(), $compressed->compressionLevel()])->toBe([Compression::Gzip, 1])
        ->and(Configs::problems(Configs::layer(['proofs' => ['compression' => 'brotli', 'compressionLevel' => 20]])))->toBe([
            'proofs.compression: expected "auto", "gzip" or "zstd", got "brotli"',
            'proofs.compressionLevel: expected an integer from 1 to 19, got 20',
        ]);
});
