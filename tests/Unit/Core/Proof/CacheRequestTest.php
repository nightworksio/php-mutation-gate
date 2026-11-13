<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\CacheStore;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Proof\CacheClass;
use NightWorksIO\MutationGate\Core\Proof\CacheRequest;
use NightWorksIO\MutationGate\Core\Proof\LedgerLimits;

it('pulls each class\'s newest pack only once told to, keeping the rest of the request', function (): void {
    $limits = LedgerLimits::standard();
    $request = CacheRequest::to(CacheStore::Actions, 'ghcr.io/octo/gate', Digest::sha256Of('base'), $limits)
        ->for(CacheClass::Trusted, Path::of('/tmp/trusted.pack'), Path::of('/tmp/restored'));
    $newest = $request->newest();

    expect($request->isNewest())->toBeFalse()
        ->and($newest->isNewest())->toBeTrue()
        ->and($newest->for(CacheClass::Branch, Path::of('/tmp/branch.pack'), Path::of('/tmp/restored'))->isNewest())->toBeTrue()
        ->and($newest->store())->toBe(CacheStore::Actions)
        ->and($newest->registry())->toBe('ghcr.io/octo/gate')
        ->and($newest->base())->toEqual(Digest::sha256Of('base'))
        ->and($newest->limits())->toBe($limits)
        ->and($newest->packs())->toEqual(['trusted' => Path::of('/tmp/trusted.pack')])
        ->and($newest->restored())->toEqual(['trusted' => Path::of('/tmp/restored')]);
});
