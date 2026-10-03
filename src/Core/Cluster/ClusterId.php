<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cluster;

use function implode;
use function mb_substr;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Mutant\IdPrefix;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;

use function preg_match;
use function sort;
use function sprintf;
use function str_starts_with;

/**
 * A cluster's id: `k` and 11 lowercase hex characters of a SHA-256 over its
 * members' ids, sorted, one to a line. The `k` is no hex digit, so a cluster's
 * id never reads as a mutant's id, nor as a prefix of one (ADR-0022, decision
 * 17).
 */
final readonly class ClusterId
{
    /** What every cluster id begins with, and no mutant id does. */
    public const string PREFIX = 'k';

    /** Every cluster id, as a JSON Schema pattern writes it: the prefix, then the hex characters. */
    public const string PATTERN = '^k[0-9a-f]{11}$';

    private const int HEX = 11;

    private const string NOT_AN_ID
        = '"%s" is not a cluster id, which is "k" and eleven lowercase hex characters, as every report prints it.';

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

        return new self(sprintf(
            '%s%s',
            self::PREFIX,
            mb_substr(Digest::sha256Of(implode("\n", $ids))->value(), 0, self::HEX),
        ));
    }

    /** An id as a report or a command line writes it. */
    public static function parse(string $id): self|CannotJudge
    {
        if (preg_match(sprintf('/%s/D', self::PATTERN), $id) !== 1) {
            return CannotJudge::because(sprintf(self::NOT_AN_ID, $id));
        }

        return new self($id);
    }

    /** What an id a command line writes names: a cluster, where it begins as a cluster id does; else a mutant. */
    public static function orMutant(string $id): self|IdPrefix|CannotJudge
    {
        return str_starts_with($id, self::PREFIX) ? self::parse($id) : IdPrefix::parse($id);
    }

    public function value(): string
    {
        return $this->value;
    }
}
