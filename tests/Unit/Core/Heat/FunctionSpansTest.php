<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\Heat\FunctionSpan;
use NightWorksIO\MutationGate\Core\Heat\FunctionSpans;
use NightWorksIO\MutationGate\Core\Php\Nameless;
use NightWorksIO\MutationGate\Tests\Support\Heatmaps;

$named = static fn(FunctionSpans $spans): array => array_map(
    static fn(FunctionSpan $span): array => [$span->name(), $span->first()->number(), $span->last()->number()],
    [...$spans],
);

it('holds each named function and method of a file, from its keyword to its closing brace', function () use ($named): void {
    expect($named(FunctionSpans::in(Contents::of(Heatmaps::MONEY))))->toBe([['Money::add', 4, 7], ['Money::sub', 9, 13], ['helper', 15, 18]])
        ->and($named(FunctionSpans::in(Contents::of("<?php\ninterface A\n{\n    public function a(): void;\n}\n"))))->toBe([]);
});

it('names the function a line is in, a closure\'s line its function\'s, and none outside every function', function (): void {
    $spans = FunctionSpans::in(Contents::of(Heatmaps::MONEY));

    expect($spans->at(Line::of(11)))->toBe('Money::sub')
        ->and($spans->at(Line::of(4)))->toBe('Money::add')
        ->and($spans->at(Line::of(7)))->toBe('Money::add')
        ->and($spans->at(Line::of(8)))->toEqual(Nameless::code());
});

it('names the narrowest function that holds a line, in whichever order they are held, the first of two as narrow', function (): void {
    $outer = FunctionSpan::of('outer', Line::of(1), Line::of(10));
    $inner = FunctionSpan::of('inner', Line::of(3), Line::of(5));
    $twin = FunctionSpan::of('twin', Line::of(3), Line::of(5));

    expect(FunctionSpans::of($outer, $inner)->at(Line::of(4)))->toBe('inner')
        ->and(FunctionSpans::of($inner, $outer)->at(Line::of(4)))->toBe('inner')
        ->and(FunctionSpans::of($inner, $outer)->at(Line::of(2)))->toBe('outer')
        ->and(FunctionSpans::of($inner, $twin)->at(Line::of(4)))->toBe('inner')
        ->and($inner->width())->toBe(2);
});

it('keeps only the functions that hold one of some lines', function () use ($named): void {
    $spans = FunctionSpans::in(Contents::of(Heatmaps::MONEY));

    expect($named($spans->holding(Line::of(6), Line::of(17))))->toBe([['Money::add', 4, 7], ['helper', 15, 18]])
        ->and($named($spans->holding()))->toBe([])
        ->and($named(FunctionSpans::none()))->toBe([]);
});
