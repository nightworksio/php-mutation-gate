<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Git\Command;
use NightWorksIO\MutationGate\Adapter\Git\WorkingTree;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('cannot tell a link\'s blob id where git gives none', function (): void {
    $root = Scratch::directory();
    symlink('nowhere', sprintf('%s/Link.php', $root));
    $missing = sprintf('%s/not-a-directory', $root);

    expect(WorkingTree::of(Command::in($missing), Root::of($root))->fingerprints(['Link.php']))
        ->toEqual(CannotTell::because(sprintf('git hash-object --stdin gave no answer: The provided cwd "%s" does not exist.', $missing)));
});
