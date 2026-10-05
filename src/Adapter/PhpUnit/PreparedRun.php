<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Mutator\Engine\MadeMutant;

/**
 * One mutant's run, ready to start: the mutant, the tests that cover it, the
 * files PHPUnit reads and writes for it, the command that runs them, and the
 * limit and memory cap it runs under.
 */
final readonly class PreparedRun
{
    private function __construct(
        private MadeMutant $made,
        private TestIds $covering,
        private MutantFiles $files,
        private Command $command,
        private Seconds $limit,
        private MemoryCap $cap,
    ) {
    }

    public static function of(
        MadeMutant $made,
        TestIds $covering,
        MutantFiles $files,
        Command $command,
        Seconds $limit,
        MemoryCap $cap,
    ): self {
        return new self($made, $covering, $files, $command, $limit, $cap);
    }

    public function made(): MadeMutant
    {
        return $this->made;
    }

    public function covering(): TestIds
    {
        return $this->covering;
    }

    public function files(): MutantFiles
    {
        return $this->files;
    }

    public function command(): Command
    {
        return $this->command;
    }

    public function limit(): Seconds
    {
        return $this->limit;
    }

    public function cap(): MemoryCap
    {
        return $this->cap;
    }
}
