<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Init\NextSteps;
use NightWorksIO\MutationGate\Tests\Support\Repository;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

$holds = [
    'holds:src/Cli/Init/NextSteps.php',
];

afterEach(function (): void {
    Scratch::sweep();
});

it('says to git add each file init changed or wrote, in byte order, and none git ignores or would have added before', function (): void {
    $repository = Repository::empty()
        ->write('.gitignore', "ignored/\n")
        ->write('composer.json', "{}\n")
        ->commit('The project.')
        ->write('notes.txt', "Here before init.\n");
    $next = NextSteps::before($repository->root);
    $repository
        ->write('composer.json', "{\"scripts\": {}}\n")
        ->write('mutation-gate.json', "{}\n")
        ->write('.github/workflows/mutation.yml', "on: push\n")
        ->write('mutation notes.md', "Kept.\n")
        ->write('ignored/cache.json', "{}\n");

    expect($next->said())->toBe(implode("\n", [
        'Next:',
        '  vendor/bin/mutation-gate doctor',
        '  vendor/bin/mutation-gate',
        "  git add .github/workflows/mutation.yml composer.json 'mutation notes.md' mutation-gate.json",
    ]));
})->group(...$holds);
