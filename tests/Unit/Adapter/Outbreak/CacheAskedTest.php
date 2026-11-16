<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Outbreak\CacheAsked;
use NightWorksIO\MutationGate\Core\Config\CacheStore;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\CacheClass;
use NightWorksIO\MutationGate\Core\Proof\CacheRequest;
use NightWorksIO\MutationGate\Core\Proof\LedgerLimits;

$absolute = static fn(Path $path): string => sprintf('/project/%s', $path->value());

it('asks the binary for each class\'s pack at its place on disk, the newest of them where told to, with the registry and the limits', function () use ($absolute): void {
    $request = CacheRequest::to(CacheStore::Actions, 'ghcr.io/octo/gate', Digest::of(str_repeat('b', 64)), LedgerLimits::of(1000, 5000, 60.0))
        ->for(CacheClass::Trusted, Path::of('trusted.pack'), Path::of('restored/trusted'))
        ->for(CacheClass::Branch, Path::of('branch.pack'), Path::of('restored/branch'))
        ->newest();

    expect(json_decode(CacheAsked::of($request, $absolute), associative: true))->toBe([
        'store' => CacheStore::Actions->value,
        'registry' => 'ghcr.io/octo/gate',
        'base' => str_repeat('b', 64),
        'newest' => true,
        'limits' => ['packed' => 1000, 'unpacked' => 5000],
        'packs' => ['trusted' => '/project/trusted.pack', 'branch' => '/project/branch.pack'],
        'restored' => ['trusted' => '/project/restored/trusted', 'branch' => '/project/restored/branch'],
    ]);
});

it('asks for no registry, not the newest, and no packs as empty objects, where the request names none', function () use ($absolute): void {
    $request = CacheRequest::to(CacheStore::Actions, new NotGiven(), Digest::of(str_repeat('c', 64)), LedgerLimits::of(10, 20, 60.0));

    expect(CacheAsked::of($request, $absolute))->toBe(sprintf(
        '{"store":"%s","base":"%s","limits":{"packed":10,"unpacked":20},"packs":{},"restored":{}}',
        CacheStore::Actions->value,
        str_repeat('c', 64),
    ));
});
