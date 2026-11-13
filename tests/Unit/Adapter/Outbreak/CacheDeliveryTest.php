<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Outbreak\CacheDelivery;
use NightWorksIO\MutationGate\Adapter\Outbreak\Outbreak;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Proof\CacheClass;
use NightWorksIO\MutationGate\Core\Proof\CachePack;
use NightWorksIO\MutationGate\Core\Proof\CacheRequest;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A delivery directory whose request names this registry, for entries of this class. */
function cacheDelivered(string $registry, CacheClass $class): string
{
    $from = Scratch::directory();
    file_put_contents(
        sprintf('%s/%s', $from, CacheRequest::DELIVERED),
        CacheDelivery::request($registry, Digest::of(str_repeat('b', 64)), $class)->text(),
    );
    file_put_contents(sprintf('%s/%s', $from, CachePack::DELIVERED), 'entries');

    return $from;
}

it('takes a registry only as a plain host and path, with no scheme, user or query', function (string $named, bool $taken): void {
    expect(CacheDelivery::isRepository($named))->toBe($taken);
})->with([
    'a host and a path' => ['ghcr.io/owner/proofs', true],
    'a port' => ['localhost:5000/proofs', true],
    'a scheme' => ['https://ghcr.io/owner/proofs', false],
    'a user' => ['user@ghcr.io/owner/proofs', false],
    'a query' => ['ghcr.io/owner/proofs?to=elsewhere', false],
    'a climb' => ['ghcr.io/owner/../proofs', false],
    'no path' => ['ghcr.io', false],
]);

it('pushes nothing where the request names another registry than the workflow\'s', function (): void {
    $from = cacheDelivered('evil.example/steal', CacheClass::Trusted);
    $environment = Variables::of([CacheDelivery::REGISTRY => 'ghcr.io/owner/proofs', CacheDelivery::TOKEN => 'secret']);

    $pushed = CacheDelivery::pushed(Outbreak::none($from), $from, trusted: true, environment: $environment);

    expect($pushed)->toBeInstanceOf(NotWritten::class)
        ->and($pushed instanceof NotWritten ? $pushed->why() : '')->toContain('another registry');
});

it('pushes nothing where the workflow names no registry, or one that is no plain repository', function (string $registry): void {
    $from = cacheDelivered($registry, CacheClass::Trusted);

    $pushed = CacheDelivery::pushed(
        Outbreak::none($from),
        $from,
        trusted: true,
        environment: Variables::of([CacheDelivery::REGISTRY => $registry, CacheDelivery::TOKEN => 'secret']),
    );

    expect($pushed)->toBeInstanceOf(NotWritten::class)
        ->and($pushed instanceof NotWritten ? $pushed->why() : '')->toContain('MUTATION_GATE_REGISTRY');
})->with(['', 'https://evil.example/steal', 'user:pass@evil.example/steal']);

it('pushes a trusted pack only from a trusted run, and a branch\'s only with a token', function (): void {
    $environment = static fn(string $token): Variables => Variables::of([CacheDelivery::REGISTRY => 'ghcr.io/o/p', CacheDelivery::TOKEN => $token]);
    $trusted = cacheDelivered('ghcr.io/o/p', CacheClass::Trusted);
    $branch = cacheDelivered('ghcr.io/o/p', CacheClass::Branch);

    $untrusted = CacheDelivery::pushed(Outbreak::none($trusted), $trusted, trusted: false, environment: $environment('secret'));
    $tokenless = CacheDelivery::pushed(Outbreak::none($branch), $branch, trusted: true, environment: $environment(''));

    expect($untrusted instanceof NotWritten ? $untrusted->why() : '')->toContain('no default branch ledger')
        ->and($tokenless instanceof NotWritten ? $tokenless->why() : '')->toContain('no registry token');
});

it('says nothing of a delivery that holds no request', function (): void {
    $from = Scratch::directory();

    expect(CacheDelivery::pushed(Outbreak::none($from), $from, trusted: true, environment: Variables::of([])))->toBeInstanceOf(NotGiven::class);
});
