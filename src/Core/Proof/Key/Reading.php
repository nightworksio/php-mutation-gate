<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof\Key;

use function in_array;
use function mb_strtolower;

use PhpToken;

/**
 * Where a reading of a PHP file's top level has got to, one token at a time.
 * The depth counts every bracket, brace and attribute still open, so a
 * statement is at the top level only while it is zero. The opener is the id
 * of the token that opened the statement being read, and naming says the next
 * bare name is the one a declaration declares.
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
        private int $opener,
        private bool $naming,
        private array $declares,
        private bool $refused,
    ) {
    }

    public static function start(): self
    {
        return new self(0, atStart: true, opener: T_OPEN_TAG, naming: false, declares: [], refused: false);
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

    /** The reading once this token is read. */
    public function then(PhpToken $token): self
    {
        $opens = $this->depth === 0 && $this->atStart;

        if ($opens && ! $token->is(self::DECLARING)) {
            return $this->refusing();
        }

        $read = $opens ? $this->opening($token->id) : $this;

        return $read->named($token)->nested($token);
    }

    /** A new statement at the top level, opened by this token. */
    private function opening(int $kind): self
    {
        return new self(
            0,
            atStart: false,
            opener: $kind,
            naming: $this->naming,
            declares: $this->declares,
            refused: $this->refused,
        );
    }

    /** The statement at the top level ended, so the next token opens another. */
    private function ended(): self
    {
        return new self(
            0,
            atStart: true,
            opener: $this->opener,
            naming: $this->naming,
            declares: $this->declares,
            refused: false,
        );
    }

    private function refusing(): self
    {
        return new self($this->depth, $this->atStart, $this->opener, $this->naming, $this->declares, refused: true);
    }

    private function named(PhpToken $token): self
    {
        if ($this->depth !== 0) {
            return $this;
        }

        if ($this->naming && $token->is(T_STRING)) {
            return new self(
                0,
                $this->atStart,
                $this->opener,
                naming: false,
                declares: [...$this->declares, mb_strtolower($token->text)],
                refused: false,
            );
        }

        $names = $token->is(self::NAMING) || ($token->is(',') && $this->opener === T_CONST);

        return new self(0, $this->atStart, $this->opener, $names || $this->naming, $this->declares, refused: false);
    }

    private function nested(PhpToken $token): self
    {
        if ($token->is(self::OPENING)) {
            return $this->opened($token);
        }

        if ($token->is(self::CLOSING)) {
            return $this->closed($token);
        }

        return $token->is(';') && $this->depth === 0 ? $this->ended() : $this;
    }

    private function opened(PhpToken $token): self
    {
        return $token->is('{') && $this->depth === 0 && in_array($this->opener, self::ENCLOSING, strict: true)
            ? $this->refusing()
            : new self($this->depth + 1, $this->atStart, $this->opener, $this->naming, $this->declares, refused: false);
    }

    private function closed(PhpToken $token): self
    {
        $depth = $this->depth - 1;

        return new self(
            $depth,
            $depth === 0 && $this->ends($token),
            $this->opener,
            $this->naming,
            $this->declares,
            refused: false,
        );
    }

    /** Whether closing this bracket at the top level ends the statement it is in. */
    private function ends(PhpToken $token): bool
    {
        return ($token->is('}') && $this->opener !== T_USE) || ($token->is(']') && $this->opener === T_ATTRIBUTE);
    }
}
