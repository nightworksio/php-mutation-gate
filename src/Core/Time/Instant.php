<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Time;

use DateTimeImmutable;
use DateTimeZone;
use NightWorksIO\MutationGate\Core\CannotJudge;

use function preg_match;
use function sprintf;

/** A moment, to the second, in UTC, written `2026-09-29T20:48:17Z`. */
final readonly class Instant
{
    /** How an instant is written, as `DateTimeImmutable::format` takes it. */
    public const string FORMAT = 'Y-m-d\TH:i:s\Z';

    private const string WRITTEN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D';

    private const string UTC = 'UTC';

    private function __construct(private string $value)
    {
    }

    /** The instant a clock read, whatever time zone it read it in. */
    public static function at(DateTimeImmutable $moment): self
    {
        return new self($moment->setTimezone(new DateTimeZone(self::UTC))->format(self::FORMAT));
    }

    /** An instant as a file the gate wrote spells it. */
    public static function parse(string $instant): self|CannotJudge
    {
        if (preg_match(self::WRITTEN, $instant) !== 1) {
            return CannotJudge::because(sprintf('"%s" is not an instant. Write it as 2026-09-29T20:48:17Z.', $instant));
        }

        return new self($instant);
    }

    public function value(): string
    {
        return $this->value;
    }

    /** Whether this came after another; written in one zone and to the second, instants order as their text does. */
    public function isAfter(self $other): bool
    {
        return $this->value > $other->value;
    }
}
