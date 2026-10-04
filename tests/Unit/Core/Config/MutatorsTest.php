<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\Mutators;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Config\PresetSet;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\NotGiven;

it('turns on no set and turns off no mutator where no layer says', function (): void {
    expect([...Mutators::none()->sets()])->toBe([])
        ->and([...Mutators::none()->except()])->toBe([])
        ->and(Mutators::none()->written())->toEqual(Json::object())
        ->and(Mutators::standard()->written()->line())->toBe('{"mutators":{"sets":[],"except":[]}}');
});

it('grows both lists layer by layer, keeping each name once', function (): void {
    $preset = Mutators::of(Listed::of('laravel', 'security'));
    $file = Mutators::of(Listed::of('security', 'acme'), Listed::of('laravel/RemoveAbort'));
    $laid = $preset->over($file)->over(Mutators::of(except: Listed::of('laravel/RemoveAbort', 'acme/RemoveAudit')));

    expect([...$laid->sets()])->toEqual([Name::of('laravel'), Name::of('security'), Name::of('acme')])
        ->and([...$laid->except()])->toBe(['laravel/RemoveAbort', 'acme/RemoveAudit'])
        ->and($file->over(Mutators::none())->written()->line())
        ->toBe('{"mutators":{"sets":["security","acme"],"except":["laravel/RemoveAbort"]}}');
});

it('writes each list it sets as a call on the Mutators builder', function (): void {
    $code = Mutators::of(Listed::of('acme'))->php()->code();

    expect($code)->toContain("Mutators::sets('acme')")
        ->and(str_contains($code, 'Mutators::except'))->toBeFalse();
});

it('offers the sets a preset turns on, until a layer chooses one, and turns on none where it offers none', function (): void {
    $laravel = PresetSet::of(Name::of('laravel'), Name::of('laravel'), 'nightworksio/mutation-gate-laravel');
    $security = PresetSet::of(Name::of('security'), Name::of('laravel'), 'nightworksio/mutation-gate-security');
    $preset = Mutators::offered($laravel, $security);
    $laid = $preset->over(Mutators::of(Listed::of('security', 'acme')));
    $both = Mutators::offered($laravel)->over(Mutators::offered($laravel));

    expect([...$laid->sets()])->toEqual([Name::of('laravel'), Name::of('security'), Name::of('acme')])
        ->and($laid->offering(Name::of('laravel')))->toBe($laravel)
        ->and($laid->offering(Name::of('security')))->toEqual(NotGiven::value())
        ->and($laid->offering(Name::of('acme')))->toEqual(NotGiven::value())
        ->and(Mutators::of(Listed::of('laravel'))->over($preset)->offering(Name::of('laravel')))->toEqual(NotGiven::value())
        ->and($both->offering(Name::of('laravel')))->toBe($laravel)
        ->and([...$both->sets()])->toEqual([Name::of('laravel')])
        ->and(Mutators::offered())->toEqual(Mutators::none())
        ->and($preset->written()->line())->toBe('{"mutators":{"sets":["laravel","security"]}}');
});
