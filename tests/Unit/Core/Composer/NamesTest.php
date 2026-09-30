<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Composer\Names;

it('holds names in the order they were listed', function (): void {
    $names = Names::of('acme/core', 'php', 'acme/testing');

    expect([...$names])->toBe(['acme/core', 'php', 'acme/testing'])
        ->and(count($names))->toBe(3)
        ->and($names->has('php'))->toBeTrue()
        ->and($names->has('acme/other'))->toBeFalse()
        ->and(count(Names::of()))->toBe(0);
});

it('holds names spread by key as a list', function (): void {
    expect([...Names::of(...['first' => 'acme/core', 'second' => 'php'])])->toBe(['acme/core', 'php']);
});
