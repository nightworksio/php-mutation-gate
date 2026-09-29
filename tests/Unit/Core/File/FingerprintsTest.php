<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Fingerprint;
use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;

it('holds nothing to begin with', function (): void {
    expect(Fingerprints::none())->toHaveCount(0);
});

it('keeps one fingerprint per path, the later replacing the earlier', function (): void {
    $fingerprints = Fingerprints::of(
        Fingerprint::of(Path::of('src/A.php'), Digest::of('1')),
        Fingerprint::of(Path::of('src/B.php'), Digest::of('2')),
        Fingerprint::of(Path::of('src/A.php'), Digest::of('3')),
    );

    expect(array_map(static fn(Fingerprint $fingerprint): string => $fingerprint->digest()->value(), iterator_to_array($fingerprints, preserve_keys: true)))->toBe(['3', '2'])
        ->and($fingerprints)->toHaveCount(2);
});

it('adds a fingerprint without changing the fingerprints it came from', function (): void {
    $fingerprints = Fingerprints::none();

    expect($fingerprints->with(Fingerprint::of(Path::of('123'), Digest::of('1'))))->toHaveCount(1)
        ->and($fingerprints)->toHaveCount(0);
});

it('answers the digest of a path it holds, and names a path it does not', function (): void {
    $fingerprints = Fingerprints::of(Fingerprint::of(Path::of('123'), Digest::of('1')));

    expect($fingerprints->digestOf(Path::of('123')))->toEqual(Digest::of('1'))
        ->and($fingerprints->digestOf(Path::of('src/B.php')))->toEqual(Missing::at(Path::of('src/B.php')));
});
