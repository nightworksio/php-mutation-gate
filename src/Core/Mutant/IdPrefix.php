<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

use NightWorksIO\MutationGate\Core\CannotJudge;

use function preg_match;
use function sprintf;
use function str_starts_with;

/**
 * A mutant's id as a command line takes it: all twelve hex characters, or
 * the first six or more of them (ADR-0014 decision 14).
 */
final readonly class IdPrefix
{
    private const string SPELLING = '/^[0-9a-f]{6,12}$/D';

    private const string MISSPELLED = <<<'SAID'
        "%s" is not a mutant id. Give the twelve lowercase hex characters every report prints, or the first six or more.
        SAID;

    private function __construct(private string $value)
    {
    }

    public static function parse(string $written): self|CannotJudge
    {
        return preg_match(self::SPELLING, $written) === 1
            ? new self($written)
            : CannotJudge::because(sprintf(self::MISSPELLED, $written));
    }

    /** Whether an id starts with this prefix, as a whole id starts with itself. */
    public function names(MutantId $id): bool
    {
        return str_starts_with($id->value(), $this->value);
    }

    public function value(): string
    {
        return $this->value;
    }
}
