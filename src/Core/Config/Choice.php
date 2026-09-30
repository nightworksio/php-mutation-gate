<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;

/**
 * An adapter a setting chooses (ADR-0002): a name an extension registered,
 * or a class, with the options written beside it, every default of a
 * built-in adapter's filled in.
 */
final readonly class Choice
{
    private function __construct(private string $use, private Json $options)
    {
    }

    public static function of(string $use, Json $options): self
    {
        return new self($use, $options);
    }

    /** The name or the class, as the config writes it. */
    public function use(): string
    {
        return $this->use;
    }

    /** The options, as a JSON object, with every default of a built-in adapter's filled in. */
    public function options(): Json
    {
        return $this->options;
    }

    /** As a config writes it: its name alone where it has no options, else `{"use": …, "with": …}`. */
    public function written(): Json|string
    {
        return $this->options->isEmpty()
            ? $this->use
            : Json::object(Member::of('use', $this->use))->with(Member::of('with', $this->options));
    }

}
