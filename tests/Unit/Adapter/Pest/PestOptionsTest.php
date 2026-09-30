<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Adapter\Pest\PestOptions;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Extension\Options;

it('is unpatched, with the tests under tests, where the config says nothing', function (): void {
    $read = PestOptions::read(Options::none());

    expect($read instanceof PestOptions ? [$read->patching(), $read->tests()] : $read)
        ->toEqual([Patching::off(), Paths::of(Path::of('tests'))]);
});

it('is patched with the canary group and the test directories the config names', function (): void {
    $read = PestOptions::read(Options::ofJson('{"patch": true, "canary": "smoke", "tests": ["tests", "spec/"]}'));

    expect($read instanceof PestOptions ? [$read->patching(), $read->tests()] : $read)
        ->toEqual([Patching::on(Group::named('smoke')), Paths::of(Path::of('tests'), Path::of('spec'))]);
});

it('opens a patched shard on mutation-canary unless the config names another group', function (): void {
    $read = PestOptions::read(Options::ofJson('{"patch": true}'));

    expect($read instanceof PestOptions ? $read->patching() : $read)
        ->toEqual(Patching::on(Group::named('mutation-canary')));
});

it('stays unpatched when the config says so', function (): void {
    $read = PestOptions::read(Options::ofJson('{"patch": false, "canary": "smoke"}'));

    expect($read instanceof PestOptions ? $read->patching() : $read)->toEqual(Patching::off());
});

it('refuses each option written as something else', function (): void {
    expect(PestOptions::read(Options::ofJson('{"patch": "yes"}')))->toEqual(Invalid::because(
        Problem::at('patch', 'Whether the project applies pest:patch is true or false.'),
    ))->and(PestOptions::read(Options::ofJson('{"canary": 7}')))->toEqual(Invalid::because(
        Problem::at('canary', 'The canary is the name of a group, as text.'),
    ))->and(PestOptions::read(Options::ofJson('{"tests": "tests"}')))->toEqual(Invalid::because(
        Problem::at('tests', 'The tests are a list of directories, each as text.'),
    ))->and(PestOptions::read(Options::ofJson('{"tests": [3]}')))->toEqual(Invalid::because(
        Problem::at('tests', 'The tests are a list of directories, each as text.'),
    ));
});
