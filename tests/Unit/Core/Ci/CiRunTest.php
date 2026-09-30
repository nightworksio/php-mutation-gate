<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Ci\CiRun;

it('holds the repository, the full ref and commit, and the link to the run', function (): void {
    $run = CiRun::of('octo/gate', 'refs/heads/main', '5eeca8f0d2b1', 'https://ci.example/7');

    expect($run->repository())->toBe('octo/gate')
        ->and($run->ref())->toBe('refs/heads/main')
        ->and($run->commit())->toBe('5eeca8f0d2b1')
        ->and($run->url())->toBe('https://ci.example/7');
});

it('names a branch by its name, and any other ref as it is', function (): void {
    expect(CiRun::of('o/r', 'refs/heads/release/1.x', 'c', 'u')->refName())->toBe('release/1.x')
        ->and(CiRun::of('o/r', 'refs/tags/v1', 'c', 'u')->refName())->toBe('refs/tags/v1');
});
