<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\Import\ClassFiles;
use NightWorksIO\MutationGate\Adapter\Infection\Import\Ignores;
use NightWorksIO\MutationGate\Adapter\Infection\MutatorSettings;
use NightWorksIO\MutationGate\Adapter\Infection\Project;
use NightWorksIO\MutationGate\Core\Config\Ignored;
use NightWorksIO\MutationGate\Core\Config\IgnoredPattern;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Import\Import;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Imports;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

$imported = static function (string $mutators): Import {
    $root = Scratch::directory();
    Scratch::write($root, 'composer.json', '{"autoload": {"psr-4": {"Acme\\\\": "src/"}}}');
    Scratch::write($root, 'src/Money.php', '<?php');
    $classes = ClassFiles::in(Project::at(Root::of($root), Paths::none(), Path::of('.gate')));

    return Ignores::of(MutatorSettings::of(Node::decode($mutators)), 'infection.json5', $classes, new DateTimeImmutable(Configs::NOW));
};

it('makes a pattern over a class, or a method or line of it, an entry for the class\'s file with a reason to rewrite and an end', function () use ($imported): void {
    $import = $imported('{"Plus": {"ignore": ["Acme\\\\Money", "\\\\Acme\\\\Money::add::12"]}}');
    $reason = static fn(string $pattern): string => sprintf('"reason":"Imported from infection.json5 (%s): write the real reason","expires":"2026-12-29"', $pattern);

    expect(Imports::written($import))->toBe(sprintf(
        '{"ignores":{"entries":[{"path":"src/Money.php","mutator":"Plus",%s},{"path":"src/Money.php","mutator":"Plus",%s}]}}',
        $reason('Acme\\\\Money'),
        $reason('\\\\Acme\\\\Money::add::12'),
    ))->and(Imports::keys($import))->toBe([
        '  mutators.Plus.ignore: imported as an ignore of Plus in src/Money.php until 2026-12-29, from Acme\\Money',
        '  mutators.Plus.ignore: imported as an ignore of Plus in src/Money.php until 2026-12-29, from \\Acme\\Money::add::12, widened to the whole file',
    ]);
});

it('makes a global ignore an entry per family', function () use ($imported): void {
    $import = $imported('{"global-ignore": ["Acme\\\\Money"]}');
    $entries = $import->layer()->ignores()->entries();

    expect(array_map(static fn(Ignored $entry): string => $entry instanceof IgnoredPattern ? $entry->mutator() : "", [...$entries]))->toBe([
        'boundary', 'condition', 'logical', 'arithmetic', 'return-value', 'removed-call',
        'literal', 'collection', 'exception', 'unwrap', 'visibility',
    ])->and(Imports::keys($import))->toBe([
        '  mutators.global-ignore: imported as an ignore of every family in src/Money.php until 2026-12-29, from Acme\\Money',
    ]);
});

it('keeps native, and allows, a pattern over source, one with no file, a wildcard, and one under a profile', function () use ($imported): void {
    $import = $imported(<<<'JSON'
        {
            "Plus": {"ignore": ["Acme\\Missing", "Acme\\Legacy\\*"], "ignoreSourceCodeByRegex": ["Assert::.*"]},
            "@arithmetic": {"ignore": ["Acme\\Money"]}
        }
        JSON);

    expect(Imports::written($import))->toBe('{"ignores":{"native":"allow"}}')
        ->and(Imports::keys($import))->toBe([
            '  mutators.Plus.ignore: stays in infection.json5, because Acme\\Missing: no file the psr-4 or psr-0 autoload names holds its class, so ignores.native: allow keeps it',
            '  mutators.Plus.ignore: stays in infection.json5, because Acme\\Legacy\\*: a wildcard names no one class, so ignores.native: allow keeps it',
            '  mutators.Plus.ignoreSourceCodeByRegex: stays in infection.json5, because Assert::.*: the gate has no equivalent of a pattern over source, so ignores.native: allow keeps it',
            '  mutators.@arithmetic.ignore: stays in infection.json5, because Acme\\Money: a profile names mutators only Infection lists, so ignores.native: allow keeps it',
        ]);
});
