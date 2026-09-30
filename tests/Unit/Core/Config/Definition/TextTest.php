<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Definition\Text;
use NightWorksIO\MutationGate\Core\Format\Node;

it('reads text that is not empty, with whitespace in it or not', function (): void {
    expect(Text::of('a reason')->read(Node::config('"not tested\nhere"'))->value())->toBe("not tested\nhere")
        ->and(Text::of('a reason')->read(Node::config('""'))->problems())->toHaveCount(1)
        ->and(Text::of('a reason')->schema()->line())->toBe('{"type":"string","minLength":1}');
});

it('reads a word, and refuses text with whitespace in it', function (string $written, bool $read): void {
    expect(Text::word('a group name')->read(Node::config($written))->problems() === [])->toBe($read);
})->with([
    'a word' => ['"mutation-canary"', true],
    'a space' => ['"mutation canary"', false],
    'a tab at the end' => ['"canary\t"', false],
    'a newline at the end' => ['"canary\n"', false],
    'nothing' => ['""', false],
]);

it('says a word has no whitespace, and writes it as a pattern', function (): void {
    expect(Text::word('a group name')->expected())->toBe('a group name, with no whitespace')
        ->and(Text::word('a group name')->schema()->line())->toBe('{"type":"string","minLength":1,"pattern":"^\\\\S+$"}');
});
