<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Config\Controls as ControlsBuilder;
use NightWorksIO\MutationGate\Config\Gate;
use NightWorksIO\MutationGate\Config\Pest as PestBuilder;
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
    'controls.kills' => [Toggle::ControlsKills, static fn(): ControlsBuilder => ControlsBuilder::killsUncontrolled(), static fn(): ControlsBuilder => ControlsBuilder::killsControlled()],
    'controls.memory' => [Toggle::ControlsMemory, static fn(): ControlsBuilder => ControlsBuilder::memoryUncontrolled(), static fn(): ControlsBuilder => ControlsBuilder::memoryControlled()],
    'pest.trials' => [Toggle::PestTrials, static fn(): PestBuilder => PestBuilder::withoutTrials(), static fn(): PestBuilder => PestBuilder::trying()],
    'pest.valueReaders' => [Toggle::PestValueReaders, static fn(): PestBuilder => PestBuilder::withoutValueReaders(), static fn(): PestBuilder => PestBuilder::readingValues()],
    'pest.narrow' => [Toggle::PestNarrow, static fn(): PestBuilder => PestBuilder::unnarrowed(), static fn(): PestBuilder => PestBuilder::narrowing()],
    'staticCheck.after' => [Toggle::StaticCheckAfter, static fn(): StaticCheckBuilder => StaticCheckBuilder::survivorsUnchecked(), static fn(): StaticCheckBuilder => StaticCheckBuilder::checkingSurvivors()],
    'timeouts.parts' => [Toggle::TimeoutsParts, static fn(): TimeoutsBuilder => TimeoutsBuilder::neverInParts(), static fn(): TimeoutsBuilder => TimeoutsBuilder::inParts()],
    'suggest.looseValues' => [Toggle::SuggestLooseValues, static fn(): SuggestBuilder => SuggestBuilder::looseValuesSilent(), static fn(): SuggestBuilder => SuggestBuilder::looseValuesNoted()],
    'pest.shardScan' => [Toggle::PestShardScan, static fn(): PestBuilder => PestBuilder::scanningEachChunk(), static fn(): PestBuilder => PestBuilder::scanningOnceAShard()],
    'pest.longestFirst' => [Toggle::PestLongestFirst, static fn(): PestBuilder => PestBuilder::inTheirOrder(), static fn(): PestBuilder => PestBuilder::longestFirst()],
    'pest.remember' => [Toggle::PestRemember, static fn(): PestBuilder => PestBuilder::forgetting(), static fn(): PestBuilder => PestBuilder::remembering()],
    'pest.overlapSteps' => [Toggle::PestOverlapSteps, static fn(): PestBuilder => PestBuilder::stepByStep(), static fn(): PestBuilder => PestBuilder::overlappingSteps()],
    'pest.pcovOff' => [Toggle::PestPcovOff, static fn(): PestBuilder => PestBuilder::pcovOn(), static fn(): PestBuilder => PestBuilder::pcovOff()],
    'pest.xdebugOff' => [Toggle::PestXdebugOff, static fn(): PestBuilder => PestBuilder::xdebugAsConfigured(), static fn(): PestBuilder => PestBuilder::xdebugOff()],
    'staticCheck.turbo' => [Toggle::StaticCheckTurbo, static fn(): StaticCheckBuilder => StaticCheckBuilder::withoutTurbo(), static fn(): StaticCheckBuilder => StaticCheckBuilder::turbo()],
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

it('writes a toggle where a layer sets it, and the builder calls for it, as the builder writes it', function (Toggle $toggle, Closure $off, Closure $on): void {
    [$section, $key] = explode('.', $toggle->value);
    $layer = Layer::of(Toggles::of($toggle, false));

    expect($layer->written(ProjectRoot::origin())->line())->toBe(sprintf('{"%s":{"%s":false}}', $section, $key))
        ->and($layer->php(ProjectRoot::origin())->code())->toContain($toggle->php(false))
        ->and(Layer::of(Toggles::of($toggle, true))->php(ProjectRoot::origin())->code())->toContain($toggle->php(true))
        ->and(Configs::written(Gate::configure()->with($off())))->toBe([$section => [$key => false]])
        ->and(Configs::written(Gate::configure()->with($on())))->toBe([$section => [$key => true]]);
})->with($toggles);

it('hands Pest the toggles it reads among its options, and any other runner none', function (): void {
    $pest = Toggles::of(Toggle::PestTrials, false)->beneath(Choice::of(BuiltinRunner::Pest->value, Options::none()));
    $other = Toggles::of(Toggle::PestTrials, false)->beneath(Choice::of(BuiltinRunner::PhpUnit->value, Options::none()));

    expect($pest->options()->written()->line())->toBe('{"trials":false,"valueReaders":true,"narrow":true,"shardScan":true,"longestFirst":true,"remember":true,"overlapSteps":true,"pcovOff":true,"xdebugOff":true}')
        ->and($other->options()->written()->line())->toBe('{}');
});

it('loosens with controls.kills off, and with pest.trials off only where uncovered mutants are excluded', function (): void {
    expect(Toggles::of(Toggle::ControlsKills, false)->loose(false))->toBe([Toggle::ControlsKills])
        ->and(Toggles::of(Toggle::PestTrials, false)->loose(false))->toBe([])
        ->and(Toggles::of(Toggle::PestTrials, false)->loose(true))->toBe([Toggle::PestTrials])
        ->and(Toggles::of(Toggle::ControlsMemory, false)->loose(true))->toBe([])
        ->and(Toggles::standard()->loose(true))->toBe([]);
});

it('keys none of the speed-ups that change no output, and every check that vouches for a result', function (): void {
    $speedUps = array_values(array_filter(Toggle::cases(), static fn(Toggle $toggle): bool => $toggle->speedsUp()));

    expect(array_map(static fn(Toggle $toggle): string => $toggle->value, $speedUps))
        ->toBe(['pest.shardScan', 'pest.longestFirst', 'pest.remember', 'pest.overlapSteps', 'pest.pcovOff', 'pest.xdebugOff', 'staticCheck.turbo'])
        ->and(array_filter($speedUps, static fn(Toggle $toggle): bool => $toggle->effect()->isKeyed()))->toBe([])
        ->and(Toggle::ControlsKills->effect()->isKeyed())->toBeTrue();
});
