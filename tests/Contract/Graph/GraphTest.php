<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Outbreak\Outbreak;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Php\NamedFiles;
use NightWorksIO\MutationGate\Core\Php\PhpFile;
use NightWorksIO\MutationGate\Core\Reach\GraphAnswer;
use NightWorksIO\MutationGate\Core\Reach\GraphQuestion;
use NightWorksIO\MutationGate\Tests\Support\Tree;

// The static graph's reader held to the gate's own name graph (ADR-0132,
// decision 7): on the gate's sources and on each fixture project, every other
// PHP file the gate's NameGraph says a file names, the binary's graph says it
// names too. Outbreak's core is the binary OUTBREAK_BINARY names; with none,
// the graph answers nothing.

$projects = [
    'the gate\'s sources' => ['src'],
    'the runner fixture' => ['tests/Contract/Runner/fixture'],
    'the static checker fixture' => ['tests/Contract/StaticChecker/fixture'],
];

it('joins every file to each PHP file the gate\'s name graph says it names', function (string ...$directories): void {
    $root = Tree::root();
    $binary = getenv('OUTBREAK_BINARY');
    $graph = is_string($binary) ? Outbreak::at($binary, $root) : Outbreak::none($root);
    $read = [];

    foreach ($directories as $directory) {
        foreach (Tree::filesUnder($directory) as $path) {
            $read[$path] = PhpFile::read(Contents::of((string) file_get_contents(Tree::at($path))));
        }
    }

    $paths = Paths::of(...array_map(Path::of(...), array_keys($read)));
    $answer = $graph->answered(GraphQuestion::over($paths, Paths::none(), Paths::none())->naming($paths));

    if (! $graph->isInUse()) {
        expect($answer)->toBeInstanceOf(NotWritten::class);

        return;
    }

    $names = NamedFiles::read($read);
    $missing = [];

    expect($answer)->toBeInstanceOf(GraphAnswer::class);

    foreach ($paths as $path) {
        $named = $answer instanceof GraphAnswer ? $answer->named($path) : Paths::none();

        foreach ($names->named($path->value()) as $target) {
            $joined = $named instanceof Paths && $named->has(Path::of($target));

            if ($target !== $path->value() && str_ends_with($target, '.php') && ! $joined) {
                $missing[] = sprintf('%s names %s', $path->value(), $target);
            }
        }
    }

    expect($missing)->toBe([]);
})->with($projects);
