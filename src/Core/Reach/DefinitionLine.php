<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Reach;

use function ltrim;
use function mb_strlen;
use function mb_substr;
use function preg_match;
use function str_starts_with;

/**
 * A line of a CI definition, outside every block scalar, as the reader that
 * leaves out its comment lines needs it: where it starts,
 * where a sibling of its value starts, and what it is to its value. It reads
 * only the YAML a definition plainly writes: keys spelt plainly or quoted, a
 * list's entries, values written whole on the line, and block scalars. Any
 * other line, among them an anchor, an alias, a tag, a merge key, a complex
 * key, a second document, a directive, a quote or a list left open, and a
 * value carried onto the next line, is unread.
 */
final readonly class DefinitionLine
{
    /** A list's entry, up to its node. */
    private const string ENTRY = '/^-(?: +|$)/u';

    /** A key spelt plainly or quoted, up to its value. */
    private const string KEY = <<<'REGEX'
        /^(?:[A-Za-z0-9_$][A-Za-z0-9_$.\/ -]*?|"[^"\\]*"|'[^']*') *:(?: +|$)/u
        REGEX;

    /** A block scalar's header: its style, its chomping and indentation indicators, and a comment. */
    private const string BLOCK = '/^[|>](?:[1-9][-+]?|[-+][1-9]?)?(?: +#.*)? *$/u';

    /**
     * A value this reader does not read: an anchor, an alias or a tag before
     * it, a block scalar's header it cannot read, a character YAML keeps, a
     * flow indicator, or an indicator of a nested node on the line.
     */
    private const string UNREAD = '/^(?:[&*!|>%@`,\]}]|[?:-](?: |$))/u';

    /** A value written in quotes or as a flow collection, which must close on its line. */
    private const string OPENED = '/^["\'[{]/u';

    /**
     * A value written whole on its line in quotes, or as a flow collection
     * that holds no quote and no other collection, with nothing but a comment
     * after it. Any other value that opens so is unread.
     */
    private const string WHOLE = <<<'REGEX'
        /^(?:"(?:[^"\\]|\\.)*"|'(?:[^']|'')*'|\[[^"'\[\]{}#]*\]|\{[^"'\[\]{}#]*\}) *(?:#.*)?$/u
        REGEX;

    private function __construct(
        private int $indent,
        private int $sibling,
        private LineRole $role,
    ) {
    }

    /** A line that holds something besides a comment. */
    public static function read(string $line): self
    {
        $rest = ltrim($line, ' ');
        $indent = mb_strlen($line) - mb_strlen($rest);
        $entry = false;

        while (preg_match(self::ENTRY, $rest, $dash) === 1) {
            $rest = mb_substr($rest, mb_strlen($dash[0]));
            $entry = true;
        }

        $keyed = preg_match(self::KEY, $rest, $key) === 1;
        $value = $keyed ? mb_substr($rest, mb_strlen($key[0])) : $rest;
        $sibling = $keyed ? mb_strlen($line) - mb_strlen($rest) : $indent;

        return new self($indent, $sibling, $keyed || $entry ? self::roleOf($value) : LineRole::Unread);
    }

    /** How many spaces indent the line. */
    public function indent(): int
    {
        return $this->indent;
    }

    public function role(): LineRole
    {
        return $this->role;
    }

    /**
     * Whether a line indented so deeply, the next that holds something,
     * could carry on this line's value: written deeper than the line, other
     * than where a sibling key starts.
     */
    public function carriedOnBy(int $indent): bool
    {
        return $this->role === LineRole::Holds && $indent > $this->indent && $indent !== $this->sibling;
    }

    private static function roleOf(string $value): LineRole
    {
        return match (true) {
            $value === '', str_starts_with($value, '#') => LineRole::Opens,
            preg_match(self::BLOCK, $value) === 1 => LineRole::StartsBlock,
            preg_match(self::UNREAD, $value) === 1 => LineRole::Unread,
            preg_match(self::OPENED, $value) === 1 && preg_match(self::WHOLE, $value) !== 1 => LineRole::Unread,
            default => LineRole::Holds,
        };
    }
}
