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
 * it exited with, whether a signal ended it, and the end of what it printed.
 * It keeps twice the tail of what was printed, so a secret hidden in it
 * before it is cut leaves none of itself at the cut; its tail is the last
 * 2 KiB of that, every control and format character but a tab and the ends
 * of lines dropped, cut on a character's edge.
 */
final readonly class Ended
{
    /** How many bytes of what the process printed its tail keeps. */
    private const int TAIL = 2048;

    /** How many bytes of what it printed it keeps until its secrets are hidden. */
    private const int KEPT = self::TAIL * 2;

    private const string UTF8 = 'UTF-8';

    private function __construct(private int|NotGiven $code, private bool|NotGiven $signalled, private string $printed)
    {
    }

    public static function of(int|NotGiven $code, bool|NotGiven $signalled, string $printed): self
    {
        return new self($code, $signalled, self::last($printed, self::KEPT));
    }

    /**
     * What it printed, as much of it as it keeps: twice its tail, so a
     * secret hidden in it before it is cut leaves none of itself at the cut.
     */
    public function printed(): string
    {
        return $this->printed;
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

    /** The last 2 KiB of what it printed, as text a terminal shows safely. */
    public function tail(): string
    {
        return self::last(Printable::text($this->printed), self::TAIL);
    }

    /**
     * The last of these bytes of text, from a character's first byte: a cut
     * that falls inside a character moves past it.
     */
    private static function last(string $text, int $bytes): string
    {
        $length = Bytes::length($text);
        $start = max(0, $length - $bytes);
        $tail = mb_strcut($text, $start, null, self::UTF8);

        while (Bytes::length($tail) > $bytes) {
            ++$start;
            $tail = mb_strcut($text, $start, null, self::UTF8);
        }

        return $tail;
    }
}
