<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\ConfigFile;
use NightWorksIO\MutationGate\Core\Config\Definition\Pattern;
use NightWorksIO\MutationGate\Core\Config\PathOrigin;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;

it('names a glob from the directory of the file that writes it', function (): void {
    $ci = ConfigFile::at(Path::of('/project/ci/gate.json'), Path::of('/project'));

    expect(Pattern::glob($ci)->read(Node::config('"Gen/**"'))->value())->toEqual(Glob::of('ci/Gen/**'))
        ->and(Pattern::glob($ci)->read(Node::config('"../src/**"'))->value())->toEqual(Glob::of('src/**'))
        ->and(Pattern::glob(ProjectRoot::origin())->read(Node::config('"./src/**"'))->value())->toEqual(Glob::of('src/**'));
});

it('refuses a glob that is not text, or is empty', function (string $written): void {
    expect(Pattern::glob(ProjectRoot::origin())->read(Node::config($written))->problems())->toHaveCount(1);
})->with(['a number' => ['3'], 'nothing' => ['""']]);

it('refuses a glob that goes up out of the project, or is absolute, wherever it is written', function (
    PathOrigin $origin,
    string $written,
): void {
    expect(Pattern::glob($origin)->read(Node::config($written))->problems())
        ->toEqual([Problem::at('', sprintf('expected a path inside the project, got %s', $written))]);
})->with([
    'up, in a file' => [ConfigFile::at(Path::of('/project/ci/gate.json'), Path::of('/project')), '"../../src/**"'],
    'absolute, in a file' => [ConfigFile::at(Path::of('/project/ci/gate.json'), Path::of('/project')), '"/src/**"'],
    'absolute, on the command line' => [ProjectRoot::commandLine(), '"/src/**"'],
]);
