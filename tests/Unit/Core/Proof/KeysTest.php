<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Proof\Unkeyed;

it('holds no keys to begin with', function (): void {
    expect(Keys::none())->toHaveCount(0)
        ->and(Keys::none()->units())->toEqual(Paths::none());
});

it('answers each unit\'s key, or why it has none', function (): void {
    $keys = Keys::none()
        ->with(Path::of('src/A.php'), Digest::of('9c1e'))
        ->with(Path::of('src/B.php'), Unkeyed::because('There is no coverage map.'));

    expect($keys->keyOf(Path::of('src/A.php')))->toEqual(Digest::of('9c1e'))
        ->and($keys->keyOf(Path::of('src/B.php')))->toEqual(Unkeyed::because('There is no coverage map.'))
        ->and($keys->keyOf(Path::of('src/C.php')))->toEqual(Unkeyed::because('src/C.php was not considered, so it has no key.'));
});

it('lists its units in the order they came, a later key of a unit replacing the earlier', function (): void {
    $keys = Keys::none()
        ->with(Path::of('src/B.php'), Digest::of('1'))
        ->with(Path::of('123'), Digest::of('2'))
        ->with(Path::of('src/B.php'), Digest::of('3'));

    expect($keys->units())->toEqual(Paths::of(Path::of('src/B.php'), Path::of('123')))
        ->and($keys->keyOf(Path::of('src/B.php')))->toEqual(Digest::of('3'))
        ->and($keys)->toHaveCount(2);
});

it('adds a key without changing the keys it came from', function (): void {
    $keys = Keys::none();
    $keys->with(Path::of('src/A.php'), Digest::of('1'));

    expect($keys)->toHaveCount(0);
});

it('reads keys together with others, a later key of a unit replacing the earlier where it stood', function (): void {
    $first = Keys::none()->with(Path::of('src/B.php'), Digest::of('1'))->with(Path::of('123'), Digest::of('2'));
    $second = Keys::none()->with(Path::of('src/A.php'), Digest::of('3'));
    $third = Keys::none()->with(Path::of('src/B.php'), Unkeyed::because('It moved.'));

    expect($first->and($second, $third)->units())->toEqual(Paths::of(Path::of('src/B.php'), Path::of('123'), Path::of('src/A.php')))
        ->and($first->and($second, $third)->keyOf(Path::of('src/B.php')))->toEqual(Unkeyed::because('It moved.'))
        ->and($first->and($second, $third)->keyOf(Path::of('123')))->toEqual(Digest::of('2'))
        ->and($first->and())->toEqual($first)
        ->and($first)->toHaveCount(2);
});
