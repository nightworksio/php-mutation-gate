<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Command\EditorFiles;
use NightWorksIO\MutationGate\Cli\Command\Output;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Editor\VsCode;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('writes the task and the recommendation where neither file is there', function (): void {
    $project = Scratch::directory();

    expect(EditorFiles::made($project, Output::Written))
        ->toBe("Wrote .vscode/tasks.json.\nWrote .vscode/extensions.json.")
        ->and((string) file_get_contents(sprintf('%s/.vscode/tasks.json', $project)))->toBe(sprintf("%s\n", VsCode::tasks()))
        ->and((string) file_get_contents(sprintf('%s/.vscode/extensions.json', $project)))
        ->toBe(sprintf("%s\n", VsCode::extensions()));
});

it('prints what to add to each file that is there, and leaves it as it is', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, '.vscode/tasks.json', '{"version": "2.0.0", "tasks": []}');
    Scratch::write($project, '.vscode/extensions.json', '{"recommendations": []}');

    expect(EditorFiles::made($project, Output::Written))->toBe(sprintf(
        "Add this task to the tasks in .vscode/tasks.json:\n\n%s\nAdd %s to the recommendations in .vscode/extensions.json.",
        VsCode::task(),
        VsCode::SARIF_VIEWER,
    ))->and((string) file_get_contents(sprintf('%s/.vscode/tasks.json', $project)))
        ->toBe('{"version": "2.0.0", "tasks": []}');
});

it('prints both files whole with --stdout, and writes neither', function (): void {
    $project = Scratch::directory();

    expect(EditorFiles::made($project, Output::Printed))->toBe(sprintf(
        ".vscode/tasks.json:\n\n%s\n.vscode/extensions.json:\n\n%s",
        VsCode::tasks(),
        VsCode::extensions(),
    ))->and(is_dir(sprintf('%s/.vscode', $project)))->toBeFalse();
});

it('says why a file it cannot read is not set up', function (string $unreadable): void {
    $project = Scratch::directory();
    Scratch::write($project, sprintf('%s/inside', $unreadable), '');
    $made = EditorFiles::made($project, Output::Written);

    expect($made instanceof CannotJudge ? $made->why() : $made)
        ->toBe(sprintf('%s/%s could not be read.', $project, $unreadable));
})->with(['the tasks' => ['.vscode/tasks.json'], 'the recommendations' => ['.vscode/extensions.json']]);
