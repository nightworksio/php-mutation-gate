<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Doctor\Asked;
use NightWorksIO\MutationGate\Core\Doctor\Check\ArmRunners;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\GitHub\ForkApproval;
use NightWorksIO\MutationGate\Core\Doctor\GitHub\GitHubSettings;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\ProjectFiles;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;
use NightWorksIO\MutationGate\Tests\Support\GateRunning;
use NightWorksIO\MutationGate\Tests\Support\Online;

$observed = static fn(bool $public): Observations => Observations::none()->withAsked(Asked::nothing()->withGitHub(GitHubSettings::of(
    'octo/gate',
    'main',
    Listed::of('mutation / verdict'),
    ForkApproval::AllExternalContributors,
    Online::ranAt('2026-09-29T03:00:00Z'),
    isPublic: $public,
)));

$running = static fn(string $text): ProjectFiles => ProjectFiles::none()
    ->withRunningTheGate(GateRunning::at($text, Path::of('.github/workflows/gate.yml')));

it('advises the arm runner for a public repository whose gate workflows name none', function () use ($observed, $running): void {
    $advice = Findings::of(Finding::of(
        Slug::ArmRunners,
        Severity::Advice,
        'octo/gate is public, and the workflows that run the gate name no arm runner.',
        <<<'WHY'
            GitHub's hosted arm runners are free for a public repository, and the gate's shards took 29% fewer runner
            minutes on them where the gate measured it. Proofs are keyed by the architecture, so the first run there is
            a full one.
            WHY,
        'Pass `runs-on: ubuntu-24.04-arm` to the reusable workflow, where every extension the tests need builds on arm.',
    ));

    expect(ArmRunners::in($observed(public: true)))->toEqual($advice)
        ->and(ArmRunners::in($observed(public: true)->withFiles($running("runs-on: ubuntu-latest\n"))))
        ->toEqual($advice);
});

it('finds nothing where a gate workflow names an arm runner, the repository is private or GitHub was not asked', function () use ($observed, $running): void {
    expect(ArmRunners::in($observed(public: true)->withFiles($running("runs-on: ubuntu-22.04-arm\n"))))
        ->toEqual(Findings::none())
        ->and(ArmRunners::in($observed(public: false)))->toEqual(Findings::none())
        ->and(ArmRunners::in(Observations::none()))->toEqual(Findings::none());
});
