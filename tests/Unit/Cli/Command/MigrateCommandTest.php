<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Command\MigrateCommand;
use NightWorksIO\MutationGate\Cli\Config\MigrationFiles;
use NightWorksIO\MutationGate\Cli\Config\Registered;
use NightWorksIO\MutationGate\Core\Migration\Migration;
use NightWorksIO\MutationGate\Core\Migration\Migrations;
use NightWorksIO\MutationGate\Core\Migration\Rename;
use NightWorksIO\MutationGate\Core\Registry\Origin;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Symfony\Component\Console\Tester\CommandTester;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * `migrate` run in a project, with a release that renames a config key and a baseline key: its exit code, and what
 * it printed.
 *
 * @param  array<string, bool> $input
 * @return array{int, string}
 */
function migrateCommandIn(string $project, array $input = []): array
{
    $extensions = Registered::config(new Extensions(Origin::of('nightworksio/mutation-gate')), static fn(): bool => true);
    $tester = new CommandTester(MigrateCommand::command(
        new MigrationFiles($project, $extensions, static fn(): bool => true),
        Migrations::of(Migration::in('2.0.0', Rename::of('runnr', 'runner'))),
        Migrations::of(Migration::in('2.0.0', Rename::of('floors', 'trees'))),
    ));
    $code = $tester->execute($input);

    return [$code, $tester->getDisplay()];
}

it('says each file is current, and exits 0, where nothing is behind', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'mutation-gate.json', "{\"runner\": \"pest\"}\n");
    Scratch::write($project, 'mutation-gate.baseline.json', "{\"format\": 1, \"trees\": {}}\n");

    expect(migrateCommandIn($project))->toBe([0, "mutation-gate.json is current.\nmutation-gate.baseline.json is current.\n"]);
});

it('shows the diff of each file behind and exits 1 without --write, and writes them and exits 0 with it', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'mutation-gate.json', "{\"runnr\": \"pest\"}\n");
    Scratch::write($project, 'mutation-gate.baseline.json', "{\"format\": 1, \"floors\": {}}\n");
    $shown = migrateCommandIn($project);
    $kept = (string) file_get_contents(sprintf('%s/mutation-gate.json', $project));
    [$code, $said] = migrateCommandIn($project, ['--write' => true]);

    expect($shown)->toBe([1, implode("\n", [
        '--- a/mutation-gate.json',
        '+++ b/mutation-gate.json',
        '@@ -1,1 +1,1 @@',
        '-{"runnr": "pest"}',
        '+{"runner": "pest"}',
        '--- a/mutation-gate.baseline.json',
        '+++ b/mutation-gate.baseline.json',
        '@@ -1,1 +1,1 @@',
        '-{"format": 1, "floors": {}}',
        '+{"format": 1, "trees": {}}',
        '',
    ])])
        ->and($kept)->toBe("{\"runnr\": \"pest\"}\n")
        ->and($code)->toBe(0)
        ->and($said)->toContain("Wrote mutation-gate.json.\n")
        ->and($said)->toContain("Wrote mutation-gate.baseline.json.\n")
        ->and((string) file_get_contents(sprintf('%s/mutation-gate.json', $project)))->toBe("{\"runner\": \"pest\"}\n")
        ->and((string) file_get_contents(sprintf('%s/mutation-gate.baseline.json', $project)))->toBe("{\"format\": 1, \"trees\": {}}\n");
});

it('notes before it writes that a YAML config loses its comments', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'mutation-gate.yaml', "runnr: pest # the runner\n");
    [$code, $said] = migrateCommandIn($project, ['--write' => true]);

    expect($code)->toBe(0)
        ->and($said)->toContain(
            "mutation-gate.yaml is written again from its migrated form, so its comments are not kept.\nWrote mutation-gate.yaml.\n",
        );
});

it('lists each change left for a hand edit and exits 1, with --write too', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'mutation-gate.json', '{"runnr": "pest", "runner": "phpunit"}');

    expect(migrateCommandIn($project, ['--write' => true]))->toBe([
        1,
        "mutation-gate.json, runnr: `runnr` became `runner` in 2.0.0, and migrate cannot make that change here: edit it by hand\n",
    ]);
});

it('exits 2 where a file cannot be migrated, and 0 where the project has no config and no baseline', function (): void {
    $broken = Scratch::directory();
    Scratch::write($broken, 'mutation-gate.json', '[1]');

    expect(migrateCommandIn($broken))->toBe([2, "mutation-gate.json cannot be migrated: The file holds JSON, but not an object.\n"])
        ->and(migrateCommandIn(Scratch::directory()))->toBe([0, '']);
});

it('exits 2 where a file it would write cannot be written', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'mutation-gate.json', '{"runnr": "pest"}');
    chmod(sprintf('%s/mutation-gate.json', $project), 0o444);
    set_error_handler(static fn(): bool => true);
    [$code, $said] = migrateCommandIn($project, ['--write' => true]);
    restore_error_handler();
    chmod(sprintf('%s/mutation-gate.json', $project), 0o644);

    expect($code)->toBe(2)
        ->and($said)->toContain('mutation-gate.json could not be written.');
});
