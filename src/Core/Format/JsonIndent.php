<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Format;

use function array_slice;
use function explode;
use function implode;
use function json_encode;
use function mb_strlen;
use function mb_strrpos;
use function mb_substr;
use function preg_match;
use function sprintf;
use function str_replace;
use function str_starts_with;

/** How a JSON file lays out what it holds: the indentation of each line, and a member written into it at a depth. */
final readonly class JsonIndent
{
    private const string NEWLINE = "\n";

    /** The blanks a line starts with. */
    private const string LEADING = '/^[ \t]*/u';

    /** The level of a file that shows none, as the gate's own JSON is written. */
    private const string STANDARD = '    ';

    private const string NESTED = "%s: {\n%s%s\n%s}";

    private function __construct(private string $unit)
    {
    }

    /**
     * The layout of this text, whose top object's first member stands at
     * this offset; the standard one where that member's line shows none.
     */
    public static function of(string $json, int $firstMember): self
    {
        $unit = self::at($json, $firstMember);

        return $unit === '' ? self::standard() : new self($unit);
    }

    /** The layout of a file that shows none, as the gate's own JSON is written. */
    public static function standard(): self
    {
        return new self(self::STANDARD);
    }

    /** The indentation of the line this character offset stands on. */
    public static function at(string $json, int $offset): string
    {
        $before = mb_substr($json, 0, $offset);
        $newline = mb_strrpos($before, self::NEWLINE);
        $line = $newline === false ? $before : mb_substr($before, $newline + 1);

        return preg_match(self::LEADING, $line, $blanks) === 1 ? $blanks[0] : '';
    }

    /** A value's text with its lines after the first indented by this much more. */
    public static function indented(string $text, string $indent): string
    {
        return str_replace(self::NEWLINE, sprintf('%s%s', self::NEWLINE, $indent), $text);
    }

    /** A value's text with this indentation taken from the start of each line after its first. */
    public static function outdented(string $text, string $indent): string
    {
        $lines = explode(self::NEWLINE, $text);
        $later = [];

        foreach (array_slice($lines, 1) as $line) {
            $later[] = str_starts_with($line, $indent) ? mb_substr($line, mb_strlen($indent)) : $line;
        }

        return implode(self::NEWLINE, [$lines[0], ...$later]);
    }

    /** One level deeper than this indentation. */
    public function deeper(string $indent): string
    {
        return sprintf('%s%s', $indent, $this->unit);
    }

    /**
     * A member under this key, written at this indentation: its value, or,
     * where further keys follow, each object they need to hold it.
     *
     * @param list<string> $further
     */
    public function member(string $key, array $further, JsonFragment $value, string $indent): string
    {
        $name = json_encode($key, JsonText::FLAGS);

        if ($further === []) {
            return sprintf('%s: %s', $name, self::indented($value->text(), $indent));
        }

        $inner = $this->deeper($indent);
        $nested = $this->member($further[0], array_slice($further, 1), $value, $inner);

        return sprintf(self::NESTED, $name, $inner, $nested, $indent);
    }
}
