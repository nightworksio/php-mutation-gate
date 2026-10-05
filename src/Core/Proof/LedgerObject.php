<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use function array_map;
use function explode;
use function implode;

use NightWorksIO\MutationGate\Core\CannotJudge;

use function preg_split;

/**
 * Where every store keeps a scope's ledger: `<prefix>/<scope>/ledger.json.gz`
 * (ADR-0007 decision 5), and each object beside it (ADR-0023, decision 2),
 * under a directory, as a bucket's key, or as a path
 * of a public URL over the bucket, for a scope that is a branch's or a pull
 * request's ref and nothing else. In a URL each segment is percent-encoded
 * as S3 encodes a key, so the path names the object the key names and no
 * branch's name walks out of its scope.
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

    /** The key of a scope's ledger, as a bucket or a directory names it; why there is none for a ref that is none. */
    public function of(Scope $scope): string|CannotJudge
    {
        return $this->named($scope, LedgerFile::NAME);
    }

    /** The key of an object a store keeps beside a scope's ledger; why there is none for a ref that is none. */
    public function companionOf(Scope $scope, Companion $companion): string|CannotJudge
    {
        return $this->named($scope, $companion->value);
    }

    /** The key of a scope's ledger as a URL's path, each segment percent-encoded; why there is none. */
    public function path(Scope $scope): string|CannotJudge
    {
        $key = $this->of($scope);

        return $key instanceof CannotJudge ? $key : self::encoded($key);
    }

    /** A key as a URL's path, each of its segments percent-encoded. */
    public static function encoded(string $key): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', $key)));
    }

    /** The key of an object of a scope's directory, by its name; why there is none for a ref that is none. */
    private function named(Scope $scope, string $object): string|CannotJudge
    {
        $parsed = Scope::parse($scope->ref());

        return $parsed instanceof Scope
            ? implode('/', [...$this->prefix, ...explode('/', $parsed->ref()), $object])
            : $parsed;
    }
}
