<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof\Key;

use function in_array;
use function is_array;
use function max;
use function mb_strtolower;

/**
 * Where a reading of a PHP file's top level has got to, one token at a time.
 * The depth counts every bracket, brace and attribute still open, so a
 * statement is at the top level only while it is zero. The opener is the
 * token that opened the statement being read, and naming says the next bare
 * name is the one a declaration declares.
 */
final readonly class Reading
{
    /** What may open a statement in a file that only declares. */
    private const array DECLARING = [
        T_NAMESPACE, T_USE, T_DECLARE, T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM,
        T_FUNCTION, T_CONST, T_FINAL, T_ABSTRACT, T_READONLY, T_ATTRIBUTE,
    ];

    /** What names the thing a declaration declares, in the name after it. */
    private const array NAMING = [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_FUNCTION, T_CONST];

    /** What opens a bracket, a brace or an attribute. */
    private const array OPENING = ['{', '(', '[', T_ATTRIBUTE, T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES];

    /** What closes one. */
    private const array CLOSING = ['}', ')', ']'];

    /** What opens a statement whose braces hold statements this reading does not read. */
    private const array ENCLOSING = [T_NAMESPACE, T_DECLARE];

    /** @param list<string> $declares every name declared so far, lower-cased */
    private function __construct(
        private int $depth,
        private bool $atStart,
        private int|string $opener,
        private bool $naming,
        private array $declares,
        private bool $refused,
    ) {
    }

    public static function start(): self
    {
        return new self(0, atStart: true, opener: '', naming: false, declares: [], refused: false);
    }

    /** Whether the file does something at the top level, so reading it stopped. */
    public function refused(): bool
    {
        return $this->refused;
    }

    /** @return list<string> */
    public function declares(): array
    {
        return $this->declares;
    }

    /**
     * The reading once this token is read.
     *
     * @param array{0: int, 1: string, 2: int}|string $token
     */
    public function then(array|string $token): self
    {
        $kind = is_array($token) ? $token[0] : $token;
        $opens = $this->depth === 0 && $this->atStart;

        if ($opens && ! in_array($kind, self::DECLARING, strict: true)) {
            return $this->refusing();
        }

        $read = $opens ? $this->opening($kind) : $this;

        return $read->named($kind, is_array($token) ? $token[1] : $token)->nested($kind);
    }

    /** A new statement at the top level, opened by this token. */
    private function opening(int|string $kind): self
    {
        return new self(0, atStart: false, opener: $kind, naming: false, declares: $this->declares, refused: false);
    }

    /** The statement at the top level ended, so the next token opens another. */
    private function ended(): self
    {
        return new self(
            0,
            atStart: true,
            opener: $this->opener,
            naming: false,
            declares: $this->declares,
            refused: false,
        );
    }

    private function refusing(): self
    {
        return new self($this->depth, $this->atStart, $this->opener, $this->naming, $this->declares, refused: true);
    }

    private function named(int|string $kind, string $text): self
    {
        if ($this->depth !== 0) {
            return $this;
        }

        if ($this->naming && $kind === T_STRING) {
            return new self(
                0,
                $this->atStart,
                $this->opener,
                naming: false,
                declares: [...$this->declares, mb_strtolower($text)],
                refused: false,
            );
        }

        $names = in_array($kind, self::NAMING, strict: true) || ($kind === ',' && $this->opener === T_CONST);

        return new self(0, $this->atStart, $this->opener, $names || $this->naming, $this->declares, refused: false);
    }

    private function nested(int|string $kind): self
    {
        if (in_array($kind, self::OPENING, strict: true)) {
            return $this->opened($kind);
        }

        if (in_array($kind, self::CLOSING, strict: true)) {
            return $this->closed($kind);
        }

        return $kind === ';' && $this->depth === 0 ? $this->ended() : $this;
    }

    private function opened(int|string $kind): self
    {
        return $kind === '{' && $this->depth === 0 && in_array($this->opener, self::ENCLOSING, strict: true)
            ? $this->refusing()
            : new self($this->depth + 1, $this->atStart, $this->opener, $this->naming, $this->declares, refused: false);
    }

    private function closed(int|string $kind): self
    {
        $depth = max(0, $this->depth - 1);

        return new self(
            $depth,
            $depth === 0 && $this->ends($kind),
            $this->opener,
            $this->naming,
            $this->declares,
            refused: false,
        );
    }

    /** Whether closing this bracket at the top level ends the statement it is in. */
    private function ends(int|string $kind): bool
    {
        return ($kind === '}' && $this->opener !== T_USE) || ($kind === ']' && $this->opener === T_ATTRIBUTE);
    }
}
