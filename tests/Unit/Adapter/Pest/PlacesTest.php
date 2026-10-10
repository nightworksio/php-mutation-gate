<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Places;
use NightWorksIO\MutationGate\Core\NotGiven;

it('reaches as far as its last killer stood, in one run', function (): void {
    $places = Places::none()->with(3, str_repeat('a', 64), 7)->with(5, str_repeat('b', 64), 7);

    expect($places->reach())->toBe(5);
});

it('reaches nowhere it can tell where it has no killer, its killers come from two runs, or its last stood nowhere', function (Places $places): void {
    expect($places->reach())->toBeInstanceOf(NotGiven::class);
})->with([
    'no killer' => [fn(): Places => Places::none()],
    'two runs' => [fn(): Places => Places::none()->with(3, str_repeat('a', 64), 7)->with(5, str_repeat('b', 64), 8)],
    'a last killer that stood nowhere' => [fn(): Places => Places::none()->with(3, str_repeat('a', 64), 7)->with(NotGiven::value(), NotGiven::value(), 7)],
]);
