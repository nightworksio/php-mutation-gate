<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\Import\ClassFiles;
use NightWorksIO\MutationGate\Adapter\Infection\Import\Ignores;
use NightWorksIO\MutationGate\Adapter\Infection\Import\Profiles;
use NightWorksIO\MutationGate\Adapter\Infection\Import\Unmapped;
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

$imported = static function (string $mutators, bool $installed = true): Import {
    $root = Scratch::directory();
    Scratch::write($root, 'composer.json', '{"autoload": {"psr-4": {"Acme\\\\": "src/"}}}');
    Scratch::write($root, 'src/Money.php', '<?php');
    Scratch::write($root, 'src/Legacy/Old.php', '<?php');
    $classes = ClassFiles::in(Project::at(Root::of($root), Paths::none(), Path::of('.gate')));
    $profiles = Profiles::installed(static fn(): bool => $installed);

    return Ignores::of(MutatorSettings::of(Node::decode($mutators)), 'infection.json5', $classes, $profiles, new DateTimeImmutable(Configs::NOW));
};

/** @return list<array{string, string}> each entry an import writes, as its path and the mutator it names */
$entries = static fn(Import $import): array => array_map(
    static fn(Ignored $entry): array => $entry instanceof IgnoredPattern ? [$entry->path()->value(), $entry->mutator()] : [],
    [...$import->layer()->ignores()->entries()],
);

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

it('makes a pattern over a whole namespace an entry for everything under its directory', function () use ($imported, $entries): void {
    $import = $imported('{"Plus": {"ignore": ["Acme\\\\Legacy\\\\*"]}}');

    expect($entries($import))->toBe([['src/Legacy/**', 'Plus']])
        ->and(Imports::keys($import))->toBe([
            '  mutators.Plus.ignore: imported as an ignore of Plus in src/Legacy/** until 2026-12-29, from Acme\\Legacy\\*',
        ]);
});

it('makes a profile\'s pattern an entry per family it holds whole, and per other mutator of it by name', function () use ($imported, $entries): void {
    expect($entries($imported('{"@conditional_boundary": {"ignore": ["Acme\\\\Money"]}}')))->toBe([['src/Money.php', 'boundary']])
        ->and(array_column($entries($imported('{"@equal": {"ignore": ["Acme\\\\Money"]}}')), 1))->not->toContain('condition');
});

it('makes a global ignore an entry per family, and per mutator of no family', function () use ($imported, $entries): void {
    $names = array_column($entries($imported('{"global-ignore": ["Acme\\\\Money"]}')), 1);

    expect(array_slice($names, 0, 11))->toBe([
        'boundary', 'condition', 'logical', 'arithmetic', 'return-value', 'removed-call',
        'literal', 'collection', 'exception', 'unwrap', 'visibility',
    ])->and($names)->toContain('Concat');
});

it('keeps native, and allows, each pattern that maps to no entry, and says why', function () use ($imported): void {
    $import = $imported(<<<'JSON'
        {
            "Plus": {"ignore": ["Acme\\Missing", "Acme\\*Controller", "Acme\\Gone\\*"], "ignoreSourceCodeByRegex": ["Assert::.*"]},
            "@nope": {"ignore": ["Acme\\Money"]}
        }
        JSON);
    $kept = static fn(string $pattern, Unmapped $why): string => sprintf('%s: %s, so ignores.native: allow keeps it', $pattern, $why->said());

    expect(Imports::written($import))->toBe('{"ignores":{"native":"allow"}}')
        ->and(Imports::keys($import))->toBe([
            sprintf('  mutators.Plus.ignore: stays in infection.json5, because %s', $kept('Acme\\Missing', Unmapped::NoFile)),
            sprintf('  mutators.Plus.ignore: stays in infection.json5, because %s', $kept('Acme\\*Controller', Unmapped::Wildcard)),
            sprintf('  mutators.Plus.ignore: stays in infection.json5, because %s', $kept('Acme\\Gone\\*', Unmapped::NoDirectory)),
            sprintf('  mutators.Plus.ignoreSourceCodeByRegex: stays in infection.json5, because %s', $kept('Assert::.*', Unmapped::OverSource)),
            sprintf('  mutators.@nope.ignore: stays in infection.json5, because %s', $kept('Acme\\Money', Unmapped::NoProfile)),
        ]);
});

it('keeps a profile\'s pattern native where Infection is not installed to list its mutators', function () use ($imported): void {
    expect(Imports::keys($imported('{"global-ignore": ["Acme\\\\Money"]}', installed: false)))->toBe([
        '  mutators.global-ignore: stays in infection.json5, because Acme\\Money: Infection, which lists each profile\'s mutators, is not installed, so ignores.native: allow keeps it',
    ]);
});

it('says why each pattern maps to no entry', function (Unmapped $why, string $said): void {
    expect($why->said())->toBe($said);
})->with([
    [Unmapped::OverSource, 'the gate has no equivalent of a pattern over source'],
    [Unmapped::NoFile, 'no file the psr-4 or psr-0 autoload names holds its class'],
    [Unmapped::NoDirectory, 'no directory the psr-4 or psr-0 autoload names holds its namespace'],
    [Unmapped::Wildcard, 'a wildcard names no one class or namespace'],
    [Unmapped::NoInfection, 'Infection, which lists each profile\'s mutators, is not installed'],
    [Unmapped::NoProfile, 'Infection has no such profile'],
]);
