<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Http\MediaType;
use NightWorksIO\MutationGate\Core\Http\Method;
use NightWorksIO\MutationGate\Core\Http\Request;
use NightWorksIO\MutationGate\Core\Http\Token;

it('holds a request\'s method, URL, headers and body', function (): void {
    $put = Request::put('https://storage.example/ledger.json.gz', 'bytes')
        ->carrying(Token::bearer('ya29'))
        ->sending(MediaType::Gzip)
        ->with('x-ms-version', '2024-11-04');

    expect([$put->method(), $put->url(), $put->headers(), $put->body()])->toBe([
        Method::Put,
        'https://storage.example/ledger.json.gz',
        ['Authorization' => 'Bearer ya29', 'Content-Type' => 'application/gzip', 'x-ms-version' => '2024-11-04'],
        'bytes',
    ]);
});

it('gets with no body, posts one, and sets a header to its last value', function (): void {
    $get = Request::get('https://token.example')->with('Accept', 'text/plain')->with('Accept', 'application/json');
    $post = Request::post('https://token.example', 'a=b')->sending(MediaType::Form);

    expect([$get->method(), $get->body(), $get->headers()])->toBe([Method::Get, '', ['Accept' => 'application/json']])
        ->and([$post->method(), $post->body(), $post->headers()])
        ->toBe([Method::Post, 'a=b', ['Content-Type' => 'application/x-www-form-urlencoded']])
        ->and(MediaType::Json->value)->toBe('application/json');
});
