<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\TestShare;

it('is every covering test unless the shard noted its part\'s, owned there or elsewhere', function (bool $owned, bool $otherOwned, TestShare $share): void {
    expect(TestShare::of(owned: $owned, otherOwned: $otherOwned))->toBe($share);
})->with([
    'noted neither' => [false, false, TestShare::All],
    'owned here' => [true, false, TestShare::OwnedPart],
    'owned elsewhere' => [false, true, TestShare::OtherPart],
]);

it('dooms alone as the whole answer, and is checked by anything but tests unless another part owns it', function (TestShare $share, bool $whole, bool $owned): void {
    expect([$share->isWhole(), $share->isOwned()])->toBe([$whole, $owned]);
})->with([
    'every covering test' => [TestShare::All, true, true],
    'its own part' => [TestShare::OwnedPart, false, true],
    'another part\'s' => [TestShare::OtherPart, false, false],
]);
