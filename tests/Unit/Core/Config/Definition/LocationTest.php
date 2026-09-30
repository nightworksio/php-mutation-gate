<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\ConfigFile;
use NightWorksIO\MutationGate\Core\Config\Definition\Location;
use NightWorksIO\MutationGate\Core\Config\PathOrigin;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;

$ci = ConfigFile::at(Path::of('/project/ci/gate.json'), Path::of('/project'));

it('names a path from where the layer that writes it is', function () use ($ci): void {
    expect(Location::path($ci)->read(Node::config('"../src"'))->value())->toEqual(Path::of('src'))
        ->and(Location::path(ProjectRoot::origin())->read(Node::config('"./src/"'))->value())->toEqual(Path::of('src'));
});

it('keeps an absolute path the command line names', function (): void {
    expect(Location::path(ProjectRoot::commandLine())->read(Node::config('"/tmp/x.json"'))->value())
        ->toEqual(Path::of('/tmp/x.json'));
});

it('refuses a path that goes up out of the project, or is absolute where the command line does not name it', function (
    PathOrigin $origin,
    string $written,
): void {
    expect(Location::path($origin)->read(Node::config($written))->problems())
        ->toEqual([Problem::at('', sprintf('expected a path inside the project, got %s', $written))]);
})->with([
    'up, in a file' => [$ci, '"../../x"'],
    'absolute, in a file' => [$ci, '"/tmp/x.json"'],
    'absolute, in a preset' => [ProjectRoot::origin(), '"/tmp/x.json"'],
    'up, on the command line' => [ProjectRoot::commandLine(), '"../x.json"'],
]);
