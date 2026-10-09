<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Time;

use DateTimeImmutable;

use function intdiv;
use function intval;

use NightWorksIO\MutationGate\Core\CannotJudge;

use function preg_match;
use function round;
use function sprintf;

/** A duration, in seconds. */
final readonly class Seconds
{
    /** Nanoseconds in a second, as a monotonic clock and OTLP count time. */
    public const int NANOSECONDS = 1_000_000_000;

    /** Seconds in a minute. */
    public const int PER_MINUTE = 60;

    private const int PER_HOUR = 3600;

    private const int PER_DAY = 86_400;

    private const int MICROSECONDS = 1_000_000;

    /** Days, hours, minutes and seconds, each optional and in that order: `90s`, `15m`, `1h30m`, `7d`. */
    private const string DURATION = '/^(?:(?<d>\d+)d)?(?:(?<h>\d+)h)?(?:(?<m>\d+)m)?(?:(?<s>\d+)s)?$/D';

    private function __construct(private float $seconds)
    {
    }

    public static function of(float $seconds): self
    {
        return new self($seconds);
    }

    /** The time from one moment to a later one. */
    public static function between(DateTimeImmutable $start, DateTimeImmutable $end): self
    {
        return new self((float) $end->format('U.u') - (float) $start->format('U.u'));
    }

    public static function days(int $days): self
    {
        return new self($days * self::PER_DAY);
    }

    public static function minutes(int $minutes): self
    {
        return new self($minutes * self::PER_MINUTE);
    }

    /** A duration as a config or an option writes it: `90s`, `15m`, `1h30m` or `7d`. */
    public static function parse(string $duration): self|CannotJudge
    {
        if ($duration === '' || preg_match(self::DURATION, $duration, $parts, PREG_UNMATCHED_AS_NULL) !== 1) {
            return CannotJudge::because(
                sprintf('"%s" is not a duration. Write it as 90s, 15m, 1h30m or 7d.', $duration),
            );
        }

        $days = intval($parts['d']) * self::PER_DAY;
        $hours = intval($parts['h']) * self::PER_HOUR;
        $minutes = intval($parts['m']) * self::PER_MINUTE;

        return new self($days + $hours + $minutes + intval($parts['s']));
    }

    /** The duration in whole microseconds, as `usleep` takes it. */
    public function microseconds(): int
    {
        return intval(round($this->seconds * self::MICROSECONDS));
    }

    public function seconds(): float
    {
        return $this->seconds;
    }

    /** The duration in whole nanoseconds, rounded. */
    public function nanoseconds(): int
    {
        return (int) round($this->seconds * self::NANOSECONDS);
    }

    /** The duration in minutes, as runner time is billed. */
    public function inMinutes(): float
    {
        return $this->seconds / self::PER_MINUTE;
    }

    /**
     * The duration as a report says it, to the nearest unit it shows: `45s`
     * under a minute, then `6m`, then `1h 41m` (ADR-0017, decision 13).
     */
    public function text(): string
    {
        $minutes = intval(round($this->seconds / self::PER_MINUTE));
        $hours = intdiv($minutes, self::PER_MINUTE);
        $left = $minutes % self::PER_MINUTE;

        return match (true) {
            round($this->seconds) < self::PER_MINUTE => sprintf('%ds', round($this->seconds)),
            $hours === 0 => sprintf('%dm', $minutes),
            default => $left === 0 ? sprintf('%dh', $hours) : sprintf('%dh %dm', $hours, $left),
        };
    }

    /** The duration of one test, which is often under a second: `0.25s`, or as `text()` says it from a minute. */
    public function preciseText(): string
    {
        return round($this->seconds) < self::PER_MINUTE ? sprintf('%.2fs', $this->seconds) : $this->text();
    }

    /** As a config writes a duration, in whole seconds: `90s` is `1m30s`, `3600s` is `1h`, and none is `0s`. */
    public function written(): string
    {
        $whole = intval($this->seconds);
        $days = intdiv($whole, self::PER_DAY);
        $hours = intdiv($whole % self::PER_DAY, self::PER_HOUR);
        $minutes = intdiv($whole % self::PER_HOUR, self::PER_MINUTE);
        $seconds = $whole % self::PER_MINUTE;
        $written = sprintf(
            '%s%s%s%s',
            $days > 0 ? sprintf('%dd', $days) : '',
            $hours > 0 ? sprintf('%dh', $hours) : '',
            $minutes > 0 ? sprintf('%dm', $minutes) : '',
            $seconds > 0 ? sprintf('%ds', $seconds) : '',
        );

        return $written === '' ? '0s' : $written;
    }
}
