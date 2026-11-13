<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Adapter\Azure\ContainerLedger;
use NightWorksIO\MutationGate\Adapter\Filesystem\FetchedCopies;
use NightWorksIO\MutationGate\Adapter\Filesystem\LocalLedgers;
use NightWorksIO\MutationGate\Adapter\GitHub\MergedLedgers;
use NightWorksIO\MutationGate\Adapter\GitHub\PassedPullRequests;
use NightWorksIO\MutationGate\Adapter\Http\PublicLedger;
use NightWorksIO\MutationGate\Adapter\Zstd\ZstdHere;
use NightWorksIO\MutationGate\Cli\Config\Chosen;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\DefaultBranch;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Proof\LedgerCodec;
use NightWorksIO\MutationGate\Core\Proof\LedgerLimits;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Port\ChangeSource;
use NightWorksIO\MutationGate\Port\CiPlan;
use NightWorksIO\MutationGate\Port\ProofStore;
use NightWorksIO\MutationGate\Port\Repository;

/**
 * The proof store a run is wired with (ADR-0010): the one the config names,
 * opened as far as it serves, kept in the local ledgers outside CI, read
 * beside the copy `fetch` wrote and written as `proofs.compression` says;
 * with each merged pull request's own ledger, where GitHub's change source
 * trusts it.
 *
 * @internal
 */
final readonly class WiredStore
{
    public function __construct(private Variables $environment, private Settings $settings)
    {
    }

    /** The repository a run reads: the change source that trusts merged pull requests, where both are GitHub's. */
    public static function repository(
        Repository $repository,
        ChangeSource $trusted,
    ): Repository {
        return $repository instanceof PassedPullRequests && $trusted instanceof PassedPullRequests
            ? $trusted
            : $repository;
    }

    /**
     * The store the config names; opened read-only, the default branch's scope alone, which is all a public URL
     * serves (ADR-0013), whether or not the CI and the repository can name that branch; an Azure
     * container told which scope is the default branch's, which its public container keeps (ADR-0028).
     */
    public function configured(
        Chosen $chosen,
        CiPlan|Invalid|CannotJudge $ci,
        Repository|Invalid|CannotJudge $repository,
    ): ProofStore|Invalid|CannotJudge {
        $store = $chosen->proofStore($this->settings->proofs()->store());
        $run = $ci instanceof CiPlan ? $ci->runOn() : $ci;
        $detected = [
            ...$run instanceof RunOn ? [$run->defaultBranch()] : [],
            ...$repository instanceof Repository ? [$repository->defaultBranch()] : [],
        ];
        $named = $this->settings->ci()->defaultBranch();
        $default = static fn(): Scope => DefaultBranch::of($named, ...$detected);

        return match (true) {
            $store instanceof PublicLedger => $store->onlyReading($default()),
            $store instanceof ContainerLedger => $store->forDefaultBranch($default()),
            default => $store,
        };
    }

    /**
     * Where a run keeps its proofs (ADR-0010): in CI, and in a
     * directory store the config names, there; elsewhere, in the local
     * ledgers over the store the config names; read beside the copy `fetch`
     * wrote, as `proofs.copies` says; each ledger written as `proofs.compression` says, and read as it was written
     * (ADR-0085).
     */
    public function kept(ProofStore|Invalid|CannotJudge $configured, Root $project): ProofStore|Invalid|CannotJudge
    {
        if (! $configured instanceof ProofStore) {
            return $configured;
        }

        $proofs = $this->settings->proofs();
        $local = ! $this->environment->inCi() && ! $proofs->keptOnDisk();
        $kept = $local ? LocalLedgers::over($configured, $project) : $configured;

        return FetchedCopies::over($kept, $proofs->copies(), $project)
            ->encoding(LedgerCodec::of($proofs->compression(), $proofs->compressionLevel(), ZstdHere::found()))
            ->limitedTo(LedgerLimits::kept($proofs->retained()));
    }

    /**
     * GitHub's change source, reading each pull request's own ledger from the proof store as
     * `ci.trustMergedPullRequests` and `ci.mergedOwnScopeProofs` allow; any other as it is.
     */
    public function trusted(
        ChangeSource|Invalid|CannotJudge $source,
        ProofStore|Invalid|CannotJudge $proofs,
    ): ChangeSource|Invalid|CannotJudge {
        $ci = $this->settings->ci();

        return $source instanceof PassedPullRequests && $proofs instanceof ProofStore && $ci->trustsMergedPullRequests()
            ? $source->trusting($proofs, $ci->mergedTrust(), $ci->mergedOwnScopeProofs())
            : $source;
    }

    /** The store, beside the ledgers of the merged pull requests the change source trusts. */
    public function merged(
        ChangeSource|Invalid|CannotJudge $trusted,
        ProofStore|Invalid|CannotJudge $kept,
    ): ProofStore|Invalid|CannotJudge {
        return $trusted instanceof PassedPullRequests && $kept instanceof ProofStore
            ? MergedLedgers::over($kept, $trusted)
            : $kept;
    }
}
