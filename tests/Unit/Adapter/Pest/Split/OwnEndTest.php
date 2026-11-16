<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\OwnEnd;

it('runs what a process asked for, in the order asked, before it signals itself', function (): void {
    $ran = [];
    OwnEnd::first(static function () use (&$ran): void {
        $ran[] = 'first';
    });
    OwnEnd::first(static function () use (&$ran): void {
        $ran[] = 'second';
    });

    OwnEnd::by(0);

    expect(array_slice($ran, -2))->toBe(['first', 'second']);
});
