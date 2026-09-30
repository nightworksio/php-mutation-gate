<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/**
 * An adapter a config chooses by its class rather than by a name an extension
 * registered: `Acme\Gate\SlackReporter`, or `\SlackReporter` for a class in
 * the global namespace.
 */
final readonly class ClassNamed
{
    private function __construct(private string $class)
    {
    }

    public static function of(string $class): self
    {
        return new self($class);
    }

    /** The class, as the config writes it. */
    public function value(): string
    {
        return $this->class;
    }
}
