<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Console;

use function is_iterable;
use function is_scalar;

use NightWorksIO\MutationGate\Core\Format\Printable;
use Stringable;

/**
 * What the console is given to write, as it writes it: each message printable (Printable) before the console
 * formats it, so the only escape sequences it writes are the colours it adds. A message that is neither scalar nor
 * Stringable is written as an empty one.
 */
final readonly class Messages
{
    /**
     * @param  string|iterable<mixed> $messages
     * @return list<string>
     */
    public static function printable(string|iterable $messages): array
    {
        $printable = [];

        foreach (is_iterable($messages) ? $messages : [$messages] as $message) {
            $text = is_scalar($message) || $message instanceof Stringable ? (string) $message : '';
            $printable[] = Printable::text($text);
        }

        return $printable;
    }
}
