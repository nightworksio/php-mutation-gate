<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpSpec\LaunchOption;

it('sets each option by its name, an equals sign and its value', function (LaunchOption $option): void {
    expect($option->with('a=b'))->toBe(sprintf('%s=a=b', $option->value));
})->with([LaunchOption::Examples, LaunchOption::Group, LaunchOption::Filter, LaunchOption::Spec, LaunchOption::Coverage, LaunchOption::Listing]);

it('names options the launcher takes off before PhpSpec reads its own', function (): void {
    expect(LaunchOption::names())->toBe([
        '--mutation-gate-examples',
        '--mutation-gate-group',
        '--mutation-gate-filter',
        '--mutation-gate-spec',
        '--mutation-gate-coverage',
        '--mutation-gate-listing',
    ]);
});
