<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Command;
use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\BootSpec;
use NightWorksIO\MutationGate\Adapter\Pest\WorkerBoot;
use NightWorksIO\MutationGate\Core\Config\PestWorkers;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\Pool;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Runner\Workers;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('leaves what the tail\'s workers boot, the suites the run names, where pest.bootTail is on and the run is of the tail, forks it and narrows nothing', function (): void {
    $root = Scratch::directory();
    $project = Project::at($root, Paths::none(), Path::of('.mutation-gate'), Path::of('vendor'));
    $pool = Pool::of(ProcessCount::of(2), Workers::Fork)->tail();
    $request = MutationRequest::of(Paths::of(Path::of('src')), WholeSuite::tests())->across($pool);
    $command = Command::pest('vendor/bin/pest', Withheld::standard(), '--testsuite=unit,feature');

    WorkerBoot::of($project, Patching::on(Group::named('canary'))->working(PestWorkers::of(bootTail: true)))->leftFor($request, $command, narrows: false, warm: $root);
    $spec = BootSpec::readIn($root);
    $real = (string) realpath($root);

    expect($spec)->toBeInstanceOf(BootSpec::class)
        ->and($spec instanceof BootSpec ? [$spec->root(), $spec->autoloader(), $spec->script(), $spec->suites()] : [])
        ->toBe([$real, sprintf('%s/vendor/autoload.php', $real), 'vendor/pestphp/pest/bin/pest', ['unit', 'feature']]);
});

it('leaves what mutants\' workers boot, the suites the run names, where pest.bootMutants is on and the run forks mutants\' own runs, narrowed or not', function (string $workers, bool $narrows): void {
    $root = Scratch::directory();
    $project = Project::at($root, Paths::none(), Path::of('.mutation-gate'), Path::of('vendor'));
    $request = MutationRequest::of(Paths::of(Path::of('src')), WholeSuite::tests())->across(Pool::of(ProcessCount::of(2), Workers::from($workers)));
    $command = Command::pest('vendor/bin/pest', Withheld::standard(), '--testsuite=unit');

    WorkerBoot::of($project, Patching::on(Group::named('canary'))->working(PestWorkers::of(bootTail: false)))->leftFor($request, $command, $narrows, $root);
    $spec = BootSpec::readIn($root);

    expect($spec instanceof BootSpec ? [$spec->isForMutants(), $spec->suites()] : [])->toBe([true, ['unit']]);
})->with([
    'forking every run' => ['fork', false],
    'forking only mutants\' runs' => ['mutants', false],
    'a narrowed run' => ['fork', true],
]);

/** The patching a dataset's closure makes. */
function patchingBy(Closure $made): Patching
{
    $patching = $made();

    return $patching instanceof Patching ? $patching : throw new LogicException('A dataset makes no patching.');
}

it('leaves nothing to boot where its pest setting is off, the tail starts fresh, the tail\'s run narrows, or mutants\' runs start fresh', function (Closure $patching, bool $tail, string $workers, bool $narrows): void {
    $root = Scratch::directory();
    $project = Project::at($root, Paths::none(), Path::of('.mutation-gate'), Path::of('vendor'));
    $pool = Pool::of(ProcessCount::of(2), Workers::from($workers));
    $request = MutationRequest::of(Paths::of(Path::of('src')), WholeSuite::tests())->across($tail ? $pool->tail() : $pool);

    WorkerBoot::of($project, patchingBy($patching))->leftFor($request, Command::pest('vendor/bin/pest', Withheld::standard()), $narrows, $root);

    expect(BootSpec::readIn($root))->toBeInstanceOf(NotGiven::class);
})->with([
    'pest.bootTail off' => [static fn(): Patching => Patching::on(Group::named('canary'))->working(PestWorkers::of(bootTail: false)), true, 'fork', false],
    'the tail starting fresh' => [static fn(): Patching => Patching::on(Group::named('canary'))->working(PestWorkers::of(bootTail: true)), true, 'mutants', false],
    'a narrowed run of the tail' => [static fn(): Patching => Patching::on(Group::named('canary'))->working(PestWorkers::of(bootTail: true)), true, 'fork', true],
    'pest.bootMutants off' => [static fn(): Patching => Patching::on(Group::named('canary'))->working(PestWorkers::of(bootMutants: false)), false, 'fork', false],
    'mutants\' runs where only pest.bootTail is on' => [static fn(): Patching => Patching::on(Group::named('canary'))->working(PestWorkers::of(bootTail: true))->working(PestWorkers::of(bootMutants: false)), false, 'fork', false],
    'mutants\' runs starting fresh' => [static fn(): Patching => Patching::on(Group::named('canary')), false, 'fresh', false],
    'no patch' => [static fn(): Patching => Patching::off(), false, 'fork', false],
]);
