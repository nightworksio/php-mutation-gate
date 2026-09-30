<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;

use function str_contains;

/**
 * An adapter a setting chooses (ADR-0002): a name an extension registered,
 * or a class, with the options written beside it, every default of a
 * built-in adapter's filled in.
 */
final readonly class Choice
{
    /** What sets a class apart from a name: a namespace, or the global one's leading backslash. */
    private const string NAMESPACED = '\\';

    private function __construct(private Name|ClassNamed $use, private Options $options)
    {
    }

    /** As a config writes it: a class where it has a backslash, `Acme\Reporter` or `\Reporter`, else a name. */
    public static function of(string $use, Options $options): self
    {
        return new self(str_contains($use, self::NAMESPACED) ? ClassNamed::of($use) : Name::of($use), $options);
    }

    /** The name or the class. */
    public function use(): Name|ClassNamed
    {
        return $this->use;
    }

    /** The options, with every default of a built-in adapter's filled in. */
    public function options(): Options
    {
        return $this->options;
    }

    /** As a config writes it: its name alone where it has no options, else `{"use": …, "with": …}`. */
    public function written(): Json|string
    {
        $with = $this->options->written();

        return $with->isEmpty()
            ? $this->use->value()
            : Json::object(Member::of('use', $this->use->value()))->with(Member::of('with', $with));
    }
}
