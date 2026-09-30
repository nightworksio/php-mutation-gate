<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Time;

use function intdiv;
use function intval;

use NightWorksIO\MutationGate\Core\CannotJudge;

use function preg_match;
use function round;
use function sprintf;

/** A duration, in seconds. */
final readonly class Seconds
{
    private const int PER_MINUTE = 60;

    private const int PER_HOUR = 3600;

    private const int MICROSECONDS = 1_000_000;

    /** Hours, minutes and seconds, each optional and in that order: `90s`, `15m`, `1h30m`. */
    private const string DURATION = '/^(?:(?<h>\d+)h)?(?:(?<m>\d+)m)?(?:(?<s>\d+)s)?$/D';

    private function __construct(private float $seconds)
    {
    }

    public static function of(float $seconds): self
    {
        return new self($seconds);
    }

    public static function minutes(int $minutes): self
    {
        return new self($minutes * self::PER_MINUTE);
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

    /** The duration in whole microseconds, as `usleep` takes it. */
    public function microseconds(): int
    {
        return intval(round($this->seconds * self::MICROSECONDS));
    }

    public function seconds(): float
    {
        return $this->seconds;
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
        $hours = intdiv($whole, self::PER_HOUR);
        $minutes = intdiv($whole % self::PER_HOUR, self::PER_MINUTE);
        $seconds = $whole % self::PER_MINUTE;
        $written = sprintf(
            '%s%s%s',
            $hours > 0 ? sprintf('%dh', $hours) : '',
            $minutes > 0 ? sprintf('%dm', $minutes) : '',
            $seconds > 0 ? sprintf('%ds', $seconds) : '',
        );

        return $written === '' ? '0s' : $written;
    }
}
