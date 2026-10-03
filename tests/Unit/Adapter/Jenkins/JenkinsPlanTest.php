<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Jenkins\JenkinsPlan;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\CiJob;
use NightWorksIO\MutationGate\Core\Ci\CiMarker;
use NightWorksIO\MutationGate\Core\Ci\PlanListing;
use NightWorksIO\MutationGate\Core\Ci\Publication;
use NightWorksIO\MutationGate\Core\Ci\PullRequestNumber;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Ci;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Decoded;
use NightWorksIO\MutationGate\Tests\Support\LogCommands;
use NightWorksIO\MutationGate\Tests\Support\ShardedPlan;

/** The plan for a pipeline run from the Jenkinsfile, in a build with these variables. */
function jenkinsPlanIn(Variables $variables): JenkinsPlan
{
    return JenkinsPlan::in(CiJob::of($variables, Paths::of(Path::of('Jenkinsfile'))));
}

/** The reason Jenkins gives the plan for naming no default branch. */
function jenkinsUnnamed(): CannotTell
{
    return CannotTell::because('Jenkins does not name the default branch. Set ci.defaultBranch.');
}

it('prints the plan as the generic JSON, for the Jenkinsfile to read', function (): void {
    $plan = JenkinsPlan::fromOptions(Configs::options('{"definition": "Jenkinsfile"}'));

    expect(jenkinsPlanIn(Variables::of([]))->publish(ShardedPlan::of(2)))
        ->toEqual(Publication::printed(PlanListing::of(ShardedPlan::of(2))))
        ->and($plan instanceof JenkinsPlan ? $plan->publish(ShardedPlan::of(1)) : $plan)
        ->toEqual(Publication::printed(PlanListing::of(ShardedPlan::of(1))));
});

it('reads a pull request from its number, and the branch a multibranch build builds otherwise', function (): void {
    $pullRequest = Variables::of(['BRANCH_NAME' => 'PR-31', 'CHANGE_ID' => '31', 'CHANGE_TARGET' => 'main']);
    $push = Variables::of(['BRANCH_NAME' => 'feature/money']);

    expect(jenkinsPlanIn($pullRequest)->runOn())
        ->toEqual(RunOn::pullRequest(PullRequestNumber::parse('31'), jenkinsUnnamed()))
        ->and(jenkinsPlanIn($push)->runOn())->toEqual(RunOn::branch('feature/money', jenkinsUnnamed()));
});

it('gives no scope to a tag, which is no branch the gate writes for', function (): void {
    expect(jenkinsPlanIn(Variables::of(['BRANCH_NAME' => 'v1', 'TAG_NAME' => 'v1']))->runOn())
        ->toEqual(RunOn::detached(jenkinsUnnamed()));
});

it('cannot tell the run of a change whose id is no number, or of a build that names no branch', function (): void {
    expect(jenkinsPlanIn(Variables::of(['BRANCH_NAME' => 'CR-1', 'CHANGE_ID' => 'I8f3a']))->runOn())
        ->toEqual(CannotTell::because('"I8f3a" is not the number of a pull request.'))
        ->and(jenkinsPlanIn(Variables::of(['BUILD_TAG' => 'jenkins-gate-4']))->runOn())->toBeInstanceOf(CannotTell::class);
});

it('is run by the Jenkinsfile the config names', function (): void {
    $definitions = static function (string $options): Paths|Invalid {
        $plan = JenkinsPlan::fromOptions(Configs::options($options));

        return $plan instanceof JenkinsPlan ? $plan->definitions() : $plan;
    };
    $missing = Invalid::because(Problem::at('definition', 'expected the pipeline that runs the gate, as a path'));

    expect($definitions('{"definition": "ci/Jenkinsfile"}'))->toEqual(Paths::of(Path::of('ci/Jenkinsfile')))
        ->and($definitions(Ci::none()->planOptions(Name::of('jenkins'))->written()->line()))
        ->toEqual(Paths::of(Path::of('Jenkinsfile')))
        ->and($definitions('{"definition": 3}'))->toEqual(Invalid::because(Problem::at('definition', 'expected a path, got 3')))
        ->and($definitions('{}'))->toEqual($missing);
});

it('withholds nothing of Jenkins\' own, which binds only the credentials a Jenkinsfile names', function (): void {
    expect(JenkinsPlan::withheld())->toEqual(Withheld::nothing());
});

it('is marked by BUILD_TAG, which Jenkins sets in every build', function (): void {
    expect(JenkinsPlan::marker())->toEqual(CiMarker::setting('BUILD_TAG'));
});

it('prints a plan whose paths hold log commands so a CI\'s log reads none, and every reader reads the paths back', function (): void {
    $printed = jenkinsPlanIn(Variables::of([]))->publish(ShardedPlan::hostile())->text();

    expect(LogCommands::in($printed))->toBe([])
        ->and(Decoded::at($printed, 'shards', 0, 'units'))->toBe([ShardedPlan::HOSTILE]);
});
