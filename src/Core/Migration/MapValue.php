<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Migration;

use NightWorksIO\MutationGate\Core\Format\JsonDocument;
use NightWorksIO\MutationGate\Core\Format\JsonFragment;
use NightWorksIO\MutationGate\Core\NotGiven;

/** A value a key may hold given another: `timeouts.mode`'s `"soft"` written `"hard"`. */
final readonly class MapValue implements Step
{
    private function __construct(
        private KeyPath $key,
        private JsonFragment $old,
        private JsonFragment $new,
        private Spelling|NotGiven $spelling,
    ) {
    }

    public static function of(string $key, string|int|float|bool $old, string|int|float|bool $new): self
    {
        return new self(
            KeyPath::of($key),
            JsonFragment::encoding($old),
            JsonFragment::encoding($new),
            NotGiven::value(),
        );
    }

    /** This change, made to the literal argument of a builder call (ADR-0026, decision 3). */
    public function spelt(Spelling $spelling): self
    {
        return new self($this->key, $this->old, $this->new, $spelling);
    }

    public function applied(JsonDocument $file): JsonDocument
    {
        return $this->appliesTo($file) ? $file->with($this->key, $this->new) : $file;
    }

    public function appliesTo(JsonDocument $file): bool
    {
        $written = $file->fragment($this->key);

        return $written instanceof JsonFragment && $written->equals($this->old);
    }

    public function at(): KeyPath
    {
        return $this->key;
    }

    public function change(): string
    {
        return Changes::mapped($this->key, $this->old, $this->new);
    }

    public function spelling(): Spelling|NotGiven
    {
        return $this->spelling;
    }

    /** The value it retires. */
    public function old(): JsonFragment
    {
        return $this->old;
    }

    /** The value that replaces it. */
    public function new(): JsonFragment
    {
        return $this->new;
    }
}
