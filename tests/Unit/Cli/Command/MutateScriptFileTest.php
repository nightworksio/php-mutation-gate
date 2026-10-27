<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Command\MutateScriptFile;
use NightWorksIO\MutationGate\Cli\Command\Output;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

const MUTATE_SCRIPT_ADD = <<<'SAID'
    Add this to the scripts in composer.json, then run composer mutate:
      "mutate": ["Composer\\Config::disableProcessTimeout","mutation-gate"]
    SAID;

it('writes the script into a composer.json laid out as Composer writes it, and says how to run it', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'composer.json', "{\n    \"name\": \"acme/app\"\n}\n");

    expect(MutateScriptFile::made($project, Output::Written))->toBe('Wrote composer.json. Run it with composer mutate.')
        ->and((string) file_get_contents(sprintf('%s/composer.json', $project)))->toContain('"mutate": [');
});

it('says what to add where it writes nothing: printing, no composer.json, or one laid out otherwise', function (): void {
    $printed = Scratch::directory();
    Scratch::write($printed, 'composer.json', "{\n    \"name\": \"acme/app\"\n}\n");
    $otherwise = Scratch::directory();
    Scratch::write($otherwise, 'composer.json', "{\"name\": \"acme/app\"}\n");

    expect([
        MutateScriptFile::made($printed, Output::Printed),
        MutateScriptFile::made(Scratch::directory(), Output::Written),
        MutateScriptFile::made($otherwise, Output::Written),
    ])->toBe([
        MUTATE_SCRIPT_ADD,
        MUTATE_SCRIPT_ADD,
        sprintf('composer.json is not laid out as Composer writes it, so init leaves it as it is. %s', MUTATE_SCRIPT_ADD),
    ])->and((string) file_get_contents(sprintf('%s/composer.json', $printed)))->toBe("{\n    \"name\": \"acme/app\"\n}\n")
        ->and((string) file_get_contents(sprintf('%s/composer.json', $otherwise)))->toBe("{\"name\": \"acme/app\"}\n");
});

it('leaves a composer.json that has a mutate script as it is', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'composer.json', "{\n    \"scripts\": {\n        \"mutate\": \"mutation-gate\"\n    }\n}\n");

    expect(MutateScriptFile::made($project, Output::Written))
        ->toBe('composer.json already has a mutate script, so init leaves it as it is.');
});

it('says why where composer.json cannot be read', function (): void {
    $project = Scratch::directory();
    mkdir(sprintf('%s/composer.json', $project));

    expect(MutateScriptFile::made($project, Output::Written))->toBeInstanceOf(CannotJudge::class);
});
