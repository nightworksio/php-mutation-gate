<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Owner;

use NightWorksIO\MutationGate\Core\NotGiven;

use function preg_match;

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

    /** The owner as the rule writes it: `@acme/payments`. */
    public function value(): string
    {
        return $this->value;
    }
}
