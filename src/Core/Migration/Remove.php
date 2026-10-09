<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Migration;

use NightWorksIO\MutationGate\Core\Format\JsonDocument;
use NightWorksIO\MutationGate\Core\NotGiven;

/** A key no release reads any more, taken out with its value, and why it went. */
final readonly class Remove implements Step
{
    private function __construct(private KeyPath $key, private string $because, private Spelling|NotGiven $spelling)
    {
    }

    /** A key taken out, and why, as a clause: `every run narrows its tests now`. */
    public static function of(string $key, string $because): self
    {
        return new self(KeyPath::of($key), $because, NotGiven::value());
    }

    /** This removal, taking a builder call out of the PHP config too. */
    public function spelt(Spelling $spelling): self
    {
        return new self($this->key, $this->because, $spelling);
    }

    public function applied(JsonDocument $file): JsonDocument
    {
        return $file->without($this->key);
    }

    public function appliesTo(JsonDocument $file): bool
    {
        return $file->has($this->key);
    }

    public function at(): KeyPath
    {
        return $this->key;
    }

    public function change(): string
    {
        return Changes::removed($this->key, $this->because);
    }

    public function spelling(): Spelling|NotGiven
    {
        return $this->spelling;
    }
}
