<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpUnit\MutantFile;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Override;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Project;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('writes the script that registers the wrapper, in the adapter\'s own directory', function (): void {
    $project = Project::at(Scratch::directory(), Path::of('vendor'), Path::of('.mutation-gate'));
    $script = Override::writtenFor($project);

    expect($script)->toBe($project->own('override.php'))
        ->and((string) file_get_contents(is_string($script) ? $script : ''))
        ->toBe(sprintf("<?php\n\ndeclare(strict_types=1);\n\n%s", MutantFile::registering()));
});
