<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Choice;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\Pest;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Tests\Support\Configs;

$kept = static fn(string ...$globs): Pest => Pest::of(pcovKept: Listed::of(...array_map(Glob::of(...), $globs)));

it('hands the Pest runner every layer\'s pest.pcovKept globs together, each once, and none where no layer lists one', function () use ($kept): void {
    $runner = Choice::of('pest', Configs::options('{}'));
    $listed = static function (Pest $pest) use ($runner): mixed {
        $options = json_decode($pest->beneath($runner)->options()->written()->line(), associative: true);

        return is_array($options) && array_key_exists('pcovKept', $options) ? $options['pcovKept'] : null;
    };

    expect($listed($kept('tests/Legacy/**')->over($kept('src/Coverage/*.php', 'tests/Legacy/**'))))
        ->toBe(['tests/Legacy/**', 'src/Coverage/*.php'])
        ->and($listed(Pest::standard()))->toBe([])
        ->and($listed($kept('tests/Legacy/**')->over(Pest::none())))->toBe(['tests/Legacy/**']);
});

it('writes pest.pcovKept as a layer lists it, and as the PHP config calls it, and nothing where it lists none', function () use ($kept): void {
    $written = static fn(Pest $pest): string => $pest->written(ProjectRoot::origin())->line();

    expect($written($kept('tests/Legacy/**')))->toBe('{"pest":{"pcovKept":["tests/Legacy/**"]}}')
        ->and($written(Pest::none()))->toBe('{}')
        ->and($kept('tests/Legacy/**')->php(ProjectRoot::origin())->code())->toContain("PestSpeedUps::pcovKept('tests/Legacy/**')")
        ->and(Pest::none()->php(ProjectRoot::origin())->code())->not->toContain('pcovKept');
});
