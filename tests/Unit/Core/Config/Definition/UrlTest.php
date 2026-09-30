<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Definition\Url;
use NightWorksIO\MutationGate\Core\Format\Node;

it('reads an https:// URL, and refuses any other', function (string $written, bool $read): void {
    expect(Url::https()->read(Node::config($written))->problems() === [])->toBe($read);
})->with([
    'https' => ['"https://hooks.example"', true],
    'http' => ['"http://hooks.example"', false],
    'the scheme alone' => ['"https://"', false],
    'no URL' => ['"not a url"', false],
    'a number' => ['3', false],
]);

it('reads an http:// or https:// URL where it is spoken to in the clear, and refuses any other', function (
    string $written,
    bool $read,
): void {
    expect(Url::web()->read(Node::config($written))->problems() === [])->toBe($read);
})->with([
    'https' => ['"https://account.r2.example"', true],
    'http' => ['"http://minio.test:9000"', true],
    'the scheme alone' => ['"http://"', false],
    'no URL' => ['"not a url"', false],
]);

it('says what it expects, and writes it as a pattern', function (): void {
    expect(Url::https()->expected())->toBe('an https:// URL')
        ->and(Url::web()->expected())->toBe('an http:// or https:// URL')
        ->and(Url::https()->schema()->line())->toBe('{"type":"string","pattern":"^https://."}')
        ->and(Url::web()->schema()->line())->toBe('{"type":"string","pattern":"^https?://."}');
});
