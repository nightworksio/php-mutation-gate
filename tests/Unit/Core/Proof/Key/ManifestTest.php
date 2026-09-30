<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Proof\Key\Manifest;

it('reads a manifest without the gate\'s own entry', function (): void {
    $manifest = Contents::of('{"name": "acme/money", "extra": {"mutation-gate": {"extensions": []}, "laravel": {"dont-discover": ["a/b"]}}}');

    expect(Manifest::digestOf($manifest))
        ->toEqual(Digest::sha256Of('{"name":"acme/money","extra":{"laravel":{"dont-discover":["a/b"]}}}'));
});

it('reads two manifests that differ only in the gate\'s entry alike, and two that differ elsewhere apart', function (): void {
    expect(Manifest::digestOf(Contents::of('{"extra": {"mutation-gate": {"extensions": ["A"]}}}')))
        ->toEqual(Manifest::digestOf(Contents::of('{"extra": {"mutation-gate": {"extensions": ["B"]}}}')))
        ->and(Manifest::digestOf(Contents::of('{"extra": {"mutation-gate": {}, "other": 1}}')))
        ->not->toEqual(Manifest::digestOf(Contents::of('{"extra": {"mutation-gate": {}, "other": 2}}')));
});

it('reads a manifest as it is where it is not a JSON object', function (string $manifest): void {
    expect(Manifest::digestOf(Contents::of($manifest)))->toEqual(Digest::sha256Of($manifest));
})->with([
    'text that is not JSON' => ['{"extra": '],
    'a string' => ['"acme/money"'],
]);
