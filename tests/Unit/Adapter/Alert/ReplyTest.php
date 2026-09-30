<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Alert\Reply;

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
