<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Assertion;

use function array_key_exists;
use function mb_strtolower;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Php\Tokens;

/**
 * The functions and methods a test may call to assert for it: those its own
 * file declares, and those the files that define the runner declare, such
 * as `tests/Pest.php`. A test that calls one is not assessed, since the
 * helper may assert what the test does not (ADR-0025, decision 5).
 */
final readonly class Helpers
{
    /** @param array<string, true> $names each name, in lower case */
    private function __construct(private array $names)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /** The named functions and methods a file declares. */
    public static function in(Contents $file): self
    {
        return self::declaredIn(Tokens::in($file));
    }

    /** The named functions and methods some tokens declare. */
    public static function declaredIn(Tokens $tokens): self
    {
        $names = [];

        foreach ($tokens->indicesOf(T_FUNCTION) as $at) {
            $named = $tokens->functionName($at);

            if ($named !== Tokens::NONE) {
                $names[mb_strtolower($tokens->text($named))] = true;
            }
        }

        return new self($names);
    }

    /** These, and those others. */
    public function and(self $others): self
    {
        return new self($this->names + $others->names);
    }

    /** Whether a function or method is among them, by its name in lower case. */
    public function has(string $name): bool
    {
        return array_key_exists($name, $this->names);
    }
}
