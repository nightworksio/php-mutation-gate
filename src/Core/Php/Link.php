<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function ltrim;
use function mb_strtolower;

/** One call of a chain, or a property it reads: its name, as PHP matches it in any case, and its arguments. */
final readonly class Link
{
    /** @param list<Argument> $arguments */
    private function __construct(private string $name, private array $arguments)
    {
    }

    /**
     * A call, or a property read, by the name it is spelt with.
     *
     * @param list<Argument> $arguments
     */
    public static function of(string $spelt, array $arguments): self
    {
        return new self(mb_strtolower(ltrim($spelt, '\\')), $arguments);
    }

    /** The name, in lower case and with no leading backslash. */
    public function name(): string
    {
        return $this->name;
    }

    /** @return list<Argument> */
    public function arguments(): array
    {
        return $this->arguments;
    }
}
