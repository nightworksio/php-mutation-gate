<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\CommandLine;
use NightWorksIO\MutationGate\Cli\Config\Detected;
use NightWorksIO\MutationGate\Cli\Config\Effective;
use NightWorksIO\MutationGate\Cli\Config\Registered;
use NightWorksIO\MutationGate\Cli\Config\RunContext;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Context;
use NightWorksIO\MutationGate\Core\Config\ContextChain;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Registry\Origin;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Decoded;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

afterEach(function (): void {
    Scratch::sweep();
});

/** The effective config of a project whose config file holds this, with a preset `acme` that writes blocks too. */
$effective = static function (array $config, array $preset = []): Effective {
    $project = Scratch::directory();
    Scratch::write($project, 'mutation-gate.json', Configs::json(['runner' => 'pest', ...$config]));
    $registry = Extensions::of(Origin::of('nightworksio/mutation-gate'))
        ->withPreset(Name::of('acme'), Configs::valid($preset));

    return new Effective(
        $project,
        Registered::config($registry, static fn(): bool => true),
        new Detected(Directory::at($project), Directory::at(sprintf('%s/vendor', $project))),
        new DateTimeImmutable(Configs::NOW),
        new RunContext(Variables::of([])),
    );
};

/** A command line in this context. */
$in = static fn(Context $context): CommandLine => CommandLine::nothing()->defaultingTo($context);

/** What the effective config shows under a key, or every problem. */
$shown = static fn(Settings|Invalid|CannotJudge $settings, string $key): mixed => $settings instanceof Settings
    ? Configs::shown($settings, $key)
    : Configs::problems($settings);

it('lays the block of each context the run is in over the rest of the file, and the command line over both', function () use (
    $effective,
    $in,
    $shown,
): void {
    $gate = $effective(['budget' => '30m', 'contexts' => [
        'local' => ['budget' => '2m'],
        'ci' => ['budget' => '20m'],
        'pr' => ['budget' => '10m'],
    ]]);

    expect([
        $shown($gate->settings($in(Context::Local)), 'budget'),
        $shown($gate->settings($in(Context::Ci)), 'budget'),
        $shown($gate->settings($in(Context::PullRequest)), 'budget'),
        $shown($gate->settings($in(Context::Main)), 'budget'),
        $shown($gate->settings($in(Context::PullRequest)->withBudget('1m')), 'budget'),
        $shown($gate->settings(CommandLine::nothing()), 'budget'),
    ])->toBe(['2m', '20m', '10m', '20m', '1m', '2m']);
});

it('resolves each layer for the run before laying them, so the file\'s own setting beats a preset\'s block', function () use (
    $effective,
    $in,
    $shown,
): void {
    $gate = $effective(
        ['preset' => 'acme', 'budget' => '30m', 'contexts' => ['main' => ['shards' => ['max' => 2]]]],
        ['budget' => '5m', 'shards' => ['max' => 8], 'contexts' => ['pr' => ['budget' => '9m', 'shards' => ['max' => 4]]]],
    );

    expect($shown($gate->settings($in(Context::PullRequest)), 'budget'))->toBe('30m')
        ->and($shown($gate->settings($in(Context::PullRequest)), 'shards'))->toMatchArray(['max' => 4])
        ->and($shown($gate->settings($in(Context::Main)), 'shards'))->toMatchArray(['max' => 2]);
});

it('keeps every layer\'s blocks as written, laid by context, and drops them once a run lays them', function () use (
    $effective,
    $in,
): void {
    $gate = $effective(
        ['preset' => 'acme', 'contexts' => ['pr' => ['budget' => '10m']]],
        ['contexts' => ['pr' => ['shards' => ['max' => 4]], 'local' => ['budget' => '1m']]],
    );
    $written = $gate->asWritten(CommandLine::nothing());
    $resolved = $gate->settings($in(Context::PullRequest));

    expect($written instanceof Settings ? Configs::shown($written, 'contexts') : Configs::problems($written))->toBe([
        'local' => ['budget' => '1m'],
        'pr' => ['shards' => ['max' => 4], 'budget' => '10m'],
    ])->and($resolved instanceof Settings ? Decoded::at($resolved->effective()->written(ProjectRoot::origin())->line(), 'contexts') : $resolved)
        ->toBeNull();
});

it('keys a proof by the settings of the contexts the run is in', function () use ($effective, $in): void {
    $gate = $effective(['contexts' => ['pr' => ['timeouts' => ['seconds' => 90]], 'main' => ['budget' => '1h']]]);
    $canonical = static fn(Context $context): string => ($settings = $gate->settings($in($context))) instanceof Settings
        ? $settings->canonical()
        : implode(', ', Configs::problems($settings));

    expect($canonical(Context::PullRequest))->not->toBe($canonical(Context::Ci))
        ->and($canonical(Context::Main))->toBe($canonical(Context::Ci));
});

it('names the contexts a run is in: local outside CI, or those --context names', function () use ($effective, $in): void {
    $gate = $effective([]);
    $chain = static fn(CommandLine $given): string => ($contexts = $gate->contexts($given)) instanceof ContextChain
        ? $contexts->spelt()
        : implode(', ', Configs::problems($contexts));
    $named = CommandLine::from(new ArrayInput(
        ['--context' => 'staging'],
        new InputDefinition([new InputOption('context', mode: InputOption::VALUE_REQUIRED)]),
    ));

    expect([$chain(CommandLine::nothing()), $chain($in(Context::Main)), $chain($named)])
        ->toBe(['local', 'ci, main', '--context: expected local, ci, github, gitlab, bitbucket, azure, buildkite, jenkins, circleci, pr or main, got "staging"'])
        ->and(Configs::problems($gate->settings($named)))
        ->toBe(['--context: expected local, ci, github, gitlab, bitbucket, azure, buildkite, jenkins, circleci, pr or main, got "staging"']);
});

it('refuses a block that sets what decides the context, with every other problem, before telling the context', function () use (
    $effective,
): void {
    expect(Configs::problems($effective(['contexts' => ['pr' => ['ci' => ['plan' => 'github']]]])->settings(CommandLine::nothing())))
        ->toBe(['contexts.pr.ci.plan: expected nothing: a context block cannot set what is read before the context is known']);
});
