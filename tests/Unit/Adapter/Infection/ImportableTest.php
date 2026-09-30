<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\Importable;
use NightWorksIO\MutationGate\Adapter\Infection\Project;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Doctor\InfectionConfig;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Import\Import;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Imports;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('reads whether an Infection config sets its own floor, and whether it ignores mutants itself', function (string $text, bool $minMsi, bool $ignores): void {
    expect(Importable::in('infection.json5', $text))->toEqual(InfectionConfig::in('infection.json5', minMsi: $minMsi, ignores: $ignores));
})->with([
    'a minMsi' => ['{minMsi: 80}', true, false],
    'a minCoveredMsi, with a comment' => ["{\n  // the covered floor\n  minCoveredMsi: 90,\n}", true, false],
    'a mutator\'s ignore' => ['{"mutators": {"Plus": {"ignore": ["App\\\\Money::add"]}}}', false, true],
    'a global ignore' => ['{"mutators": {"global-ignore": ["App\\\\Legacy"]}}', false, true],
    'neither' => ['{"source": {"directories": ["src"]}, "mutators": {"@default": true}}', false, false],
]);

it('cannot judge text that is not a config Infection could read', function (): void {
    expect(Importable::in('infection.json5', '{minMsi: '))->toBeInstanceOf(CannotJudge::class);
});

it('reads a config to import, or why the gate cannot take over from it', function (): void {
    $importable = Importable::read('infection.json', '{"source": {"directories": ["src", "lib/", 1]}}');

    expect($importable instanceof Importable ? [$importable->file(), $importable->directories()] : [])
        ->toEqual(['infection.json', Paths::of(Path::of('src'), Path::of('lib'))])
        ->and(Importable::read('infection.json5', '{minMsi: '))->toBeInstanceOf(CannotJudge::class)
        ->and(Importable::read('infection.json5', '{"testFramework": "phpspec"}'))
        ->toEqual(CannotJudge::because('infection.json5 sets testFramework to phpspec. The gate runs Infection with PHPUnit alone, so it cannot judge that suite.'));
});

it('seeds the gate\'s config with Infection as the runner, and says what became of every key', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'composer.json', '{"autoload": {"psr-4": {"Acme\\\\": "src/"}}}');
    Scratch::write($root, 'src/Money.php', '<?php');
    $importable = Importable::read('infection.json5', <<<'JSON5'
        {
            source: {directories: ["src"]},
            minMsi: 75,
            timeout: 5,
            mutators: {Plus: {ignore: ["Acme\\Money"]}},
            logs: {gitlab: "cq.json"},
            threads: 2,
        }
        JSON5);
    $import = $importable instanceof Importable
        ? $importable->imported(Project::at(Root::of($root), Paths::none(), Path::of('.gate')), new Absent(), new DateTimeImmutable(Configs::NOW))
        : Import::none();

    expect(Imports::written($import))->toBe(
        '{"runner":"infection","trees":[{"path":"src","floor":75}],"timeouts":{"seconds":5},"ignores":{"entries":[{"path":"src/Money.php",'
        . '"mutator":"Plus","reason":"Imported from infection.json5 (Acme\\\\Money): write the real reason","expires":"2026-12-29"}]},'
        . '"reports":[{"use":"gitlab","path":"cq.json"}]}',
    )->and(Imports::keys($import))->toBe([
        '  source.directories: imported as trees: src, which replace the list the tree source finds',
        '  minMsi: imported as the floor of every tree, 75.00',
        '  timeout: imported as timeouts.seconds: 5',
        '  mutators.Plus.ignore: imported as an ignore of Plus in src/Money.php until 2026-12-29, from Acme\\Money',
        '  logs.gitlab: imported as a reports entry gitlab at cq.json',
        '  mutators: stays in infection.json5, because the Infection adapter reads it',
        '  threads: stays in infection.json5, because the gate overrides it for each run',
    ]);
});
