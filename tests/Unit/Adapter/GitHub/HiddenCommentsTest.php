<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\GitHub\Answer;
use NightWorksIO\MutationGate\Adapter\GitHub\Api;
use NightWorksIO\MutationGate\Adapter\GitHub\HiddenComments;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Tests\Support\Decoded;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Comments as GitHub's REST API lists them, each by this node id.
 *
 * @return list<Answer>
 */
function restComments(string ...$ids): array
{
    $client = new MockHttpClient(new JsonMockResponse(array_map(static fn(string $id): array => ['node_id' => $id], $ids)));
    $answer = Api::at($client, 'https://api.github.example', 'secret')->get('/comments');

    return $answer instanceof Answer ? $answer->items() : [];
}

/** The answer of one comment hidden. */
function commentHidden(bool $hidden = true): JsonMockResponse
{
    return new JsonMockResponse(['data' => ['minimizeComment' => ['minimizedComment' => ['isMinimized' => $hidden]]]]);
}

it('asks nothing of no comments', function (): void {
    expect(HiddenComments::at(new MockHttpClient([]), '', '', 'secret')->hide([]))->toBe(0);
});

it('hides each comment not hidden yet, as outdated, and leaves those hidden already', function (): void {
    $known = new JsonMockResponse(['data' => ['nodes' => [['id' => 'one', 'isMinimized' => true], ['id' => 'two', 'isMinimized' => false]]]]);
    $hide = commentHidden();
    $hidden = HiddenComments::at(new MockHttpClient([$known, $hide]), 'https://api.github.example', '', 'secret')
        ->hide(restComments('one', 'two'));
    $body = is_string($known->getRequestOptions()['body']) ? $known->getRequestOptions()['body'] : '';

    expect($hidden)->toBe(1)
        ->and($known->getRequestMethod())->toBe('POST')
        ->and($known->getRequestUrl())->toBe('https://api.github.example/graphql')
        ->and(Decoded::at($body, 'query'))->toBe('query { nodes(ids: ["one", "two"]) { ... on IssueComment { id isMinimized } } }')
        ->and(Decoded::at(is_string($hide->getRequestOptions()['body']) ? $hide->getRequestOptions()['body'] : '', 'query'))
        ->toBe('mutation { minimizeComment(input: {subjectId: "two", classifier: OUTDATED}) { minimizedComment { isMinimized } } }');
});

it('asks the GraphQL endpoint GitHub names, or else GitHub\'s own', function (): void {
    $named = new JsonMockResponse(['data' => ['nodes' => []]]);
    $own = new JsonMockResponse(['data' => ['nodes' => []]]);

    HiddenComments::at(new MockHttpClient($named), 'https://api.github.example', 'https://graphql.example/query', 'secret')->hide(restComments('one'));
    HiddenComments::at(new MockHttpClient($own), '', '', 'secret')->hide(restComments('one'));

    expect($named->getRequestUrl())->toBe('https://graphql.example/query')
        ->and($own->getRequestUrl())->toBe('https://api.github.com/graphql');
});

it('counts no comment GitHub did not hide, and says why where it cannot say which are hidden', function (): void {
    $known = static fn(): JsonMockResponse => new JsonMockResponse(['data' => ['nodes' => [['id' => 'a', 'isMinimized' => false]]]]);
    $hiding = static fn(MockResponse $answer): CannotTell|int => HiddenComments::at(new MockHttpClient([$known(), $answer]), 'https://api.github.example', '', 'secret')
        ->hide(restComments('a'));
    $refused = HiddenComments::at(new MockHttpClient(new MockResponse('{}', ['http_code' => 403])), 'https://api.github.example', '', 'secret')
        ->hide(restComments('a'));

    expect($hiding(new MockResponse('{}', ['http_code' => 500])))->toBe(0)
        ->and($hiding(commentHidden(hidden: false)))->toBe(0)
        ->and($hiding(new JsonMockResponse(['errors' => [['message' => 'no']]])))->toBe(0)
        ->and($hiding(commentHidden()))->toBe(1)
        ->and($refused)->toBeInstanceOf(CannotTell::class);
});

it('writes a quote or a backslash of an id so it stays one string of the query', function (): void {
    $known = new JsonMockResponse(['data' => ['nodes' => []]]);

    HiddenComments::at(new MockHttpClient($known), 'https://api.github.example', '', 'secret')->hide(restComments('a"b\\c'));

    expect(Decoded::at(is_string($known->getRequestOptions()['body']) ? $known->getRequestOptions()['body'] : '', 'query'))
        ->toContain('["a\\"b\\\\c"]');
});
