<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\SchemaSwitch;
use NightWorksIO\MutationGate\Adapter\Pest\Split\ChildLimit;
use NightWorksIO\MutationGate\Adapter\Pest\Split\ChildPlaces;
use NightWorksIO\MutationGate\Adapter\Pest\Split\Kills;
use NightWorksIO\MutationGate\Adapter\Pest\Split\SiteGuard;
use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamPlan;

$holds = ['holds:src/Adapter/Pest/Split/SiteGuard.php'];

afterEach(function (): void {
    SchemaSwitch::unsplit();
});

it('holds every site of its plan undecided, and decides each once its mutant is killed in a test the parent passed', function (): void {
    $plan = new StreamPlan('', ['f1' => [1 => ['m1', '', []], 2 => ['m2', '', []]], 'f2' => [4 => ['m4', '', []]]], [], [], ChildPlaces::fixed(1), new ChildLimit(0.0, 0.0, 0.0));
    $guard = SiteGuard::of($plan);
    $planned = SchemaSwitch::$live;
    $kills = new Kills();
    $kills->failedIn([['m2', 't'], ['m4', 'u']]);
    $kills->ended('t', passed: true);
    $kills->ended('u', passed: false);
    $guard->killed(...$kills->taken());

    expect($planned)->toBe(['f1' => [1 => true, 2 => true], 'f2' => [4 => true]])
        ->and(SchemaSwitch::$live)->toBe(['f1' => [1 => true], 'f2' => [4 => true]])
        ->and($kills->taken())->toBe([]);
})->group(...$holds);
