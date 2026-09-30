<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Http\Reply;
use NightWorksIO\MutationGate\Core\NotWritten;

it('holds the status, the Retry-After and the body it was answered with', function (): void {
    $reply = Reply::of(429, '7', 'slow down');

    expect($reply->status())->toBe(429)
        ->and($reply->retryAfter())->toBe('7')
        ->and($reply->body())->toBe('slow down');
});

it('is accepted with a 2xx, and worth trying again after a 429 or a 5xx', function (int $status, bool $accepted, bool $again): void {
    expect(Reply::of($status, '', '')->isAccepted())->toBe($accepted)
        ->and(Reply::of($status, '', '')->isWorthRetrying())->toBe($again);
})->with([
    [199, false, false],
    [200, true, false],
    [204, true, false],
    [299, true, false],
    [300, false, false],
    [404, false, false],
    [428, false, false],
    [429, false, true],
    [430, false, false],
    [499, false, false],
    [500, false, true],
    [503, false, true],
]);

it('says who refused a post, with its status and at most 200 characters of its answer', function (): void {
    expect(Reply::of(403, '', 'invalid_token')->refusedBy('Slack'))->toEqual(NotWritten::because('Slack answered 403: invalid_token'))
        ->and(Reply::of(503, '', str_repeat('x', 300))->refusedBy('the webhook'))
        ->toEqual(NotWritten::because(sprintf('the webhook answered 503: %s…', str_repeat('x', 199))));
});

it('says what could not be reached, and why', function (): void {
    expect(Reply::unreached('Discord', 'Could not resolve host'))->toEqual(NotWritten::because('Discord could not be reached: Could not resolve host'));
});

it('says an answer on one plain line, and never a URL a failure names', function (): void {
    expect(Reply::of(400, '', "bad\n::error::owned\e[31m")->refusedBy('the collector'))
        ->toEqual(NotWritten::because('the collector answered 400: bad ::error::owned[31m'))
        ->and(Reply::unreached('Slack', "Timed out for \"https://hooks.example/T/B/secret\"\nagain"))
        ->toEqual(NotWritten::because('Slack could not be reached: Timed out for "Slack" again'));
});
