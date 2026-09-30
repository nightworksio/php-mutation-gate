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

it('reads an https:// base another path is added to, and refuses one with a user, a query or a fragment', function (
    string $written,
    bool $read,
): void {
    expect(Url::base()->read(Node::config($written))->problems() === [])->toBe($read);
})->with([
    'a host' => ['"https://ledgers.example.com"', true],
    'a host and a port' => ['"https://ledgers.example.com:8443"', true],
    'a path' => ['"https://ledgers.example.com/pub/"', true],
    'a path with an at sign' => ['"https://ledgers.example.com/@team"', true],
    'http' => ['"http://ledgers.example.com"', false],
    'the scheme alone' => ['"https://"', false],
    'a user' => ['"https://reader@ledgers.example.com"', false],
    'a user and a password' => ['"https://reader:secret@ledgers.example.com/pub"', false],
    'a query' => ['"https://ledgers.example.com/pub?signed=1"', false],
    'a query with no path' => ['"https://ledgers.example.com?signed=1"', false],
    'a fragment' => ['"https://ledgers.example.com/pub#main"', false],
    'a space' => ['"https://ledgers.example.com/p ub"', false],
]);

it('says what it expects, and writes it as a pattern', function (): void {
    expect(Url::https()->expected())->toBe('an https:// URL')
        ->and(Url::web()->expected())->toBe('an http:// or https:// URL')
        ->and(Url::https()->schema()->line())->toBe('{"type":"string","pattern":"^https://."}')
        ->and(Url::web()->schema()->line())->toBe('{"type":"string","pattern":"^https?://."}')
        ->and(Url::base()->expected())->toBe('an https:// URL with no user, query or fragment')
        ->and(Url::base()->schema()->line())
        ->toBe('{"type":"string","pattern":"^https://[^/?\\\\x23@\\\\s]+(/[^?\\\\x23\\\\s]*)?$"}');
});
