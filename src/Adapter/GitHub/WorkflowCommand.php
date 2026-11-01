<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitHub;

use function implode;

use NightWorksIO\MutationGate\Core\Format\Printable;

use function sprintf;
use function strtr;

/**
 * One GitHub Actions workflow command, such as `::error file=…,line=…::…`,
 * escaped as the runner reads it: `%`, line breaks, `:` and `,` in a
 * property, and `%` and line breaks in the message. Each is printable first
 * (Printable), so no escape sequence in it reaches the log.
 *
 * @internal
 */
final readonly class WorkflowCommand
{
    private const array MESSAGE = ['%' => '%25', "\r" => '%0D', "\n" => '%0A'];

    private const array PROPERTY = ['%' => '%25', "\r" => '%0D', "\n" => '%0A', ':' => '%3A', ',' => '%2C'];

    /** @param array<string, string|int> $properties */
    public static function of(string $command, array $properties, string $message): string
    {
        $written = [];

        foreach ($properties as $name => $value) {
            $written[] = sprintf('%s=%s', $name, self::escaped(sprintf('%s', $value), self::PROPERTY));
        }

        return sprintf('::%s %s::%s', $command, implode(',', $written), self::escaped($message, self::MESSAGE));
    }

    /** @param array<string, string> $escapes */
    private static function escaped(string $text, array $escapes): string
    {
        return strtr(Printable::text($text), $escapes);
    }
}
