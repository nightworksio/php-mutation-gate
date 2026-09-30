<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Definition\MisreadSetting;
use NightWorksIO\MutationGate\Core\Config\Definition\Reading;
use NightWorksIO\MutationGate\Core\Config\Definition\Text;
use NightWorksIO\MutationGate\Core\Format\Node;

it('refuses to hand on a value it does not hold', function (): void {
    expect(static fn(): string => Text::of('a branch')->read(Node::config('{}')->field('branch'))->must())
        ->toThrow(
            MisreadSetting::class,
            'The reading holds no value for a setting that must be written, though it has no problem.',
        );
});

it('hands on the value it holds', function (): void {
    expect(Reading::of('trunk')->must())->toBe('trunk');
});
