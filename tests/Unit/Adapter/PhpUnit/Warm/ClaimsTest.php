<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpUnit\Warm\Claims;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Warm\Workplace;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\WarmClaims;

afterEach(function (): void {
    Scratch::sweep();
});

it('claims each run once, in order, across every worker, and none once all are claimed', function (): void {
    $workplace = Workplace::at(sprintf('%s/warm', Scratch::directory()));
    $job = WarmClaims::job(3, NotGiven::value());
    $workplace->opened($job);
    $first = Claims::of($workplace, $job);
    $second = Claims::of($workplace, $job);

    expect([$first->next(), $second->next(), $first->next(), $second->next(), $first->next()])
        ->toEqual([0, 1, 2, NotGiven::value(), NotGiven::value()]);
});

it('claims no run once the job\'s end has come', function (): void {
    $workplace = Workplace::at(sprintf('%s/warm', Scratch::directory()));
    $job = WarmClaims::job(3, microtime(as_float: true) - 1.0);
    $workplace->opened($job);

    expect(Claims::of($workplace, $job)->next())->toEqual(NotGiven::value())
        ->and(file_get_contents($workplace->claimed()))->toBe('0');
});

it('claims nothing where the count of claims cannot be opened', function (): void {
    $job = WarmClaims::job(1, NotGiven::value());

    expect(Claims::of(Workplace::at(sprintf('%s/nowhere', Scratch::directory())), $job)->next())->toEqual(NotGiven::value());
});
