<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Outbreak\CacheDelivery;
use NightWorksIO\MutationGate\Adapter\Outbreak\Outbreak;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Tests\Support\OutbreakStandIn;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('pulls the newest pack of every class from the workflow\'s registry into the directory fetch fetches into', function (): void {
    $pulling = OutbreakStandIn::answering('{"outbreak":1,"pulled":2}');

    $pulled = CacheDelivery::pulled(Outbreak::at($pulling->binary(), '/project'), Variables::of([CacheDelivery::REGISTRY => 'ghcr.io/owner/proofs']));
    $asked = json_decode($pulling->read(), associative: true);

    expect($pulled)->toEqual(Written::noting('/project', 'Outbreak pulled 2 of the proof cache\'s packs.'))
        ->and(is_array($asked) ? [$asked['registry'], $asked['newest'], $asked['packs'], $asked['restored']] : [])->toBe([
            'ghcr.io/owner/proofs',
            true,
            ['trusted' => '/project/cache/trusted/trusted.pack', 'branch' => '/project/cache/branch/branch.pack'],
            ['trusted' => '/project/cache/trusted/trusted.pack', 'branch' => '/project/cache/branch/branch.pack'],
        ]);
})->group('holds:src/Adapter/Outbreak/CacheDelivery.php');
