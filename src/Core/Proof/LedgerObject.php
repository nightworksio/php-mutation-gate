<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use function implode;

use NightWorksIO\MutationGate\Core\CannotJudge;

use function preg_split;

/**
 * Where a store that keeps objects by key, a bucket or a public URL over one,
 * keeps a scope's ledger: `<prefix>/<scope>/ledger.json.gz` (ADR-0007
 * decision 5), for a scope that is a branch's or a pull request's ref and
 * nothing else.
 */
final readonly class LedgerObject
{
    /** @param list<string> $prefix the prefix's segments, none for the top */
    private function __construct(private array $prefix)
    {
    }

    /** Under this prefix, whose empty segments count for nothing. */
    public static function under(string $prefix): self
    {
        $segments = preg_split('#/#', $prefix, flags: PREG_SPLIT_NO_EMPTY);

        return new self($segments === false ? [] : $segments);
    }

    /** The key of a scope's ledger; why there is none for a ref that is no scope. */
    public function of(Scope $scope): string|CannotJudge
    {
        $parsed = Scope::parse($scope->ref());

        return $parsed instanceof CannotJudge
            ? $parsed
            : implode('/', [...$this->prefix, $parsed->ref(), LedgerFile::NAME]);
    }
}
