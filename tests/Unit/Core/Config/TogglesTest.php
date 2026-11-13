<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Config\Controls as ControlsBuilder;
use NightWorksIO\MutationGate\Config\Gate;
use NightWorksIO\MutationGate\Config\Pest as PestBuilder;
use NightWorksIO\MutationGate\Config\Setting;
use NightWorksIO\MutationGate\Config\StaticCheck as StaticCheckBuilder;
use NightWorksIO\MutationGate\Config\Suggest as SuggestBuilder;
use NightWorksIO\MutationGate\Config\Timeouts as TimeoutsBuilder;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\Config\Choice;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\Config\Toggle;
use NightWorksIO\MutationGate\Core\Config\Toggles;
use NightWorksIO\MutationGate\Tests\Support\Configs;

$toggles = [
    'controls.kills' => [Toggle::ControlsKills, ControlsBuilder::killsUncontrolled(), ControlsBuilder::killsControlled()],
    'controls.memory' => [Toggle::ControlsMemory, ControlsBuilder::memoryUncontrolled(), ControlsBuilder::memoryControlled()],
    'pest.trials' => [Toggle::PestTrials, PestBuilder::withoutTrials(), PestBuilder::trying()],
    'pest.valueReaders' => [Toggle::PestValueReaders, PestBuilder::withoutValueReaders(), PestBuilder::readingValues()],
    'pest.narrow' => [Toggle::PestNarrow, PestBuilder::unnarrowed(), PestBuilder::narrowing()],
    'staticCheck.after' => [Toggle::StaticCheckAfter, StaticCheckBuilder::survivorsUnchecked(), StaticCheckBuilder::checkingSurvivors()],
    'timeouts.parts' => [Toggle::TimeoutsParts, TimeoutsBuilder::neverInParts(), TimeoutsBuilder::inParts()],
    'suggest.looseValues' => [Toggle::SuggestLooseValues, SuggestBuilder::looseValuesSilent(), SuggestBuilder::looseValuesNoted()],
    'pest.shardScan' => [Toggle::PestShardScan, PestBuilder::scanningEachChunk(), PestBuilder::scanningOnceAShard()],
    'pest.longestFirst' => [Toggle::PestLongestFirst, PestBuilder::inTheirOrder(), PestBuilder::longestFirst()],
    'pest.remember' => [Toggle::PestRemember, PestBuilder::forgetting(), PestBuilder::remembering()],
    'pest.overlapSteps' => [Toggle::PestOverlapSteps, PestBuilder::stepByStep(), PestBuilder::overlappingSteps()],
    'pest.pcovOff' => [Toggle::PestPcovOff, PestBuilder::pcovOn(), PestBuilder::pcovOff()],
    'pest.xdebugOff' => [Toggle::PestXdebugOff, PestBuilder::xdebugAsConfigured(), PestBuilder::xdebugOff()],
    'staticCheck.turbo' => [Toggle::StaticCheckTurbo, StaticCheckBuilder::withoutTurbo(), StaticCheckBuilder::turbo()],
];

it('turns every step on by default, and a step off where its key says, a later layer\'s over an earlier one\'s', function (): void {
    foreach (Toggle::cases() as $toggle) {
        [$section, $key] = explode('.', $toggle->value);
        $off = Configs::valid([$section => [$key => false]]);
        $on = Configs::valid([$section => [$key => true]]);

        expect(Layer::standard()->toggles()->on($toggle))->toBeTrue($toggle->value)
            ->and($off->toggles()->on($toggle))->toBeFalse($toggle->value)
            ->and($on->toggles()->on($toggle))->toBeTrue($toggle->value)
            ->and($off->over($on)->toggles()->on($toggle))->toBeTrue($toggle->value)
            ->and($on->over($off)->toggles()->on($toggle))->toBeFalse($toggle->value)
            ->and($off->over(Layer::of())->toggles()->on($toggle))->toBeFalse($toggle->value)
            ->and(Configs::problems(Configs::layer([$section => [$key => 'no']]))[0] ?? '')->toStartWith(sprintf('%s: ', $toggle->value));
    }
});

it('writes a toggle where a layer sets it, and the builder calls for it, as the builder writes it', function (Toggle $toggle, Setting $off, Setting $on): void {
    [$section, $key] = explode('.', $toggle->value);
    $layer = Layer::of(Toggles::of($toggle, on: false));

    expect($layer->written(ProjectRoot::origin())->line())->toBe(sprintf('{"%s":{"%s":false}}', $section, $key))
        ->and($layer->php(ProjectRoot::origin())->code())->toContain($toggle->php(on: false))
        ->and(Layer::of(Toggles::of($toggle, on: true))->php(ProjectRoot::origin())->code())->toContain($toggle->php(on: true))
        ->and(Configs::written(Gate::configure()->with($off)))->toBe([$section => [$key => false]])
        ->and(Configs::written(Gate::configure()->with($on)))->toBe([$section => [$key => true]]);
})->with($toggles);

it('hands Pest the toggles it reads among its options, and any other runner none', function (): void {
    $pest = Toggles::of(Toggle::PestTrials, on: false)->beneath(Choice::of(BuiltinRunner::Pest->value, Options::none()));
    $other = Toggles::of(Toggle::PestTrials, on: false)->beneath(Choice::of(BuiltinRunner::PhpUnit->value, Options::none()));

    expect($pest->options()->written()->line())->toBe('{"trials":false,"valueReaders":true,"narrow":true,"shardScan":true,"longestFirst":true,"remember":true,"overlapSteps":true,"pcovOff":true,"xdebugOff":true}')
        ->and($other->options()->written()->line())->toBe('{}');
});

it('loosens with controls.kills off, and with pest.trials off only where uncovered mutants are excluded', function (): void {
    expect(Toggle::ControlsKills->loosens(on: false, uncoveredExcluded: false))->toBeTrue()
        ->and(Toggle::PestTrials->loosens(on: false, uncoveredExcluded: false))->toBeFalse()
        ->and(Toggle::PestTrials->loosens(on: false, uncoveredExcluded: true))->toBeTrue()
        ->and(Toggle::ControlsMemory->loosens(on: false, uncoveredExcluded: true))->toBeFalse()
        ->and(Toggle::ControlsKills->loosens(on: true, uncoveredExcluded: true))->toBeFalse();
});

it('keys none of the speed-ups that change no output, and every check that vouches for a result', function (): void {
    $speedUps = array_values(array_filter(Toggle::cases(), static fn(Toggle $toggle): bool => $toggle->speedsUp()));

    expect(array_map(static fn(Toggle $toggle): string => $toggle->value, $speedUps))
        ->toBe(['pest.shardScan', 'pest.longestFirst', 'pest.remember', 'pest.overlapSteps', 'pest.pcovOff', 'pest.xdebugOff', 'staticCheck.turbo'])
        ->and(array_filter($speedUps, static fn(Toggle $toggle): bool => $toggle->effect()->isKeyed()))->toBe([])
        ->and(Toggle::ControlsKills->effect()->isKeyed())->toBeTrue();
});
