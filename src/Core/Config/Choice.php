<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function array_flip;
use function array_key_exists;
use function implode;

use NightWorksIO\MutationGate\Core\Format\Json;

use function sprintf;

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
            : Json::object()->with('use', $this->use)->with('with', $this->options);
    }

    /**
     * As the builder writes it: the method of a builder class for a name it has one for, else `uses()` with each
     * option.
     *
     * @param list<string> $named the names the class has a method of its own for
     */
    public function php(string $class, array $named): string
    {
        return $this->options->isEmpty() && array_key_exists($this->use, array_flip($named))
            ? sprintf('%s::%s()', $class, $this->use)
            : sprintf(
                '%s::uses(%s)',
                $class,
                implode(', ', [PhpCalls::literal($this->use), ...PhpOptions::of($this->options)]),
            );
    }
}
