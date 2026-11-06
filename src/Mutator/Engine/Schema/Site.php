<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Mutator\Engine\Schema;

use PhpParser\Node;

/**
 * One mutant of a file as a schema switches it (ADR-0091): the node it
 * changes, as the parser read it from the file with its parent and its
 * offsets in the file, what replaces it, a node or its removal, and the id
 * the switch turns it on by.
 *
 * @internal the engine's own
 */
final readonly class Site
{
    private function __construct(private Node $original, private Node|int $replacement, private int $id)
    {
    }

    /** The mutant this id names, which puts the replacement, a node or a removal, in the original's place. */
    public static function of(Node $original, Node|int $replacement, int $id): self
    {
        return new self($original, $replacement, $id);
    }

    public function original(): Node
    {
        return $this->original;
    }

    /** What replaces the original: a node, or an int where the original is removed. */
    public function replacement(): Node|int
    {
        return $this->replacement;
    }

    public function id(): int
    {
        return $this->id;
    }

    /** The offset of the original's first byte in the file. */
    public function start(): int
    {
        return $this->original->getStartFilePos();
    }

    /** The offset one past the original's last byte in the file. */
    public function end(): int
    {
        return $this->original->getEndFilePos() + 1;
    }
}
