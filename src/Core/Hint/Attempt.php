<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Hint;

use function array_values;

/**
 * A concrete way to kill one survivor, built by rules from its diff, the
 * function around it and the values its file declares (ADR-0085): what
 * to try, as a sentence, and the arguments each call a stub makes passes in
 * place of the function's parameters.
 *
 * @internal
 */
final readonly class Attempt
{
    /** How every report and the stub say an attempt. */
    public const string SAID = 'Try: %s';

    /** @param list<array<string, string>> $calls each call's arguments, by the parameter's name */
    private function __construct(private string $text, private array $calls)
    {
    }

    /**
     * What to try, with the arguments of each call it makes, by the parameter's name.
     *
     * @param array<string, string> ...$calls
     */
    public static function of(string $text, array ...$calls): self
    {
        return new self($text, array_values($calls));
    }

    /** What to try, one sentence that starts in lower case, as `Try:` precedes it. */
    public function text(): string
    {
        return $this->text;
    }

    /** @return list<array<string, string>> each call's arguments, by the parameter's name; none for no call */
    public function calls(): array
    {
        return $this->calls;
    }
}
