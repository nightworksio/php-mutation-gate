<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Digest;

it('keeps a digest as something else spelt it', function (): void {
    expect(Digest::of('5eeca8f')->value())->toBe('5eeca8f');
});

it('takes the SHA-256 of a content in lowercase hex', function (): void {
    expect(Digest::sha256Of('')->value())->toBe('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855')
        ->and(Digest::sha256Of('abc')->value())->toBe('ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad');
});
