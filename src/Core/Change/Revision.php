<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Change;

/** A state of the repository: a ref or commit as git names it, or the working tree as it is on disk. */
final readonly class Revision
{
    private const string WORKING_TREE = 'the working tree';

    private function __construct(private string $name, private bool $workingTree) {}

    public static function ref(string $ref): self
    {
        return new self($ref, workingTree: false);
    }

    public static function workingTree(): self
    {
        return new self(self::WORKING_TREE, workingTree: true);
    }

    public function isWorkingTree(): bool
    {
        return $this->workingTree;
    }

    /** The ref as git names it, or "the working tree". */
    public function name(): string
    {
        return $this->name;
    }
}
