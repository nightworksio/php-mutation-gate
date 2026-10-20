<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Composer\LockMoves;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Tests\Support\Locks;

it('names each package whose pin moved, added or removed, under either list, in byte order', function (): void {
    $moves = LockMoves::between(
        Locks::of(['symfony/console' => '8.1.7', 'nikic/php-parser' => '5.6.0'], ['pestphp/pest' => '5.2.1', 'psr/clock' => '1.0.0']),
        Locks::of(['symfony/console' => '8.1.9', 'nikic/php-parser' => '5.6.0', 'psr/log' => '3.0.0'], ['pestphp/pest' => '5.2.1']),
    );

    expect($moves)->toBeInstanceOf(LockMoves::class)
        ->and($moves instanceof LockMoves ? $moves->packages() : $moves)->toBe(['psr/clock', 'psr/log', 'symfony/console'])
        ->and($moves instanceof LockMoves && $moves->beyondPackages())->toBeFalse();
});

it('moves nothing where only the keys Composer writes from the rest changed', function (): void {
    $before = Locks::of(['symfony/console' => '8.1.7']);
    $now = Contents::of(str_replace('This file locks', 'This file pins', (string) preg_replace('/"content-hash":"[0-9a-f]+"/', '"content-hash":"0"', $before->text())));
    $moves = LockMoves::between($before, $now);

    expect($moves instanceof LockMoves ? [$moves->packages(), $moves->beyondPackages()] : $moves)->toBe([[], false]);
});

it('moves nothing where the other keys only changed their order', function (): void {
    $moves = LockMoves::between(
        Locks::of([], [], ['minimum-stability' => 'stable', 'plugin-api-version' => '2.6.0']),
        Locks::of([], [], ['plugin-api-version' => '2.6.0', 'minimum-stability' => 'stable']),
    );

    expect($moves instanceof LockMoves ? [$moves->packages(), $moves->beyondPackages()] : $moves)->toBe([[], false]);
});

it('says a key besides the package lists moved', function (Contents $then, Contents $now): void {
    $moves = LockMoves::between($then, $now);

    expect($moves instanceof LockMoves ? [$moves->packages(), $moves->beyondPackages()] : $moves)->toBe([[], true]);
})->with([
    'the platform' => [
        fn(): Contents => Locks::of([], [], ['platform' => ['php' => '^8.5']]),
        fn(): Contents => Locks::of([], [], ['platform' => ['php' => '^8.6']]),
    ],
    'the minimum stability' => [
        fn(): Contents => Locks::of([], [], ['minimum-stability' => 'stable']),
        fn(): Contents => Locks::of([], [], ['minimum-stability' => 'dev']),
    ],
    'an alias' => [
        fn(): Contents => Locks::of([], [], ['aliases' => []]),
        fn(): Contents => Locks::of([], [], ['aliases' => [['package' => 'a/b', 'alias' => '1.0']]]),
    ],
]);

it('cannot tell what moved where either version is not a lock Composer writes', function (string $then, string $now): void {
    expect(LockMoves::between(Contents::of($then), Contents::of($now)))->toBeInstanceOf(CannotJudge::class);
})->with([
    'not JSON' => ['{"packages": []}', 'not a lock'],
    'a list of packages that is an object' => ['{"packages": {"a/b": {}}}', '{"packages": []}'],
    'an entry with no name' => ['{"packages": [{"version": "1.0"}]}', '{"packages": []}'],
]);
