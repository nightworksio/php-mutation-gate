<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Git\Command;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('cannot tell where git cannot be fed in the directory', function (): void {
    $missing = sprintf('%s/missing', Scratch::directory());

    expect(Command::in($missing)->feed(['hash-object', '--stdin'], "hello\n"))
        ->toEqual(CannotTell::because(sprintf('git hash-object --stdin gave no answer: The provided cwd "%s" does not exist.', $missing)));
});
