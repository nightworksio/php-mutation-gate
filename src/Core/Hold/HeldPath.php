<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Hold;

use function sprintf;

/**
 * The path a `#[Holds]` names: one string literal, which tokens can read, or
 * any other expression, such as a class constant, kept as it is written.
 */
final readonly class HeldPath
{
    private function __construct(private string $text, private bool $literal)
    {
    }

    /** One string literal, by what it holds. */
    public static function literal(string $path): self
    {
        return new self($path, literal: true);
    }

    /** Any other expression, as it is written. */
    public static function expression(string $written): self
    {
        return new self($written, literal: false);
    }

    public function isLiteral(): bool
    {
        return $this->literal;
    }

    /** What a literal holds, or the expression as it is written. */
    public function text(): string
    {
        return $this->text;
    }

    /** The argument as PHP: a literal in single quotes, or the expression. */
    public function written(): string
    {
        return $this->literal ? sprintf("'%s'", $this->text) : $this->text;
    }

    /** The argument that names the matching group, as PHP. */
    public function group(): string
    {
        return $this->literal ? sprintf("'holds:%s'", $this->text) : sprintf("'holds:' . %s", $this->text);
    }
}
