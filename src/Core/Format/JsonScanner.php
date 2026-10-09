<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Format;

use function array_slice;
use function count;
use function implode;
use function is_string;
use function json_decode;
use function mb_str_split;
use function str_contains;
use function trim;

/**
 * Where each value of a JSON text stands, read from text already known to
 * be JSON, by characters: each object's members by their keys, and where
 * every value starts and ends, so an edit can change one value and leave
 * each other character as it was.
 */
final readonly class JsonScanner
{
    /** What ends a number, `true`, `false` or `null`, besides the space JSON lets stand between tokens. */
    private const string AFTER_SCALAR = ',]}';

    private const string OPEN_OBJECT = '{';

    private const string CLOSE_OBJECT = '}';

    private const string OPEN_ARRAY = '[';

    private const string CLOSE_ARRAY = ']';

    private const string QUOTE = '"';

    private const string COMMA = ',';

    /** @param list<string> $chars */
    private function __construct(private array $chars)
    {
    }

    /** The value the text holds, which must be JSON. */
    public static function scan(string $json): JsonSpan
    {
        $scanner = new self(mb_str_split($json));

        return $scanner->value($scanner->skipped(0));
    }

    private function value(int $at): JsonSpan
    {
        return match ($this->chars[$at]) {
            self::OPEN_OBJECT => $this->object($at),
            self::OPEN_ARRAY => $this->array($at),
            self::QUOTE => JsonSpan::other($at, $this->stringEnd($at)),
            default => JsonSpan::other($at, $this->scalarEnd($at)),
        };
    }

    private function object(int $start): JsonSpan
    {
        $members = [];
        $at = $this->skipped($start + 1);

        while ($this->chars[$at] !== self::CLOSE_OBJECT) {
            $keyEnd = $this->stringEnd($at);
            $value = $this->value($this->skipped($this->skipped($keyEnd) + 1));
            $key = json_decode(implode('', array_slice($this->chars, $at, $keyEnd - $at)));
            $members[] = JsonMember::of(is_string($key) ? $key : '', $at, $keyEnd, $value);
            $at = $this->next($value->end);
        }

        return JsonSpan::object($start, $at + 1, ...$members);
    }

    private function array(int $start): JsonSpan
    {
        $at = $this->skipped($start + 1);

        while ($this->chars[$at] !== self::CLOSE_ARRAY) {
            $at = $this->next($this->value($at)->end);
        }

        return JsonSpan::other($start, $at + 1);
    }

    /** Where the next member or item starts after a value that ends here, or the bracket that closes them. */
    private function next(int $end): int
    {
        $at = $this->skipped($end);

        return $this->chars[$at] === self::COMMA ? $this->skipped($at + 1) : $at;
    }

    /** Past the closing quote of the string that opens here. */
    private function stringEnd(int $start): int
    {
        $at = $start + 1;

        while ($this->chars[$at] !== self::QUOTE) {
            $at += $this->chars[$at] === '\\' ? 2 : 1;
        }

        return $at + 1;
    }

    /** Past the last character of the number, `true`, `false` or `null` that starts here. */
    private function scalarEnd(int $start): int
    {
        $at = $start;

        while ($at < count($this->chars) && ! $this->isSpace($at) && ! $this->endsScalar($at)) {
            $at++;
        }

        return $at;
    }

    private function skipped(int $at): int
    {
        while ($this->isSpace($at)) {
            $at++;
        }

        return $at;
    }

    /** Whether the character here is a comma or a closing bracket. */
    private function endsScalar(int $at): bool
    {
        return str_contains(self::AFTER_SCALAR, $this->chars[$at]);
    }

    /** Whether the character here is space JSON lets stand between tokens. */
    private function isSpace(int $at): bool
    {
        return trim($this->chars[$at]) === '';
    }
}
