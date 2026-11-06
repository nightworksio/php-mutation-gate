<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Recheck\ShareOf;

/** The places, counted from 0, of the first ten files that fall to a share. */
$taken = static fn(ShareOf|CannotJudge $share): array => $share instanceof ShareOf
    ? array_values(array_filter(range(0, 9), $share->takes(...)))
    : [$share->why()];

it('gives each of the jobs every n-th file from its own, so the shares keep the order and leave none out', function () use ($taken): void {
    expect($taken(ShareOf::parse('1', '3')))->toBe([0, 3, 6, 9])
        ->and($taken(ShareOf::parse('2', '3')))->toBe([1, 4, 7])
        ->and($taken(ShareOf::parse('3', '3')))->toBe([2, 5, 8])
        ->and($taken(ShareOf::whole()))->toBe(range(0, 9))
        ->and(ShareOf::parse('3', '3') instanceof ShareOf ? ShareOf::parse('3', '3')->share() : null)->toBe(3);
});

it('names no share past the jobs, of none, or written as anything but a whole number', function (string $share, string $of): void {
    expect(ShareOf::parse($share, $of))->toEqual(CannotJudge::because(sprintf(
        '--share=%s --of=%s names no share: give whole numbers, the share at most --of.',
        $share,
        $of,
    )));
})->with([
    'past the jobs' => ['4', '3'],
    'share nought' => ['0', '3'],
    'of nought' => ['1', '0'],
    'no share' => ['', '3'],
    'no jobs' => ['1', ''],
    'a fraction' => ['1.5', '3'],
    'jobs of a fraction' => ['1', '1.5'],
]);

it('lists every share of the jobs, and names none of a count that is no whole number of 1 or more', function (): void {
    $every = ShareOf::every('3');

    expect(is_array($every) ? array_map(static fn(ShareOf $share): int => $share->share(), $every) : $every)->toBe([1, 2, 3])
        ->and(is_array($every) ? $every[1]->takes(4) : $every)->toBeTrue()
        ->and(ShareOf::every('0'))->toEqual(CannotJudge::because('--of=0 names no number of jobs: give a whole number of 1 or more.'))
        ->and(ShareOf::every(''))->toBeInstanceOf(CannotJudge::class);
});
