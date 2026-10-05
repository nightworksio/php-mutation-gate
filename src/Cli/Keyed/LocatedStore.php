<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Keyed;

use function getenv;
use function implode;

use NightWorksIO\MutationGate\Adapter\Azure\ContainerLedger;
use NightWorksIO\MutationGate\Cli\Config\Chosen;
use NightWorksIO\MutationGate\Cli\FirstParty;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Choice;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Delivery\StoreLocation;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Registry\Origin;
use NightWorksIO\MutationGate\Core\ThisPackage;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Port\ProofStore;

use function sprintf;

/**
 * The proof store a command that holds the gate's keys uses, where its own environment locates it (ADR-0007
 * decision 5), built by the gate's own adapters alone, and keeping the default branch's ledger where the store
 * keeps that branch's: on Azure, in the public container, where one is named.
 */
final readonly class LocatedStore
{
    private const string UNKEYED = '%s names %s, whose credentials this job does not hold.';

    public function __construct(private Variables $environment, private Extensions $extensions)
    {
    }

    /** The store this process's environment locates, built by the gate's own adapters from that environment. */
    public static function online(): self
    {
        return new self(
            Variables::of(getenv()),
            new FirstParty()->extend(new Extensions(Origin::of(ThisPackage::COMPOSER))),
        );
    }

    /**
     * The store, knowing the default branch's scope; why its location is refused or builds no store; or, apart,
     * that this job does not hold its credentials.
     */
    public function forDefaultBranch(Scope $defaultBranch): ProofStore|NotWritten|CannotJudge
    {
        $chosen = StoreLocation::chosen($this->environment);

        if (! $chosen instanceof Choice) {
            return $chosen;
        }

        if (! StoreLocation::isKeyed($this->environment)) {
            return NotWritten::because(sprintf(self::UNKEYED, StoreLocation::Store->value, $chosen->use()->value()));
        }

        $store = new Chosen($this->extensions)->proofStore($chosen);

        return match (true) {
            $store instanceof ContainerLedger => $store->forDefaultBranch($defaultBranch),
            $store instanceof Invalid => CannotJudge::because($this->said($store)),
            default => $store,
        };
    }

    /** Each of a store's problems at its path, in one line. */
    private function said(Invalid $invalid): string
    {
        $lines = [];

        foreach ($invalid as $problem) {
            $lines[] = sprintf('%s: %s', $problem->path(), $problem->message());
        }

        return implode('; ', $lines);
    }
}
