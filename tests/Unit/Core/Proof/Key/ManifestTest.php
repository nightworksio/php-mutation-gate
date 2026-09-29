<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Proof\Key\Manifest;

it('reads a manifest without the gate\'s own entry', function (): void {
    $manifest = Contents::of('{"name": "acme/money", "extra": {"mutation-gate": {"extensions": []}, "laravel": {"dont-discover": []}}}');

    expect(Manifest::digestOf($manifest))
        ->toEqual(Digest::sha256Of(Json::encode(['name' => 'acme/money', 'extra' => ['laravel' => ['dont-discover' => []]]])));
});

it('reads two manifests that differ only in the gate\'s entry alike', function (): void {
    expect(Manifest::digestOf(Contents::of('{"extra": {"mutation-gate": {"extensions": ["A"]}}}')))
        ->toEqual(Manifest::digestOf(Contents::of('{"extra": {"mutation-gate": {"extensions": ["B"]}}}')));
});

it('reads a manifest as it is where it has no extra map to take the entry from', function (string $manifest): void {
    expect(Manifest::digestOf(Contents::of($manifest)))->toEqual(Digest::sha256Of($manifest));
})->with([
    'no extra' => ['{"name":  "acme/money"}'],
    'an extra that is not a map' => ['{"extra":  "none"}'],
    'text that is not JSON' => ['{"extra": '],
]);
