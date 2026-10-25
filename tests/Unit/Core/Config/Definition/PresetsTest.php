<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Tests\Support\Configs;

it('refuses a preset written as an object, even one whose empty key names a preset', function (): void {
    expect(Configs::problems(Configs::validated(['runner' => 'pest', 'preset' => ['' => 'laravel']])))
        ->toBe(['preset: expected a preset name, or a list of them, got an object']);
});
