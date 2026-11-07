<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Buildkite\BuildAnnotation;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\CommentShows;
use NightWorksIO\MutationGate\Core\Delivery\CommentWrite;
use NightWorksIO\MutationGate\Core\Delivery\Delivery;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Report\Markdown;
use NightWorksIO\MutationGate\Core\Report\PlannedMarkdown;
use NightWorksIO\MutationGate\Core\Report\RecheckedMarkdown;
use NightWorksIO\MutationGate\Core\Runner\ProcessCommand;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Tests\Fakes\ProcessesFake;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Rechecks;
use NightWorksIO\MutationGate\Tests\Support\ShardedPlan;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

const BUILDKITE_BUILD = 'https://buildkite.com/acme/shop/builds/9';

/**
 * The variables of a Buildkite build, with these set.
 *
 * @param array<string, string> $with
 */
function buildkiteBuild(array $with = []): Variables
{
    return Variables::of(['BUILDKITE' => 'true', 'BUILDKITE_BUILD_URL' => BUILDKITE_BUILD, 'BUILDKITE_BUILD_ID' => '9', 'BUILDKITE_PULL_REQUEST' => 'false', ...$with]);
}

/**
 * Processes that keep the body each annotation was given, and end as told.
 *
 * @param ArrayObject<int, string> $bodies
 */
function buildkiteAgent(ArrayObject $bodies, bool $succeeds = true): ProcessesFake
{
    return new ProcessesFake(static function (ProcessCommand $command) use ($succeeds, $bodies): Ran {
        $arguments = [...$command->arguments()];
        $bodies->append((string) file_get_contents($arguments[count($arguments) - 1]));

        return Ran::finished($succeeds, $succeeds ? '' : "no agent token\n");
    });
}

/** @param ArrayObject<int, string> $bodies */
function buildkiteAnnotation(ArrayObject $bodies, Variables $variables, string $options = '{}'): BuildAnnotation
{
    return BuildAnnotation::configured(Configs::options($options), $variables, buildkiteAgent($bodies), '/project');
}

it('annotates the build through the agent, by its context, the body held in a file it removes', function (): void {
    $bodies = new ArrayObject();
    $agent = buildkiteAgent($bodies);
    $answer = BuildAnnotation::configured(Configs::options('{}'), buildkiteBuild(), $agent, '/project')->report(Verdicts::failing());
    $command = $agent->ran()[0];
    $arguments = [...$command->arguments()];

    expect($answer)->toEqual(Written::to(sprintf('the annotation of %s', BUILDKITE_BUILD)))
        ->and([...$bodies])->toBe([Markdown::comment(Verdicts::failing(), CommentShows::unfolded())])
        ->and($command->directory())->toBe('/project')
        ->and(array_slice($arguments, 0, 5))->toBe(['sh', '-c', 'exec buildkite-agent annotate --context "$1" < "$2"', 'sh', 'mutation-gate'])
        ->and(file_exists($arguments[5]))->toBeFalse();
});

it('says what the agent said where it could not annotate, and removes the file', function (): void {
    $agent = buildkiteAgent(new ArrayObject(), succeeds: false);

    expect(BuildAnnotation::configured(Configs::options('{}'), buildkiteBuild(), $agent, '/project')->report(Verdicts::failing()))
        ->toEqual(NotWritten::because('The pull request comment could not be written (no agent token).'))
        ->and(file_exists([...$agent->ran()[0]->arguments()][5]))->toBeFalse();
});

it('writes nothing outside a Buildkite build', function (): void {
    $agent = buildkiteAgent(new ArrayObject());

    expect(BuildAnnotation::configured(Configs::options('{}'), Variables::of([]), $agent, '/project')->report(Verdicts::failing()))
        ->toEqual(NotWritten::because('This run is not a Buildkite build, so there is no annotation to write.'))
        ->and($agent->ran())->toBe([]);
});

it('writes on a pull request\'s build and on any other unless comment.on narrows it', function (string $options, string $pullRequest, string $why): void {
    $answer = buildkiteAnnotation(new ArrayObject(), buildkiteBuild(['BUILDKITE_PULL_REQUEST' => $pullRequest]), $options)->report(Verdicts::failing());

    expect($answer)->toEqual($why === '' ? Written::to(sprintf('the annotation of %s', BUILDKITE_BUILD)) : NotWritten::because($why));
})->with([
    'a pull request' => ['{}', '7', ''],
    'another build' => ['{}', 'false', ''],
    'a build that does not say' => ['{}', '', ''],
    'a pull request, left out' => ['{"comment": {"on": ["default-branch"]}}', '7', 'comment.on leaves out pull requests, so no comment is written.'],
    'another build, left out' => ['{"comment": {"on": ["pull-request"]}}', 'false', 'comment.on leaves out the default branch, so no comment is written.'],
]);

it('shows every section open unless with.comment shapes it', function (): void {
    $shown = new ArrayObject();
    $shaped = new ArrayObject();

    buildkiteAnnotation($shown, buildkiteBuild())->report(Verdicts::failing());
    buildkiteAnnotation($shaped, buildkiteBuild(), '{"comment": {"open": ["verdict"]}}')->report(Verdicts::failing());

    expect([...$shown])->toBe([Markdown::comment(Verdicts::failing(), CommentShows::unfolded())])
        ->and(implode('', [...$shaped]))->toContain('<details><summary>Sets</summary>');
});

it('updates the annotation through its planned and re-checked states, leaving none for deliver', function (): void {
    $bodies = new ArrayObject();
    $annotation = buildkiteAnnotation($bodies, buildkiteBuild());

    $annotation->planned(ShardedPlan::planned(2));
    $annotation->rechecked(Rechecks::mixed());
    $annotation->writeAs('Held', CommentWrite::sticky());

    expect([...$bodies])->toBe([
        PlannedMarkdown::comment(ShardedPlan::planned(2), BUILDKITE_BUILD),
        RecheckedMarkdown::comment(Rechecks::mixed(), BUILDKITE_BUILD, CommentShows::unfolded()),
        'Held',
    ])
        ->and($annotation->plannedLater(ShardedPlan::planned(2), Delivery::none()))->toEqual(Delivery::none())
        ->and($annotation->recheckedLater(Rechecks::mixed(), Delivery::none()))->toEqual(Delivery::none());
});
