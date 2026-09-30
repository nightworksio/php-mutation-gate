<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/**
 * An adapter a setting chooses: the name an extension registered it under,
 * or its class, with the options written beside it as JSON text.
 */
final readonly class Choice
{
    private function __construct(private string $use, private string $options)
    {
    }

    /** @param string $options a JSON object */
    public static function of(string $use, string $options): self
    {
        return new self($use, $options);
    }

    /** The name or the class, as the config writes it. */
    public function use(): string
    {
        return $this->use;
    }

    /** The options, as a JSON object, with every default of a built-in adapter's filled in. */
    public function options(): string
    {
        return $this->options;
    }
}
