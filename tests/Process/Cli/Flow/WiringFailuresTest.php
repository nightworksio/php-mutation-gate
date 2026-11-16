<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Flow\Adapters;
use NightWorksIO\MutationGate\Cli\Flow\Wiring;
use NightWorksIO\MutationGate\Config\Ci;
use NightWorksIO\MutationGate\Config\CostModel;
use NightWorksIO\MutationGate\Config\Gate;
use NightWorksIO\MutationGate\Config\Proofs;
use NightWorksIO\MutationGate\Config\Runner;
use NightWorksIO\MutationGate\Config\Setting;
use NightWorksIO\MutationGate\Config\Source as TreeSourceSetting;
use NightWorksIO\MutationGate\Config\StaticCheck;
use NightWorksIO\MutationGate\Config\VersionControl;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserSettings;
use NightWorksIO\MutationGate\Core\Analysis\Findings;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Tests\Fakes\ChangeSourceFake;
use NightWorksIO\MutationGate\Tests\Fakes\StaticCheckerFake;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Wirings;

$holds = ['holds:src/Cli/Flow/Wiring.php', 'holds:src/Cli/Flow/WiredStore.php', 'holds:src/Cli/Config/Chosen.php'];

afterEach(function (): void {
    Scratch::sweep();
});

it('says which key names a cost model or a version control nothing registers', function (): void {
    $wired = static fn(Setting $setting): Adapters|Invalid|CannotJudge => new Wiring(Wirings::registry(), Variables::of([]), Wirings::detected())
        ->adapters(Flows::settings($setting), Directory::at(Flows::project()));

    expect($wired(CostModel::uses('nothing')))->toBeInstanceOf(CannotJudge::class)
        ->and($wired(VersionControl::uses('nothing')))->toBeInstanceOf(CannotJudge::class);
})->group(...$holds);

it('cannot wire a runner the registry does not have, or one that refuses its options', function (): void {
    $picky = Wirings::registry()->withRunner(
        Name::of('picky'),
        static fn(): Invalid => Invalid::because(Problem::at('level', 'The level is a number.')),
    );
    $wired = static fn(Extensions $registry, string $runner): Adapters|Invalid|CannotJudge => new Wiring(
        $registry,
        Variables::of([]),
        Wirings::detected(),
    )->adapters(Flows::settings(Runner::uses($runner)), Directory::at(Scratch::directory()));

    expect($wired(Wirings::registry(), 'nowhere'))->toEqual(CannotJudge::because('No runner is registered as "nowhere".'))
        ->and($wired($picky, 'picky'))->toEqual(
            Invalid::because(Problem::at('runner.with.level', 'The level is a number.')),
        );
})->group(...$holds);

it('cannot wire an analyser the registry does not have', function (): void {
    $adapters = new Wiring(Wirings::registry(), Variables::of([]), Wirings::detected())
        ->adapters(Flows::settings(StaticCheck::uses('nowhere')), Directory::at(Flows::project()));

    expect($adapters)->toEqual(CannotJudge::because('No static checker is registered as "nowhere".'));
})->group(...$holds);

it('cannot wire a tree source, a CI plan, a proof store or a repository nothing registers, nor an analyser that refuses its options', function (): void {
    $wired = static fn(Settings $settings, ?Extensions $registry = null): Adapters|Invalid|CannotJudge => new Wiring($registry ?? Wirings::registry(), Variables::of([]), Wirings::detected())
        ->adapters($settings, Directory::at(Flows::project()));
    $changesAlone = Wirings::registry()->withChangeSource(Name::of('hg'), static fn(): ChangeSourceFake => Flows::checkout());
    $refusing = Wirings::registry()->withStaticChecker(
        Name::of('picky'),
        static fn(): Invalid => Invalid::because(Problem::at('level', 'The level is a number.')),
    );

    expect($wired(Configs::built(Gate::configure()->runner(Runner::uses('fake'))->treeSource(TreeSourceSetting::uses('nowhere')))))
        ->toEqual(CannotJudge::because('No tree source is registered as "nowhere".'))
        ->and($wired(Flows::settings(Ci::uses('nowhere'))))->toEqual(CannotJudge::because('No CI plan is registered as "nowhere".'))
        ->and($wired(Flows::settings(Proofs::uses('nowhere'))))->toEqual(CannotJudge::because('No proof store is registered as "nowhere".'))
        ->and($wired(Flows::settings(VersionControl::uses('hg')), $changesAlone))->toEqual(CannotJudge::because('No repository is registered as "hg".'))
        ->and($wired(Flows::settings(StaticCheck::uses('picky')), $refusing))->toEqual(Invalid::because(Problem::at('staticCheck.tool.with.level', 'The level is a number.')));
})->group(...$holds);

it('names the analyser as it says it is where it cannot say who it is, whatever configuration it resolves', function (): void {
    $settings = AnalyserSettings::resolved('{"paths": []}', Flows::project());
    $unnamed = CannotJudge::because('The analyser could not say its version.');
    $registry = Wirings::registry()->withStaticChecker(
        Name::of('fake'),
        static fn(): StaticCheckerFake => new StaticCheckerFake($unnamed, Findings::none(), [], $settings),
    );
    $adapters = new Wiring($registry, Variables::of([]), Wirings::detected())
        ->adapters(Flows::settings(StaticCheck::uses('fake')), Directory::at(Flows::project()));

    expect($settings)->toBeInstanceOf(AnalyserSettings::class)
        ->and($adapters instanceof Adapters ? $adapters->analyser : $adapters)->toEqual($unnamed);
})->group(...$holds);
