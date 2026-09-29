<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Extension\Options;

it('holds the options written beside an adapter as JSON', function (): void {
    expect(Options::ofJson('{"channel": "#ci"}')->json())->toBe('{"channel": "#ci"}');
});

it('is an empty object when nothing is written', function (): void {
    expect(Options::none()->json())->toBe('{}');
});
