<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Http\Token;
use NightWorksIO\MutationGate\Core\Http\Tokens;

/** A store's tokens that are always this one, or always missing for this reason; it counts how often it is asked. */
final class FixedTokens implements Tokens
{
    public private(set) int $asked = 0;

    private function __construct(private readonly Token|CannotJudge $token)
    {
    }

    public static function of(string $token): self
    {
        return new self(Token::bearer($token));
    }

    public static function missing(string $why): self
    {
        return new self(CannotJudge::because($why));
    }

    public function token(): Token|CannotJudge
    {
        ++$this->asked;

        return $this->token;
    }
}
