<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Owner;

use function mb_strtolower;

use NightWorksIO\MutationGate\Core\NotGiven;

use function preg_match;
use function str_contains;

/**
 * One owner a CODEOWNERS rule names, as GitHub spells it: `@user`,
 * `@org/team` or an email address (ADR-0022).
 */
final readonly class Owner
{
    /** A user, or an organisation's team. */
    private const string HANDLE = '#^@[A-Za-z0-9][A-Za-z0-9-]*(?:/[A-Za-z0-9][A-Za-z0-9_.-]*)?$#';

    /** An email address: something, an at sign, and a domain with a dot. */
    private const string EMAIL = '#^[^@\s]+@[^@\s]+\.[^@\s]+$#u';

    private function __construct(private string $value)
    {
    }

    /** An owner as a rule writes it; not given where it is none of the three spellings. */
    public static function parse(string $token): self|NotGiven
    {
        return preg_match(self::HANDLE, $token) === 1 || preg_match(self::EMAIL, $token) === 1
            ? new self($token)
            : NotGiven::value();
    }

    /** Whether another spelling names this owner: the same in any case, as GitHub matches a handle or an address. */
    public function is(self $other): bool
    {
        return mb_strtolower($this->value) === mb_strtolower($other->value);
    }

    /** Whether the owner is a user or a team, `@user` or `@org/team` exactly, which GitHub mentions. */
    public function isHandle(): bool
    {
        return preg_match(self::HANDLE, $this->value) === 1;
    }

    /** Whether the owner is an organisation's team, `@org/team`. */
    public function isTeam(): bool
    {
        return $this->isHandle() && str_contains($this->value, '/');
    }

    /** The owner as the rule writes it: `@acme/payments`. */
    public function value(): string
    {
        return $this->value;
    }
}
