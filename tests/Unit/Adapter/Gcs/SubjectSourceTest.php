<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Gcs\SubjectSource;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Http\Request;
use NightWorksIO\MutationGate\Tests\Support\Cloud;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('reads the CI\'s token from a file, whole and trimmed, or from a field of its JSON', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'text', "  jwt\n");
    Scratch::write($root, 'json', '{"id_token": "jwt", "other": "x"}');
    $exchange = new Cloud()->exchange();

    expect(SubjectSource::file(sprintf('%s/text', $root), '')->token($exchange))->toBe('jwt')
        ->and(SubjectSource::file(sprintf('%s/json', $root), 'id_token')->token($exchange))->toBe('jwt')
        ->and(SubjectSource::file(sprintf('%s/json', $root), 'missing')->token($exchange))
        ->toEqual(CannotJudge::because(sprintf('the CI\'s token from %s/json is empty', $root)))
        ->and(SubjectSource::file(sprintf('%s/none', $root), '')->token($exchange))
        ->toEqual(CannotJudge::because(sprintf('the CI\'s token could not be read from %s/none', $root)));
});

it('asks for the CI\'s token with the request the file describes, whole or from a field of the answer', function (): void {
    $cloud = new Cloud()
        ->answering('https://token.example/text', 200, 'jwt')
        ->answering('https://token.example/json', 200, '{"value": "jwt"}')
        ->answering('https://token.example/denied', 403, 'no');
    $exchange = $cloud->exchange();

    expect(SubjectSource::url(Request::get('https://token.example/text')->with('Authorization', 'Bearer r'), '')->token($exchange))->toBe('jwt')
        ->and(SubjectSource::url(Request::get('https://token.example/json'), 'value')->token($exchange))->toBe('jwt')
        ->and(SubjectSource::url(Request::get('https://token.example/denied'), 'value')->token($exchange))
        ->toEqual(CannotJudge::because('https://token.example answered 403: no'))
        ->and($cloud->requests[0]['headers']['authorization'])->toBe('Bearer r');
});
