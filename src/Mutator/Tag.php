<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Mutator;

/** What a mutator is about, such as `security`, which a run can judge apart (ADR-0021 decision 16). */
final readonly class Tag
{
    private const string SECURITY = 'security';

    private function __construct(private string $name)
    {
    }

    /** The mutator changes a defence, and its mutants are also held to the security floor. */
    public static function security(): self
    {
        return new self(self::SECURITY);
    }

    public static function named(string $name): self
    {
        return new self($name);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function equals(self $other): bool
    {
        return $this->name === $other->name;
    }
}
