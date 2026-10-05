<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\GitHub\GitHubPlan;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\PlanListing;
use NightWorksIO\MutationGate\Core\Ci\Publication;
use NightWorksIO\MutationGate\Core\Ci\PullRequestNumber;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\Shards;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ShardedPlan;

afterEach(function (): void {
    Scratch::sweep();
});

/** Where a GitHub Actions step's outputs go, as `GITHUB_OUTPUT` names it. */
const GITHUB_OUTPUT = '/home/runner/work/_temp/_runner_file_commands/set_output';

it('appends the shards, by id and label, to the file GitHub reads a step\'s outputs from', function (): void {
    $plan = ShardedPlan::of(2);

    expect(GitHubPlan::in(Variables::of(['GITHUB_OUTPUT' => GITHUB_OUTPUT]))->publish($plan))->toEqual(Publication::appended(
        GITHUB_OUTPUT,
        sprintf(
            "shards=%s\nplan=%s\n",
            '[{"id":1,"label":"src, part 1 of 2"},{"id":2,"label":"src, part 2 of 2"}]',
            PlanListing::inline($plan),
        ),
    ));
});

it('writes a label as it is, slashes and all', function (): void {
    $label = 'src/Http, part 1 of 2 — naïve';
    $plan = Plan::of(Revision::ref('5eeca8f'), Digest::sha256Of('base'), Keys::none(), Shards::of(
        Shard::of(ShardId::of(1), Package::at(Path::root()), Units::none(), Seconds::of(1.0), $label),
    ));
    $published = GitHubPlan::in(Variables::of(['GITHUB_OUTPUT' => GITHUB_OUTPUT]))->publish($plan);

    expect($published instanceof Publication ? $published->text() : $published)->toBe(sprintf(
        "shards=[{\"id\":1,\"label\":\"src/Http, part 1 of 2 — naïve\"}]\nplan=%s\n",
        PlanListing::inline($plan),
    ));
});

it('names no unit in its outputs, which the plan file carries, so a plan of any size fits the 1 MB GitHub takes', function (): void {
    $units = array_map(static fn(int $n): Unit => Unit::file(Path::of(sprintf('src/Domain/Billing/Invoices/Unit%05d.php', $n))), range(1, 30_000));
    $plan = Plan::of(Revision::ref('5eeca8f'), Digest::sha256Of('base'), Keys::none(), Shards::of(
        Shard::of(ShardId::of(1), Package::at(Path::root()), Units::of(...$units), Seconds::of(1.0), 'src, part 1 of 1'),
    ));
    $published = GitHubPlan::in(Variables::of(['GITHUB_OUTPUT' => GITHUB_OUTPUT]))->publish($plan);
    $text = $published instanceof Publication ? $published->text() : '';

    expect($text)->not->toContain('Unit00001.php')
        ->and(strlen($text))->toBeLessThan(1_000);
});

it('hands a matrix nothing to run for a plan with no shards', function (): void {
    $plan = ShardedPlan::of(0);

    expect(GitHubPlan::in(Variables::of(['GITHUB_OUTPUT' => GITHUB_OUTPUT]))->publish($plan))
        ->toEqual(Publication::appended(GITHUB_OUTPUT, sprintf("shards=[]\nplan=%s\n", PlanListing::inline($plan))));
});

it('fills a matrix up to its 256 jobs and refuses one more', function (): void {
    $github = GitHubPlan::in(Variables::of(['GITHUB_OUTPUT' => GITHUB_OUTPUT]));

    expect(GitHubPlan::MOST_JOBS)->toBe(256)
        ->and($github->publish(ShardedPlan::of(256)))->toBeInstanceOf(Publication::class)
        ->and($github->publish(ShardedPlan::of(257)))->toEqual(CannotJudge::because(
            'The plan holds 257 shards, and a GitHub matrix runs at most 256 jobs. Set shards.max to 256 or less.',
        ));
});

it('cannot hand a plan to a matrix outside a GitHub Actions step', function (): void {
    expect(GitHubPlan::in(Variables::of([]))->publish(ShardedPlan::of(1)))->toEqual(CannotJudge::because(
        'GITHUB_OUTPUT is not set, so the plan cannot reach the matrix. Run plan in a GitHub Actions step.',
    ));
});

$event = static function (string $json): string {
    $file = sprintf('%s/event.json', Scratch::directory());
    file_put_contents($file, $json);

    return $file;
};

it('reads a pull request from its merge ref, and the default branch from the event payload', function () use ($event): void {
    $github = GitHubPlan::in(Variables::of([
        'GITHUB_EVENT_NAME' => 'pull_request',
        'GITHUB_REF' => 'refs/pull/12/merge',
        'GITHUB_EVENT_PATH' => $event('{"repository": {"default_branch": "trunk"}}'),
    ]));

    expect($github->runOn())->toEqual(RunOn::pullRequest(PullRequestNumber::parse('12'), RunOn::branchNamed('trunk')));
});

it('takes the pull request the payload names, whatever the event and its ref', function (string $name) use ($event): void {
    $github = GitHubPlan::in(Variables::of([
        'GITHUB_EVENT_NAME' => $name,
        'GITHUB_REF' => 'refs/heads/main',
        'GITHUB_EVENT_PATH' => $event('{"repository": {"default_branch": "main"}, "pull_request": {"number": 7}}'),
    ]));

    expect($github->runOn())->toEqual(RunOn::pullRequest(PullRequestNumber::parse('7'), RunOn::branchNamed('main')));
})->with(['pull_request_target', 'pull_request', 'push']);

it('reads a branch on push, schedule and workflow_dispatch, for the commit GitHub names', function (string $name) use ($event): void {
    $github = GitHubPlan::in(Variables::of([
        'GITHUB_EVENT_NAME' => $name,
        'GITHUB_REF' => 'refs/heads/release/2.x',
        'GITHUB_SHA' => '5eeca8f',
        'GITHUB_EVENT_PATH' => $event('{"repository": {"default_branch": "main"}}'),
    ]));
    $run = RunOn::branch('release/2.x', RunOn::branchNamed('main'));

    expect($github->runOn())->toEqual($run instanceof RunOn ? $run->withCommit(Revision::ref('5eeca8f')) : $run);
})->with(['push', 'schedule', 'workflow_dispatch']);

it('gives no scope to an event that acts on code from elsewhere, so it writes nothing', function (string $name) use ($event): void {
    $github = GitHubPlan::in(Variables::of([
        'GITHUB_EVENT_NAME' => $name,
        'GITHUB_REF' => 'refs/heads/main',
        'GITHUB_EVENT_PATH' => $event('{"repository": {"default_branch": "main"}}'),
    ]));

    expect($github->runOn())->toEqual(RunOn::detached(RunOn::branchNamed('main')));
})->with(['workflow_run', 'issue_comment', 'pull_request_target', 'release', '']);

it('gives no scope to a tag, or to a pull request ref outside a pull request event', function (string $ref): void {
    expect(GitHubPlan::in(Variables::of(['GITHUB_EVENT_NAME' => 'push', 'GITHUB_REF' => $ref]))->runOn())
        ->toEqual(RunOn::detached(CannotTell::because('No event payload could be read, so the default branch is not known.')));
})->with(['refs/tags/v1', 'refs/pull/12/merge']);

it('reads a pull request event with no payload from its merge ref alone', function (): void {
    $github = GitHubPlan::in(Variables::of(['GITHUB_EVENT_NAME' => 'pull_request', 'GITHUB_REF' => 'refs/pull/12/merge']));

    expect($github->runOn())->toEqual(RunOn::pullRequest(
        PullRequestNumber::parse('12'),
        CannotTell::because('No event payload could be read, so the default branch is not known.'),
    ));
});

it('takes no pull request from a payload whose number is not one', function () use ($event): void {
    $github = GitHubPlan::in(Variables::of([
        'GITHUB_EVENT_NAME' => 'push',
        'GITHUB_REF' => 'refs/heads/main',
        'GITHUB_EVENT_PATH' => $event('{"repository": {"default_branch": "main"}, "pull_request": {"number": "7"}}'),
    ]));

    expect($github->runOn())->toEqual(RunOn::branch('main', RunOn::branchNamed('main')));
});

it('cannot tell the default branch from a payload that does not name it or cannot be read', function (): void {
    $root = Scratch::directory();
    file_put_contents(sprintf('%s/other.json', $root), '{"repository": {"name": "gate"}}');
    file_put_contents(sprintf('%s/locked.json', $root), '{"repository": {"default_branch": "main"}}');
    chmod(sprintf('%s/locked.json', $root), 0o000);
    $unnamed = CannotTell::because('The event payload does not name the default branch.');
    $unread = CannotTell::because('No event payload could be read, so the default branch is not known.');
    $runOn = static fn(string $event): RunOn|CannotTell => GitHubPlan::in(Variables::of([
        'GITHUB_EVENT_NAME' => 'push',
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

it('is run by the workflow GITHUB_WORKFLOW_REF names, and by none where it names none', function (): void {
    $github = GitHubPlan::in(Variables::of([
        'GITHUB_WORKFLOW_REF' => 'nightworksio/gate/.github/workflows/mutation.yml@refs/heads/main',
    ]));

    expect($github->definitions())->toEqual(Paths::of(Path::of('.github/workflows/mutation.yml')))
        ->and(GitHubPlan::in(Variables::of([]))->definitions())->toEqual(Paths::none());
});

it('reads the output file from the environment', function (): void {
    $before = getenv('GITHUB_OUTPUT');
    putenv(sprintf('GITHUB_OUTPUT=%s', GITHUB_OUTPUT));
    $plan = ShardedPlan::of(0);
    $published = GitHubPlan::fromOptions(Options::none())->publish($plan);
    putenv($before === false ? 'GITHUB_OUTPUT' : sprintf('GITHUB_OUTPUT=%s', $before));

    expect($published)->toEqual(Publication::appended(GITHUB_OUTPUT, sprintf("shards=[]\nplan=%s\n", PlanListing::inline($plan))));
});

it('withholds the Actions runtime\'s credentials and the workflow\'s token', function (): void {
    expect(GitHubPlan::withheld())->toEqual(Withheld::of('ACTIONS_*', 'GITHUB_TOKEN'));
});
