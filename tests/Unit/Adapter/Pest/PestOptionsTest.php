<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Adapter\Pest\PestOptions;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Tests\Support\Configs;

it('is unpatched, with the tests under tests, where the config says nothing', function (): void {
    $read = PestOptions::read(Options::none());

    expect($read instanceof PestOptions ? [$read->patching(), $read->tests()] : $read)
        ->toEqual([Patching::off(), Paths::of(Path::of('tests'))]);
});

it('is patched with the canary group and the test directories the config names', function (): void {
    $read = PestOptions::read(Configs::options('{"patch": true, "canary": "smoke", "tests": ["tests", "spec/"]}'));

    expect($read instanceof PestOptions ? [$read->patching(), $read->tests()] : $read)
        ->toEqual([Patching::on(Group::named('smoke')), Paths::of(Path::of('tests'), Path::of('spec'))]);
});

it('opens a patched shard on mutation-canary unless the config names another group', function (): void {
    $read = PestOptions::read(Configs::options('{"patch": true}'));

    expect($read instanceof PestOptions ? $read->patching() : $read)
        ->toEqual(Patching::on(Group::named('mutation-canary')));
});

it('stays unpatched when the config says so', function (): void {
    $read = PestOptions::read(Configs::options('{"patch": false, "canary": "smoke"}'));

    expect($read instanceof PestOptions ? $read->patching() : $read)->toEqual(Patching::off());
});

it('refuses each option written as something else', function (): void {
    expect(PestOptions::read(Configs::options('{"patch": "yes"}')))->toEqual(Invalid::because(
        Problem::at('patch', 'expected true or false, got "yes"'),
    ))->and(PestOptions::read(Configs::options('{"canary": 7}')))->toEqual(Invalid::because(
        Problem::at('canary', 'expected text, got 7'),
    ))->and(PestOptions::read(Configs::options('{"tests": "tests"}')))->toEqual(Invalid::because(
        Problem::at('tests', 'expected a list of paths, got "tests"'),
    ))->and(PestOptions::read(Configs::options('{"tests": [3]}')))->toEqual(Invalid::because(
        Problem::at('tests[0]', 'expected text, got 3'),
    ));
});
