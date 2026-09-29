<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Time;

use function intval;

use NightWorksIO\MutationGate\Core\CannotJudge;

use function preg_match;
use function sprintf;

/** A duration, in seconds. */
final readonly class Seconds
{
    private const int PER_MINUTE = 60;

    private const int PER_HOUR = 3600;

    /** Hours, minutes and seconds, each optional and in that order: `90s`, `15m`, `1h30m`. */
    private const string DURATION = '/^(?:(?<h>\d+)h)?(?:(?<m>\d+)m)?(?:(?<s>\d+)s)?$/D';

    private function __construct(private float $seconds)
    {
    }

    public static function of(float $seconds): self
    {
        return new self($seconds);
    }

    /** A duration as a config or an option writes it: `90s`, `15m` or `1h30m`. */
    public static function parse(string $duration): self|CannotJudge
    {
        if ($duration === '' || preg_match(self::DURATION, $duration, $parts, PREG_UNMATCHED_AS_NULL) !== 1) {
            return CannotJudge::because(sprintf('"%s" is not a duration. Write it as 90s, 15m or 1h30m.', $duration));
        }

        $hours = intval($parts['h']) * self::PER_HOUR;
        $minutes = intval($parts['m']) * self::PER_MINUTE;

        return new self($hours + $minutes + intval($parts['s']));
    }

    public function seconds(): float
    {
        return $this->seconds;
    }
}
