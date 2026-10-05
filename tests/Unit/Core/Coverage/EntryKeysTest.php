<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Coverage\EntryKeys;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;

it('holds one key per test file, the later in place of the earlier, and none for a file it does not hold', function (): void {
    $keys = EntryKeys::none()
        ->with(Path::of('tests/MoneyTest.php'), Digest::sha256Of('first'))
        ->with(Path::of('tests/MoneyTest.php'), Digest::sha256Of('second'));

    expect($keys->keyOf(Path::of('tests/MoneyTest.php')))->toEqual(Digest::sha256Of('second'))
        ->and($keys->keyOf(Path::of('tests/Other.php')))->toEqual(Missing::at(Path::of('tests/Other.php')))
        ->and($keys->files())->toEqual(Paths::of(Path::of('tests/MoneyTest.php')));
});

it('joins two sets of keys, the other\'s in place of any they share, and leaves out those of some files', function (): void {
    $mine = EntryKeys::none()->with(Path::of('a'), Digest::sha256Of('a'))->with(Path::of('7'), Digest::sha256Of('seven'));
    $other = EntryKeys::none()->with(Path::of('a'), Digest::sha256Of('A'))->with(Path::of('b'), Digest::sha256Of('b'));

    expect($mine->and($other)->written())->toBe([
        '7' => Digest::sha256Of('seven')->value(),
        'a' => Digest::sha256Of('A')->value(),
        'b' => Digest::sha256Of('b')->value(),
    ])
        ->and($mine->and($other)->without(Paths::of(Path::of('a'), Path::of('7')))->written())->toBe(['b' => Digest::sha256Of('b')->value()])
        ->and($mine->files())->toEqual(Paths::of(Path::of('a'), Path::of('7')));
});
