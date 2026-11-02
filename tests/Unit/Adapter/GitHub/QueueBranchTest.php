<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\GitHub\QueueBranch;
use NightWorksIO\MutationGate\Core\NotGiven;

it('reads the pull request a merge queue built its group for, from its ref or its branch\'s name', function (string $ref, int $number): void {
    $branch = QueueBranch::parse($ref);

    expect($branch instanceof QueueBranch ? $branch->pullRequest()->value() : 0)->toBe($number);
})->with([
    'a ref' => ['refs/heads/gh-readonly-queue/main/pr-46758-85c329847be78b0a7192b7c949beca23d38ca32f', 46758],
    'a branch' => ['gh-readonly-queue/main/pr-7-85c329847be78b0a7192b7c949beca23d38ca32f', 7],
    'a base branch with a slash' => ['refs/heads/gh-readonly-queue/release/2.x/pr-12-85c329847be78b0a7192b7c949beca23d38ca32f', 12],
    'a SHA-256 base' => [fn(): string => sprintf('gh-readonly-queue/main/pr-3-%s', str_repeat('a', 64)), 3],
]);

it('reads no pull request from any other branch', function (string $ref): void {
    expect(QueueBranch::parse($ref))->toEqual(NotGiven::value());
})->with([
    'a branch' => ['refs/heads/main'],
    'no base commit' => ['refs/heads/gh-readonly-queue/main/pr-7'],
    'a short base commit' => ['refs/heads/gh-readonly-queue/main/pr-7-85c3298'],
    'no base branch' => ['refs/heads/gh-readonly-queue/pr-7-85c329847be78b0a7192b7c949beca23d38ca32f'],
    'a number from zero' => ['refs/heads/gh-readonly-queue/main/pr-0-85c329847be78b0a7192b7c949beca23d38ca32f'],
    'another prefix' => ['refs/heads/x/gh-readonly-queue/main/pr-7-85c329847be78b0a7192b7c949beca23d38ca32f'],
    'a pull request\'s ref' => ['refs/pull/7/merge'],
]);

it('names the prefix every branch of a merge queue has', function (): void {
    expect(QueueBranch::PREFIX)->toBe('gh-readonly-queue/');
});
