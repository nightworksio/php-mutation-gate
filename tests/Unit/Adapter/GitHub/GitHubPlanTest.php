<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\GitHub\GitHubPlan;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\Shards;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Extension\Options;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ShardedPlan;

afterEach(function (): void {
    Scratch::sweep();
});

it('appends the shards, by id and label, to the file GitHub reads a step\'s outputs from', function (): void {
    $output = sprintf('%s/output', Scratch::directory());
    file_put_contents($output, "earlier=1\n");

    $github = GitHubPlan::in(Variables::of(['GITHUB_OUTPUT' => $output]));

    expect($github->publish(ShardedPlan::of(2)))->toEqual(Written::to($output))
        ->and(file_get_contents($output))->toBe(
            "earlier=1\n"
            . 'shards=[{"id":1,"label":"src, part 1 of 2"},{"id":2,"label":"src, part 2 of 2"}]'
            . "\n",
        );
});

it('writes a label as it is, slashes and all', function (): void {
    $output = sprintf('%s/output', Scratch::directory());
    $label = 'src/Http, part 1 of 2 — naïve';
    $plan = Plan::of(Revision::ref('5eeca8f'), Keys::none(), Shards::of(
        Shard::of(ShardId::of(1), Package::at(Path::root()), Units::none(), Seconds::of(1.0), $label),
    ));
    GitHubPlan::in(Variables::of(['GITHUB_OUTPUT' => $output]))->publish($plan);

    expect(file_get_contents($output))->toBe("shards=[{\"id\":1,\"label\":\"src/Http, part 1 of 2 — naïve\"}]\n");
});

it('hands a matrix nothing to run for a plan with no shards', function (): void {
    $output = sprintf('%s/output', Scratch::directory());
    GitHubPlan::in(Variables::of(['GITHUB_OUTPUT' => $output]))->publish(ShardedPlan::of(0));

    expect(file_get_contents($output))->toBe("shards=[]\n");
});

it('fills a matrix up to its 256 jobs and refuses one more', function (): void {
    $output = sprintf('%s/output', Scratch::directory());
    $github = GitHubPlan::in(Variables::of(['GITHUB_OUTPUT' => $output]));

    expect(GitHubPlan::MOST_JOBS)->toBe(256)
        ->and($github->publish(ShardedPlan::of(256)))->toEqual(Written::to($output))
        ->and($github->publish(ShardedPlan::of(257)))->toEqual(CannotJudge::because(
            'The plan holds 257 shards, and a GitHub matrix runs at most 256 jobs. Set shards.max to 256 or less.',
        ));
});

it('cannot hand a plan to a matrix outside a GitHub Actions step', function (): void {
    expect(GitHubPlan::in(Variables::of([]))->publish(ShardedPlan::of(1)))->toEqual(CannotJudge::because(
        'GITHUB_OUTPUT is not set, so the plan cannot reach the matrix. Run plan in a GitHub Actions step.',
    ));
});

it('cannot judge an output file it cannot write', function (): void {
    $root = Scratch::directory();
    $github = GitHubPlan::in(Variables::of(['GITHUB_OUTPUT' => sprintf('%s/missing/output', $root)]));
    set_error_handler(static fn(): bool => true);
    $written = $github->publish(ShardedPlan::of(1));
    restore_error_handler();

    expect($written)->toEqual(CannotJudge::because(sprintf('%s/missing/output could not be written.', $root)));
});

it('names the shard a job was started as', function (): void {
    expect(GitHubPlan::in(Variables::of(['SHARD' => '2']))->shard(ShardedPlan::of(3)))->toEqual(ShardId::of(2));
});

it('reads a pull request from its merge ref, and the default branch from the event payload', function (): void {
    $event = sprintf('%s/event.json', Scratch::directory());
    file_put_contents($event, '{"repository": {"default_branch": "trunk"}}');
    $github = GitHubPlan::in(Variables::of([
        'GITHUB_EVENT_NAME' => 'pull_request',
        'GITHUB_REF' => 'refs/pull/12/merge',
        'GITHUB_EVENT_PATH' => $event,
    ]));

    expect($github->runOn())->toEqual(RunOn::pullRequest('12', RunOn::branchNamed('trunk')));
});

it('reads a push as its branch', function (): void {
    $event = sprintf('%s/event.json', Scratch::directory());
    file_put_contents($event, '{"repository": {"default_branch": "main"}}');
    $github = GitHubPlan::in(Variables::of([
        'GITHUB_EVENT_NAME' => 'push',
        'GITHUB_REF' => 'refs/heads/release/2.x',
        'GITHUB_EVENT_PATH' => $event,
    ]));

    expect($github->runOn())->toEqual(RunOn::branch('release/2.x', RunOn::branchNamed('main')));
});

it('reads a pull request ref as a pull request only on a pull request event', function (): void {
    $push = GitHubPlan::in(Variables::of(['GITHUB_EVENT_NAME' => 'push', 'GITHUB_REF' => 'refs/pull/12/merge']));
    $pullRequest = GitHubPlan::in(Variables::of([
        'GITHUB_EVENT_NAME' => 'pull_request',
        'GITHUB_REF' => 'refs/heads/x',
    ]));

    expect($push->runOn())
        ->toEqual(CannotTell::because(
            'GITHUB_REF is "refs/pull/12/merge", which is neither a branch nor a pull request.',
        ))
        ->and($pullRequest->runOn())
        ->toEqual(RunOn::branch('x', CannotTell::because(
            'No event payload could be read, so the default branch is not known.',
        )));
});

it('cannot tell the run of a tag', function (): void {
    expect(GitHubPlan::in(Variables::of(['GITHUB_EVENT_NAME' => 'push', 'GITHUB_REF' => 'refs/tags/v1']))->runOn())
        ->toEqual(CannotTell::because('GITHUB_REF is "refs/tags/v1", which is neither a branch nor a pull request.'));
});

it('cannot tell the default branch from a payload that does not name it or cannot be read', function (): void {
    $root = Scratch::directory();
    file_put_contents(sprintf('%s/other.json', $root), '{"repository": {"name": "gate"}}');
    file_put_contents(sprintf('%s/locked.json', $root), '{"repository": {"default_branch": "main"}}');
    chmod(sprintf('%s/locked.json', $root), 0o000);
    $unnamed = CannotTell::because('The event payload does not name the default branch.');
    $unread = CannotTell::because('No event payload could be read, so the default branch is not known.');
    $runOn = static fn(string $event): RunOn|CannotTell => GitHubPlan::in(Variables::of([
        'GITHUB_REF' => 'refs/heads/main',
        'GITHUB_EVENT_PATH' => $event,
    ]))->runOn();

    set_error_handler(static fn(): bool => true);
    $locked = $runOn(sprintf('%s/locked.json', $root));
    restore_error_handler();

    expect($runOn(sprintf('%s/other.json', $root)))->toEqual(RunOn::branch('main', $unnamed))
        ->and($runOn(sprintf('%s/absent.json', $root)))->toEqual(RunOn::branch('main', $unread))
        ->and($locked)->toEqual(RunOn::branch('main', $unread));
});

it('reads the output file from the environment', function (): void {
    $output = sprintf('%s/output', Scratch::directory());
    $before = getenv('GITHUB_OUTPUT');
    putenv(sprintf('GITHUB_OUTPUT=%s', $output));
    $written = GitHubPlan::fromOptions(Options::none())->publish(ShardedPlan::of(0));
    putenv($before === false ? 'GITHUB_OUTPUT' : sprintf('GITHUB_OUTPUT=%s', $before));

    expect($written)->toEqual(Written::to($output))
        ->and(file_get_contents($output))->toBe("shards=[]\n");
});
