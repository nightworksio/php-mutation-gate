<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Doctor\Check\TeamMentions;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\ProjectFiles;
use NightWorksIO\MutationGate\Core\Doctor\ServiceFiles;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Owner\CodeOwners;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;
use NightWorksIO\MutationGate\Tests\Support\Configs;

$owned = static fn(string $codeOwners, array $owners = []): Observations => Observations::none()
    ->withSettings(Configs::settings(['runner' => 'pest', ...$owners === [] ? [] : ['owners' => $owners]]))
    ->withFiles(ProjectFiles::none()->withServices(
        ServiceFiles::none()->withCodeOwners(CodeOwners::read(Path::of('.github/CODEOWNERS'), $codeOwners)),
    ));

it('advises that the comment names each team CODEOWNERS names without a mention, where owners are mentioned', function () use ($owned): void {
    expect(TeamMentions::in($owned("/src/ @acme/payments @jo\n/docs/ @acme/docs\n")))->toEqual(Findings::of(Finding::of(
        Slug::TeamsNotMentioned,
        Severity::Advice,
        'CODEOWNERS names a team, which the comment names without a mention when GitHub Actions\' token writes it.',
        'GITHUB_TOKEN cannot read an organisation\'s teams, so github-actions[bot] mentions none.',
        'Write the comment with a token that can read the teams, or set owners.mention to never.',
    )));
});

it('finds nothing where CODEOWNERS names no team, owners are never mentioned, or nothing was observed', function () use ($owned): void {
    expect(TeamMentions::in($owned("/src/ @jo docs@acme.example\n")))->toEqual(Findings::none())
        ->and(TeamMentions::in($owned("/src/ @acme/payments\n", ['mention' => 'never'])))->toEqual(Findings::none())
        ->and(TeamMentions::in(Observations::none()))->toEqual(Findings::none());
});
