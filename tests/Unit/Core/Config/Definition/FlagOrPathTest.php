<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\ConfigFile;
use NightWorksIO\MutationGate\Core\Config\Definition\FlagOrPath;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;

$read = static fn(string $json): Node => Node::config($json)->field('n');

it('takes true or false as they are, and a path named from where the layer that writes it is', function () use ($read): void {
    $ci = FlagOrPath::of(ConfigFile::at(Path::of('/project/ci/gate.json'), Path::of('/project')));

    expect(FlagOrPath::of(ProjectRoot::origin())->read($read('{"n": true}'))->value())->toBeTrue()
        ->and(FlagOrPath::of(ProjectRoot::origin())->read($read('{"n": false}'))->value())->toBeFalse()
        ->and($ci->read($read('{"n": "../logo.svg"}'))->value())->toEqual(Path::of('logo.svg'));
});

it('refuses anything else, and a path out of the project', function (string $json, string $message) use ($read): void {
    expect(FlagOrPath::of(ProjectRoot::origin())->read($read($json))->problems())->toEqual([Problem::at('n', $message)]);
})->with([
    'a number' => ['{"n": 3}', 'expected true, false or a path, got 3'],
    'an empty path' => ['{"n": ""}', 'expected a path, got ""'],
    'a path up out of the project' => ['{"n": "../logo.svg"}', 'expected a path inside the project, got "../logo.svg"'],
]);

it('writes its schema as a boolean or a path, and sets nothing beneath it', function (): void {
    $shape = FlagOrPath::of(ProjectRoot::origin());

    expect($shape->schema()->line())->toBe('{"oneOf":[{"type":"boolean"},{"type":"string","minLength":1}]}')
        ->and($shape->effects())->toBe([]);
});
