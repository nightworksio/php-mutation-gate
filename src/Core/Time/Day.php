<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Time;

use function checkdate;

use DateTimeImmutable;
use NightWorksIO\MutationGate\Core\CannotJudge;

use function preg_match;
use function sprintf;

/** A calendar day, written `YYYY-MM-DD`. */
final readonly class Day
{
    private const string FORMAT = 'Y-m-d';

    private const string WRITTEN = '/^(?<y>\d{4})-(?<m>\d{2})-(?<d>\d{2})$/D';

    private function __construct(private string $value) {}

    /** A day as a config writes it. */
    public static function of(string $day): self|CannotJudge
    {
        if (preg_match(self::WRITTEN, $day, $parts) !== 1 || ! checkdate((int) $parts['m'], (int) $parts['d'], (int) $parts['y'])) {
            return CannotJudge::because(sprintf('"%s" is not a day. Write it as YYYY-MM-DD.', $day));
        }

        return new self($day);
    }

    /** The day an instant falls on, in the instant's own time zone. */
    public static function on(DateTimeImmutable $instant): self
    {
        return new self($instant->format(self::FORMAT));
    }

    public function value(): string
    {
        return $this->value;
    }

    public function isBefore(self $other): bool
    {
        return $this->value < $other->value;
    }
}
