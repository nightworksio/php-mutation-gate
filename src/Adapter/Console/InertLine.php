<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Console;

use function end;
use function implode;

use NightWorksIO\MutationGate\Core\Format\Inert;

use function preg_split;

/**
 * The line a stream of the console has open, which a runner reads whole however many writes made it: each write is
 * made inert as it continues that line (Inert), so two writes start no command together that neither starts alone.
 */
final class InertLine
{
    /** What the open line holds so far, as it was given. */
    private string $open = '';

    /** This message as it is written, and the line it leaves open remembered. */
    public function written(string $message, bool $newline): string
    {
        $written = Inert::continuing($this->open, $message);
        $line = $written === Inert::text($message) ? implode('', [$this->open, $message]) : $message;
        $lines = preg_split(Inert::LINE_END, $line);
        $this->open = $newline || $lines === false ? '' : (string) end($lines);

        return $written;
    }
}
