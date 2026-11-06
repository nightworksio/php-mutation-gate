<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Command;
use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Adapter\Pest\TailBoot;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\BootSpec;
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

    TailBoot::of($project, Patching::on(Group::named('canary'))->bootingTail(boots: true))->leftFor($request, $command, narrows: false, warm: $root);
    $spec = BootSpec::readIn($root);
    $real = (string) realpath($root);

    expect($spec)->toBeInstanceOf(BootSpec::class)
        ->and($spec instanceof BootSpec ? [$spec->root(), $spec->autoloader(), $spec->script(), $spec->suites()] : [])
        ->toBe([$real, sprintf('%s/vendor/autoload.php', $real), 'vendor/pestphp/pest/bin/pest', ['unit', 'feature']]);
});

it('leaves nothing to boot where pest.bootTail is off, the run is a mutant\'s, the tail starts fresh or the run narrows', function (bool $boots, bool $tail, string $workers, bool $narrows): void {
    $root = Scratch::directory();
    $project = Project::at($root, Paths::none(), Path::of('.mutation-gate'), Path::of('vendor'));
    $pool = Pool::of(ProcessCount::of(2), Workers::from($workers));
    $request = MutationRequest::of(Paths::of(Path::of('src')), WholeSuite::tests())->across($tail ? $pool->tail() : $pool);

    TailBoot::of($project, Patching::on(Group::named('canary'))->bootingTail($boots))
        ->leftFor($request, Command::pest('vendor/bin/pest', Withheld::standard()), $narrows, $root);

    expect(BootSpec::readIn($root))->toBeInstanceOf(NotGiven::class);
})->with([
    'pest.bootTail off' => [false, true, 'fork', false],
    'a mutant\'s run' => [true, false, 'fork', false],
    'the tail starting fresh' => [true, true, 'mutants', false],
    'a narrowed run' => [true, true, 'fork', true],
]);
