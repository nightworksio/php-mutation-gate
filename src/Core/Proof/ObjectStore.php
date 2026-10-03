<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\Http\Request;
use NightWorksIO\MutationGate\Core\Http\Token;
use NightWorksIO\MutationGate\Core\Http\Tokens;

/**
 * A cloud's object API that keeps one ledger per scope, as an object a
 * bearer token reads and writes: how it names a scope's object, and the
 * requests that read and write it.
 */
interface ObjectStore extends Tokens
{
    /** Where a reader knows a scope's ledger, kept under this key, such as `gs://<bucket>/<key>`. */
    public function named(Scope $scope, string $key): string;

    /** The request that reads a scope's ledger at this percent-encoded path. */
    public function reading(Scope $scope, string $path, Token $token): Request;

    /** The request that writes these bytes as a scope's ledger at this percent-encoded path. */
    public function writing(Scope $scope, string $path, Token $token, string $bytes): Request;
}
