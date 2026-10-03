<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\Mutators;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Format\Json;

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
