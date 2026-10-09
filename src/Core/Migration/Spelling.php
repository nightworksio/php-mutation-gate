<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Migration;

use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * The builder call a step retires from the PHP config, and the one that
 * replaces it with the same arguments, where one does (ADR-0026, decision 2).
 */
final readonly class Spelling
{
    private function __construct(private BuilderCall $retired, private BuilderCall|NotGiven $replacement)
    {
    }

    /** A call another replaces, with the same arguments: `Reach::everything` by `Reach::all`. */
    public static function replacing(string $retired, string $replacement): self
    {
        return new self(BuilderCall::of($retired), BuilderCall::of($replacement));
    }

    /** A call nothing replaces. */
    public static function retiring(string $retired): self
    {
        return new self(BuilderCall::of($retired), NotGiven::value());
    }

    public function retired(): BuilderCall
    {
        return $this->retired;
    }

    public function replacement(): BuilderCall|NotGiven
    {
        return $this->replacement;
    }
}
