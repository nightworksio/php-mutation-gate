<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Alert;

/**
 * How much an alert says (ADR-0016): the most survivors it names, a chat
 * reporter's `with.survivors`, and the longest text the project wrote it
 * repeats, a path or a reason, before it is cut, `with.lineLength`.
 *
 * @internal
 */
final readonly class AlertShape
{
    /** The option of a chat reporter that says how many survivors an alert names. */
    public const string SURVIVORS = 'survivors';

    /** The option of a chat reporter that says how long a line of what the project wrote may be. */
    public const string LINE_LENGTH = 'lineLength';

    private const int STANDARD_SURVIVORS = 5;

    private const int STANDARD_LINE_LENGTH = 120;

    private function __construct(private int $survivors, private int $lineLength)
    {
    }

    /** Five survivors, and lines of 120 characters. */
    public static function standard(): self
    {
        return new self(self::STANDARD_SURVIVORS, self::STANDARD_LINE_LENGTH);
    }

    public static function of(int $survivors, int $lineLength): self
    {
        return new self($survivors, $lineLength);
    }

    public function survivors(): int
    {
        return $this->survivors;
    }

    public function lineLength(): int
    {
        return $this->lineLength;
    }
}
