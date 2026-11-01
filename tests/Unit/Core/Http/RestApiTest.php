<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Lenient;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Http\RestApi;
use NightWorksIO\MutationGate\Core\Http\Token;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\Cloud;

it('asks a path under its base, for JSON, with its bearer token', function (): void {
    $cloud = new Cloud()->answering('https://forge.example/api/v4/user', 200, '{"username": "bot"}');
    $api = RestApi::at($cloud->exchange(), 'https://forge.example/api/v4', Token::bearer('t0ken'));
    $user = $api->json($api->get('/user'));

    expect($user instanceof Node ? Lenient::text($user->field('username')) : '')->toBe('bot')
        ->and($cloud->requests[0]['method'])->toBe('GET')
        ->and($cloud->requests[0]['headers'])->toBe(['accept' => 'application/json', 'authorization' => 'Bearer t0ken']);
});

it('posts, puts and deletes, sending each document as JSON on one line', function (): void {
    $cloud = new Cloud()->answering('https://forge.example/notes', 201, '{}')->answering('https://forge.example/notes/1', 200, '{}');
    $api = RestApi::at($cloud->exchange(), 'https://forge.example', NotGiven::value());
    $document = Json::object(Member::of('body', "two\nlines"));

    $api->post('/notes', $document);
    $api->put('/notes/1', $document);
    $api->delete('/notes/1');

    expect(array_column($cloud->requests, 'method'))->toBe(['POST', 'PUT', 'DELETE'])
        ->and(array_column($cloud->requests, 'body'))->toBe(['{"body":"two\nlines"}', '{"body":"two\nlines"}', ''])
        ->and($cloud->requests[0]['headers']['content-type'] ?? '')->toBe('application/json')
        ->and($cloud->requests[0]['headers']['accept'] ?? '')->toBe('application/json')
        ->and($cloud->requests[2]['headers'])->toBe(['accept' => 'application/json']);
});

it('says a refusal by the API\'s origin alone, with its status and its answer, never the path or the token', function (): void {
    $cloud = new Cloud()->answering('https://forge.example/api/v4/projects/42/secret', 401, '{"message": "401 Unauthorized"}');
    $api = RestApi::at($cloud->exchange(), 'https://forge.example/api/v4', Token::bearer('t0ken'));

    expect($api->json($api->get('/projects/42/secret')))
        ->toEqual(CannotJudge::because('https://forge.example answered 401: {"message": "401 Unauthorized"}'))
        ->and($api->named())->toBe('https://forge.example');
});
