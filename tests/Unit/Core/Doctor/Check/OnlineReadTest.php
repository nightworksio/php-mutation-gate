<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Doctor\Check\OnlineRead;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;
use NightWorksIO\MutationGate\Tests\Support\Online;

it('advises where no repository on GitHub was found, and fails no run', function (): void {
    expect(OnlineRead::in(Online::observed(CannotTell::because('git names no origin remote.'))))->toEqual(Findings::of(Finding::of(
        Slug::NoGitHubRepository,
        Severity::Advice,
        '--online found no repository on GitHub. git names no origin remote.',
        '--online reads GitHub\'s settings alone; every other check still ran.',
        'Run doctor --online where GITHUB_REPOSITORY names the repository, or git\'s origin remote is on GitHub.',
    )));
});

it('advises of each setting GitHub would not show, with the permission that shows it', function (): void {
    $unread = CannotTell::because('HTTP/2 403 returned.');

    expect(array_map(
        static fn(Finding $finding): array => [$finding->slug(), $finding->severity(), $finding->found(), $finding->fix()],
        [...OnlineRead::in(Online::observed(Online::settings($unread, $unread, $unread)))],
    ))->toBe([
        [Slug::OnlineUnread, Severity::Advice, 'GitHub did not show the checks the default branch requires of octo/gate. HTTP/2 403 returned.', 'Run doctor --online with a GITHUB_TOKEN or GH_TOKEN that has contents: read.'],
        [Slug::OnlineUnread, Severity::Advice, 'GitHub did not show the fork approval policy of octo/gate. HTTP/2 403 returned.', 'Run doctor --online with a GITHUB_TOKEN or GH_TOKEN that has administration: read.'],
        [Slug::OnlineUnread, Severity::Advice, 'GitHub did not show the scheduled runs of octo/gate. HTTP/2 403 returned.', 'Run doctor --online with a GITHUB_TOKEN or GH_TOKEN that has actions: read.'],
    ])->and([...OnlineRead::in(Online::observed(Online::settings($unread, $unread, $unread)))][0]->why())
        ->toBe('doctor cannot tell whether it is set as the gate needs.');
});

it('finds nothing where every setting was read, or --online was not given', function (): void {
    expect(OnlineRead::in(Online::observed(Online::wellSet())))->toEqual(Findings::none())
        ->and(OnlineRead::in(Observations::none()))->toEqual(Findings::none());
});
