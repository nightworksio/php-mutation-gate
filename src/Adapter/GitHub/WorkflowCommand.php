<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitHub;

use function array_keys;
use function array_values;
use function implode;
use function sprintf;
use function str_replace;

/**
 * One GitHub Actions workflow command, such as `::error file=…,line=…::…`,
 * escaped as the runner reads it: `%`, line breaks, `:` and `,` in a
 * property, and `%` and line breaks in the message.
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
        return str_replace(array_keys($escapes), array_values($escapes), $text);
    }
}
