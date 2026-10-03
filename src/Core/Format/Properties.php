<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Format;

use function array_key_exists;
use function explode;
use function hexdec;
use function in_array;
use function ltrim;
use function mb_chr;
use function mb_substr;

use NightWorksIO\MutationGate\Core\NotGiven;

use function preg_match;
use function preg_replace_callback;
use function str_replace;

/**
 * A Java properties file, as `sonar-project.properties` is one: a key and a
 * value to a logical line, split from the key by `=`, `:` or white space,
 * with `#` and `!` beginning a comment, a line ending in an unescaped `\`
 * going on on the next, and the later of two equal keys winning.
 */
final readonly class Properties
{
    /** The white space a line begins with, or a key ends at. */
    private const string BLANK = " \t\f";

    /**
     * A key, of escaped characters and those that end none, its separator
     * and its value; a line with no key sets nothing.
     */
    private const string ENTRY = '/^(?<key>(?:\\\\.|[^ \t\f=:\\\\])+)[ \t\f]*[=:]?[ \t\f]*(?<value>.*)$/sD';

    /** An escape: a character's code in hexadecimal, or the character escaped. */
    private const string ESCAPE = '/\\\\(?:u(?<code>[0-9a-fA-F]{4})|(?<char>.))/s';

    /** What a comment line begins with. */
    private const array COMMENT = ['#', '!'];

    /** A line that ends in a `\` no other escapes, which goes on on the next. */
    private const string CONTINUED = '/(?<!\\\\)(?:\\\\\\\\)*\\\\$/D';

    /** @param array<string, string> $values by key */
    private function __construct(private array $values)
    {
    }

    public static function decode(string $text): self
    {
        $values = [];

        foreach (self::logicalLines($text) as $line) {
            if (preg_match(self::ENTRY, $line, $entry) === 1) {
                $values[self::unescaped($entry['key'])] = self::unescaped($entry['value']);
            }
        }

        return new self($values);
    }

    /** The value of a key; nothing where the file does not set it. */
    public function value(string $key): string|NotGiven
    {
        return array_key_exists($key, $this->values) ? $this->values[$key] : NotGiven::value();
    }

    /**
     * Each logical line that is not blank or a comment, without the white
     * space it begins with, its continued lines joined to it.
     *
     * @return list<string>
     */
    private static function logicalLines(string $text): array
    {
        $lines = [];
        $open = '';
        $continuing = false;

        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $text)) as $natural) {
            $line = ltrim($natural, self::BLANK);

            if (! $continuing && ($line === '' || in_array($line[0], self::COMMENT, strict: true))) {
                continue;
            }

            $continuing = self::continues($line);
            $open .= $continuing ? mb_substr($line, 0, -1) : $line;
            $lines = $continuing ? $lines : [...$lines, $open];
            $open = $continuing ? $open : '';
        }

        return $continuing ? [...$lines, $open] : $lines;
    }

    private static function continues(string $line): bool
    {
        return preg_match(self::CONTINUED, $line) === 1;
    }

    /** What an escaped character stands for: `\t`, `\n`, `\r` and `\f` a control character, any other itself. */
    private static function control(string $char): string
    {
        $escape = PropertyEscape::tryFrom($char);

        return $escape instanceof PropertyEscape ? $escape->character() : $char;
    }

    private static function unescaped(string $text): string
    {
        return preg_replace_callback(
            self::ESCAPE,
            static fn(array $escape): string => $escape['code'] === ''
                ? self::control($escape['char'])
                : mb_chr((int) hexdec($escape['code'])),
            $text,
        ) ?? $text;
    }
}
