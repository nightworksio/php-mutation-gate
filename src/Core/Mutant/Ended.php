<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

use function max;
use function mb_strcut;

use NightWorksIO\MutationGate\Core\Format\Bytes;
use NightWorksIO\MutationGate\Core\Format\Printable;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * How a killed mutant's own process ended, where the runner knows: the code
 * it exited with, whether a signal ended it, and the end of what it printed,
 * where the runner gives that. It keeps four times the tail of what was
 * printed, and whether it cut any off, so that what a cut leaves of a secret
 * at its start can be dropped before the tail is taken (see Secrets); its
 * tail is the last 2 KiB of that, every control and format character but a
 * tab and the ends of lines dropped, cut on a character's edge.
 */
final readonly class Ended
{
    /** How many bytes of what the process printed its tail keeps. */
    private const int TAIL = 2048;

    /** How many bytes of what it printed it keeps until it is screened for secrets. */
    private const int KEPT = self::TAIL * 4;

    private function __construct(
        private int|NotGiven $code,
        private bool|NotGiven $signalled,
        private string|NotGiven $printed,
        private bool $cut,
    ) {
    }

    public static function of(int|NotGiven $code, bool|NotGiven $signalled, string $printed): self
    {
        return new self($code, $signalled, self::last($printed, self::KEPT), Bytes::length($printed) > self::KEPT);
    }

    /** A process that ended so, with nothing of what it printed. */
    public static function unprinted(int|NotGiven $code, bool|NotGiven $signalled): self
    {
        return new self($code, $signalled, NotGiven::value(), cut: false);
    }

    /**
     * What it printed, as much of it as it keeps: four times its tail, so a
     * secret screened for in it before it is cut leaves none of itself at
     * the cut; none where the runner gives none.
     */
    public function printed(): string|NotGiven
    {
        return $this->printed;
    }

    /** Whether it kept less than the process printed, so what it keeps may start inside a secret. */
    public function wasCut(): bool
    {
        return $this->cut;
    }

    /** The code it exited with, where the runner read one. */
    public function code(): int|NotGiven
    {
        return $this->code;
    }

    /** Whether a signal ended it, where the runner can tell. */
    public function signalled(): bool|NotGiven
    {
        return $this->signalled;
    }

    /** The last 2 KiB of what it printed, as text a terminal shows safely; none where it keeps none. */
    public function tail(): string|NotGiven
    {
        return $this->printed instanceof NotGiven
            ? $this->printed
            : self::last(Printable::text($this->printed), self::TAIL);
    }

    /**
     * The last of these bytes of text, from a character's first byte: a cut
     * that falls inside a character moves past it.
     */
    private static function last(string $text, int $bytes): string
    {
        $length = Bytes::length($text);
        $start = max(0, $length - $bytes);
        $tail = mb_strcut($text, $start, null, Printable::UTF8);

        while (Bytes::length($tail) > $bytes) {
            ++$start;
            $tail = mb_strcut($text, $start, null, Printable::UTF8);
        }

        return $tail;
    }
}
