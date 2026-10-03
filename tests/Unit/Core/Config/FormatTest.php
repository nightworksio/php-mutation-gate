<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Format;

it('names the format of a config file by its extension', function (string $extension, Format|Absent $format): void {
    expect(Format::fromExtension($extension))->toEqual($format);
})->with([
    ['php', Format::Php],
    ['json', Format::Json],
    ['yaml', Format::Yaml],
    ['yml', Format::Yaml],
    ['neon', Format::Neon],
    ['toml', Absent::setting()],
    ['', Absent::setting()],
]);

it('looks for a config file by every name it has, in the order the formats are listed', function (): void {
    expect(Format::fileNames())->toBe([
        'mutation-gate.php',
        'mutation-gate.json',
        'mutation-gate.yaml',
        'mutation-gate.yml',
        'mutation-gate.neon',
    ]);
});

it('lists the words --format takes, in the order the formats are listed', function (): void {
    expect(Format::words())->toBe('php, json, yaml or neon');
});

it('says what each format is called, what init writes it to, and what reads it', function (
    Format $format,
    string $title,
    string $file,
    string $package,
    bool $suggested,
): void {
    expect([$format->title(), $format->fileName(), $format->package(), $format->isSuggested()])
        ->toBe([$title, $file, $package, $suggested]);
})->with([
    [Format::Php, 'PHP', 'mutation-gate.php', 'nightworksio/mutation-gate', false],
    [Format::Json, 'JSON', 'mutation-gate.json', 'nightworksio/mutation-gate', false],
    [Format::Yaml, 'YAML', 'mutation-gate.yaml', 'symfony/yaml', true],
    [Format::Neon, 'NEON', 'mutation-gate.neon', 'nette/neon', true],
]);
