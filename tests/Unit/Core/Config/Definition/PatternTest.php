<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\ConfigFile;
use NightWorksIO\MutationGate\Core\Config\Definition\Pattern;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;

it('names a glob from the directory of the file that writes it', function (): void {
    $ci = ConfigFile::at(Path::of('/project/ci/gate.json'), Path::of('/project'));

    expect(Pattern::glob($ci)->read(Node::config('"Gen/**"'))->value())->toBe('ci/Gen/**')
        ->and(Pattern::glob($ci)->read(Node::config('"../src/**"'))->value())->toBe('src/**')
        ->and(Pattern::glob(ProjectRoot::origin())->read(Node::config('"./src/**"'))->value())->toBe('src/**');
});

it('refuses a glob that is not text, or is empty', function (string $written): void {
    expect(Pattern::glob(ProjectRoot::origin())->read(Node::config($written))->problems())->toHaveCount(1);
})->with(['a number' => ['3'], 'nothing' => ['""']]);
