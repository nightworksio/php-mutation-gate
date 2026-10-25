<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Command\CiDefinition;
use NightWorksIO\MutationGate\Cli\Command\CiRequest;
use NightWorksIO\MutationGate\Cli\Command\Output;
use NightWorksIO\MutationGate\Cli\Command\PreparedCi;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\GatePin;
use NightWorksIO\MutationGate\Core\Ci\GitHubWorkflow;
use NightWorksIO\MutationGate\Core\Config\BuiltinCiPlan;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Registry\Origin;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Port\TreeSource;
use NightWorksIO\MutationGate\Tests\Fakes\TreeSourceFake;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

$holds = [
    'holds:src/Cli/Command/CiDefinition.php',
];

afterEach(function (): void {
    Scratch::sweep();
});

it('says a template the package is missing is to be reinstalled, and why one it cannot read cannot be', function (
    string $templates,
    string $why,
): void {
    $project = Scratch::directory();
    Scratch::write($project, 'templates/circleci/config.yml/unreadable', '');
    $definition = new CiDefinition(
        $project,
        new Extensions(Origin::of('acme/gate')),
        GatePin::unknown(),
        Directory::at(sprintf('%s/%s', $project, $templates)),
    );
    $settings = Configs::settings(['runner' => 'pest']);
    $prepared = $definition->prepared(
        CiRequest::of(BuiltinCiPlan::CircleCi, NotGiven::value(), Output::Written),
        $settings,
        kept: false,
    );
    $made = $prepared instanceof PreparedCi ? $definition->made($prepared, $settings->ci()) : $prepared;

    expect($made instanceof CannotJudge ? $made->why() : $made)->toBe(sprintf($why, $project));
})->with([
    'missing' => ['nowhere', 'The template circleci/config.yml is missing from the package. Reinstall it.'],
    'a directory' => ['templates', '%s/templates/circleci/config.yml could not be read.'],
])->group(...$holds);

it('reads the trees for an estimate only for a GitHub definition whose workflow is not asked for', function (
    BuiltinCiPlan $plan,
    GitHubWorkflow|NotGiven $asked,
    int $reads,
): void {
    $read = new ArrayObject();
    $extensions = new Extensions(Origin::of('acme/gate'))->withTreeSource(
        Name::of('phpunit'),
        static function () use ($read): TreeSource {
            $read->append('read');

            return TreeSourceFake::ofTheFixture();
        },
    );
    $definition = CiDefinition::packaged(Scratch::directory(), $extensions, GatePin::unknown());

    $definition->prepared(CiRequest::of($plan, $asked, Output::Printed), Configs::settings(['runner' => 'pest']), kept: false);

    expect($read)->toHaveCount($reads);
})->with([
    'GitHub, its workflow left to the estimate' => [BuiltinCiPlan::GitHub, fn(): NotGiven => NotGiven::value(), 1],
    'GitHub, its workflow asked for' => [BuiltinCiPlan::GitHub, GitHubWorkflow::Sharded, 0],
    'CircleCI' => [BuiltinCiPlan::CircleCi, fn(): NotGiven => NotGiven::value(), 0],
])->group(...$holds);
