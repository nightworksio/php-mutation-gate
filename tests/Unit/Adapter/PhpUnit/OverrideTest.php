<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpUnit\MutantFile;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Override;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Project;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('writes the script that registers the wrapper, reading the variables the gate sets, in the adapter\'s own directory', function (): void {
    $project = Project::at(Scratch::directory(), Path::of('vendor'), Path::of('.mutation-gate'));
    $script = Override::writtenFor($project);

    expect($script)->toBe($project->own('override.php'))
        ->and((string) file_get_contents(is_string($script) ? $script : ''))->toBe(sprintf(
            "<?php\n\ndeclare(strict_types=1);\n\n%s",
            MutantFile::registering('MUTATION_GATE_MUTANT', 'MUTATION_GATE_MUTATED', 'MUTATION_GATE_GUARD'),
        ));
});

it('cannot judge where PHP\'s command line would read the script\'s path as ini syntax', function (string $directory): void {
    $root = sprintf('%s/%s', Scratch::directory(), $directory);
    mkdir($root);
    $project = Project::at($root, Path::of('vendor'), Path::of('.mutation-gate'));

    expect(Override::writtenFor($project))->toEqual(CannotJudge::because(sprintf(
        'PHP cannot prepend %s: its command line reads a ", a ${ or a \\\\ in a setting as ini syntax.',
        $project->own('override.php'),
    )))->and(is_file($project->own('override.php')))->toBeFalse();
})->with([
    'a quote' => ['a"b'],
    'an interpolation' => ['a${b'],
    'two backslashes' => ['a\\\\b'],
]);

it('writes the script where the path holds what PHP\'s command line reads as it is', function (string $directory): void {
    $root = sprintf('%s/%s', Scratch::directory(), $directory);
    mkdir($root);

    expect(Override::writtenFor(Project::at($root, Path::of('vendor'), Path::of('.mutation-gate'))))->toBeString();
})->with([
    'one backslash' => ['a\\b'],
    'a dollar' => ['a$b'],
    'a space, a semicolon and a quote of the other kind' => ["a b;c'd"],
]);
