<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

/** What runs Pest for the adapter: a process in the project's root, or a fake in a test. */
interface Shell
{
    public function run(Command $command): Ran;

    /** The same shell, running each command in another directory. */
    public function in(string $directory): self;
}
