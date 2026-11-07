<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Azure;

/**
 * A comment the gate wrote on a pull request: the thread that holds it, and the identity that wrote it.
 *
 * @internal
 */
final readonly class PlacedComment
{
    private function __construct(private int $thread, private string $author)
    {
    }

    public static function of(int $thread, string $author): self
    {
        return new self($thread, $author);
    }

    public function thread(): int
    {
        return $this->thread;
    }

    public function author(): string
    {
        return $this->author;
    }
}
