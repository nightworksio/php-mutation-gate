<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Watch;

/**
 * Why `outbreak serve` watches no file for `watch`: its watcher could not
 * arm, as where inotify has no watch left, or it failed (ADR-0133).
 */
final readonly class Unwatched
{
    private function __construct(private string $why)
    {
    }

    public static function because(string $why): self
    {
        return new self($why);
    }

    public function why(): string
    {
        return $this->why;
    }
}
