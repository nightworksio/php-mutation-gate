<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\NativeMarkers;
use NightWorksIO\MutationGate\Adapter\Infection\OwnConfig;
use NightWorksIO\MutationGate\Adapter\Infection\Project;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Marker;
use NightWorksIO\MutationGate\Core\Mutant\Markers;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * Every marker as where, what, and its replacement.
 *
 * @return list<array{string, string, string}>
 */
function markersRead(Markers $markers): array
{
    return array_map(
        static fn(Marker $marker): array => [$marker->where(), $marker->marker(), $marker->replacement()],
        iterator_to_array($markers, preserve_keys: false),
    );
}

function markersConfig(string $text): OwnConfig
{
    $config = OwnConfig::read('infection.json5', $text);

    return $config instanceof OwnConfig ? $config : throw new RuntimeException($config->why());
}

it('finds @infection-ignore-all in a comment of the files asked for, on its own line', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'src/Money.php', implode("\n", [
        '<?php',
        '/**',
        ' * Money.',
        ' *',
        ' * @infection-ignore-all',
        ' */',
        'final class Money',
        '{',
        '    // @infection-ignore-all',
        '    public function add(): string { return "@infection-ignore-all"; }',
        '}',
    ]));
    Scratch::write($root, 'src/Held.php', "<?php\n// @infection-ignore-all\n");
    $project = Project::at($root, Paths::none(), Path::of('.gate'));
    $replaces = '{"mutant": "<the id of each mutant it hides>", "reason": "<why no test can tell>"}';

    expect(markersRead(NativeMarkers::in($project, markersConfig('{}'), Paths::of(Path::of('src/Money.php')))))->toBe([
        ['src/Money.php:5', '@infection-ignore-all', $replaces],
        ['src/Money.php:9', '@infection-ignore-all', $replaces],
    ]);
});

it('finds every pattern under mutators that hides a mutant, with the entry that replaces it', function (): void {
    $config = markersConfig((string) json_encode(['mutators' => [
        '@default' => true,
        'global-ignore' => ['App\\Log::*'],
        'global-ignoreSourceCodeByRegex' => ['Assert::.*'],
        '@arithmetic' => ['ignore' => ['App\\Money::add']],
        'Plus' => ['ignore' => ['App\\Money::add::11', 'App\\Tax'], 'ignoreSourceCodeByRegex' => ['\\$cache.*']],
        'Minus' => ['settings' => ['a' => 1], 'ignore' => 'not a list'],
    ]]));
    $project = Project::at(Scratch::directory(), Paths::none(), Path::of('.gate'));
    $ignore = '{"path": "<the file %s is in>", "mutator": "%s", "reason": "<why no test can tell>"}';
    $regex = '{"mutant": "<the id of each mutant %s matches>", "reason": "<why no test can tell>"}';

    expect(markersRead(NativeMarkers::in($project, $config, Paths::none())))->toBe([
        ['infection.json5 mutators.global-ignore', 'App\\Log::*', sprintf($ignore, 'App\\Log::*', '<a mutator or family>')],
        ['infection.json5 mutators.global-ignoreSourceCodeByRegex', 'Assert::.*', sprintf($regex, 'Assert::.*')],
        ['infection.json5 mutators.@arithmetic.ignore', 'App\\Money::add', sprintf($ignore, 'App\\Money::add', '<a mutator or family>')],
        ['infection.json5 mutators.Plus.ignore', 'App\\Money::add::11', sprintf($ignore, 'App\\Money::add::11', 'Plus')],
        ['infection.json5 mutators.Plus.ignore', 'App\\Tax', sprintf($ignore, 'App\\Tax', 'Plus')],
        ['infection.json5 mutators.Plus.ignoreSourceCodeByRegex', '\\$cache.*', sprintf($regex, '\\$cache.*')],
    ]);
});

it('finds nothing in files and a config without markers', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'src/Money.php', "<?php\n// an ordinary comment\n");
    $project = Project::at($root, Paths::none(), Path::of('.gate'));

    expect(count(NativeMarkers::in($project, markersConfig('{"mutators": {"@default": true}}'), Paths::of(Path::of('src')))))
        ->toBe(0);
});
