<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\BuiltinAnalyser;
use NightWorksIO\MutationGate\Core\Config\BuiltinCiPlan;
use NightWorksIO\MutationGate\Core\Config\BuiltinReporter;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\Config\BuiltinStore;
use NightWorksIO\MutationGate\Core\Config\BuiltinTreeSource;
use NightWorksIO\MutationGate\Core\Config\Definition\Builtins;
use NightWorksIO\MutationGate\Core\Config\Key;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\PathOrigin;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;

it('checks the options of every built-in runner, tree source, store, CI plan, reporter and analyser', function (): void {
    $origin = ProjectRoot::origin();
    $has = static fn(Builtins $builtins, BackedEnum ...$cases): bool => array_all(
        $cases,
        static fn(BackedEnum $case): bool => $builtins->has(sprintf('%s', $case->value)),
    );

    expect($has(Builtins::runners($origin), ...BuiltinRunner::cases()))->toBeTrue()
        ->and($has(Builtins::treeSources($origin), ...BuiltinTreeSource::cases()))->toBeTrue()
        ->and($has(Builtins::stores($origin), ...BuiltinStore::cases()))->toBeTrue()
        ->and($has(Builtins::ciPlans($origin), ...BuiltinCiPlan::cases()))->toBeTrue()
        ->and($has(Builtins::reporters($origin), ...BuiltinReporter::cases()))->toBeTrue()
        ->and($has(Builtins::staticCheckers($origin), ...BuiltinAnalyser::cases()))->toBeTrue();
});

it('hands a built-in adapter options that reach outside the project only where its layer may', function (): void {
    $choose = static fn(PathOrigin $origin): Options => Builtins::reporters($origin)
        ->choose(BuiltinReporter::Json->value, Node::config('{}')->field('with'))
        ->must()
        ->options()
        ->overPath(Key::of('path'), Path::of('/tmp/mutation.json'));

    expect($choose(ProjectRoot::commandLine())->path(Key::of('path')))->toEqual(Path::of('/tmp/mutation.json'))
        ->and($choose(ProjectRoot::origin())->path(Key::of('path')))
        ->toEqual(Problem::at('path', 'expected a path inside the project, got "/tmp/mutation.json"'));
});
