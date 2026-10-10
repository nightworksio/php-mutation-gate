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

it('takes the SHA-256 of no content as that of an empty one', function (): void {
    expect(Digest::ofNothing()->value())->toBe('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855');
});

it('takes the same SHA-256 of a content fed in parts as of the whole', function (): void {
    $context = Digest::hashing();
    hash_update($context, 'a');
    hash_update($context, 'bc');

    expect(Digest::finished($context))->toEqual(Digest::sha256Of('abc'));
});

it('knows a SHA-256 by its spelling: 64 lowercase hex digits', function (string $text, bool $isSha256): void {
    expect(Digest::isSha256($text))->toBe($isSha256);
})->with([
    'a SHA-256' => [str_repeat('a1', 32), true],
    'uppercase' => [str_repeat('A1', 32), false],
    'too short' => [str_repeat('a1', 31), false],
    'a git blob id' => ['5eeca8f', false],
    'a newline after it' => [sprintf("%s\n", str_repeat('a1', 32)), false],
]);
