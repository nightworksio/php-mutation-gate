<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cluster;

use function implode;
use function mb_substr;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;

use function preg_match;
use function sort;
use function sprintf;

/**
 * A cluster's id: `c` and 11 lowercase hex characters of a SHA-256 over its
 * members' ids, sorted, one to a line. The `c` keeps it from reading as a
 * mutant id, which is twelve hex characters (ADR-0022, decision 17).
 */
final readonly class ClusterId
{
    private const int HEX = 11;

    private const string SPELLING = '/^c[0-9a-f]{11}$/D';

    private const string NOT_AN_ID
        = '"%s" is not a cluster id, which is "c" and eleven lowercase hex characters, as every report prints it.';

    private function __construct(private string $value)
    {
    }

    public static function of(MutantIds $members): self
    {
        $ids = [];

        foreach ($members as $id) {
            $ids[] = $id->value();
        }

        sort($ids);

        return new self(sprintf('c%s', mb_substr(Digest::sha256Of(implode("\n", $ids))->value(), 0, self::HEX)));
    }

    /** An id as a report or a command line writes it. */
    public static function parse(string $id): self|CannotJudge
    {
        if (preg_match(self::SPELLING, $id) !== 1) {
            return CannotJudge::because(sprintf(self::NOT_AN_ID, $id));
        }

        return new self($id);
    }

    public function value(): string
    {
        return $this->value;
    }
}
