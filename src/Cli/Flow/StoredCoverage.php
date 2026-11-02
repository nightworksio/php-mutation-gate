<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function count;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\KeptMap;
use NightWorksIO\MutationGate\Core\Coverage\MapLimits;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Proof\Access;
use NightWorksIO\MutationGate\Core\Proof\Companion;
use NightWorksIO\MutationGate\Port\ProofStore;

/**
 * The coverage map a store keeps for a run, or why there is none to read, and the scope it was read from.
 *
 * @internal
 */
final readonly class StoredCoverage
{
    private function __construct(private KeptMap|Missing|CannotJudge $map, private KeptFrom $from)
    {
    }

    public static function of(KeptMap|Missing|CannotJudge $map, KeptFrom $from): self
    {
        return new self($map, $from);
    }

    /**
     * The map kept beside a ledger the run reads, its own scope's first and
     * then the default branch's, where its own scope's cannot be read; none;
     * or why the default branch's cannot be read.
     */
    public static function in(ProofStore $store, Access $access): self
    {
        $scopes = [...$access->reads()];
        $read = Missing::at(Path::of(Companion::Coverage->value));

        foreach ($scopes as $at => $scope) {
            $bytes = $store->companion($scope, Companion::Coverage);
            $read = $bytes instanceof Contents ? CoverageMapFile::kept($bytes->text(), MapLimits::standard()) : $bytes;
            $from = $at === count($scopes) - 1 ? KeptFrom::DefaultBranch : KeptFrom::OwnScope;

            if ($read instanceof KeptMap) {
                return self::of($read, $from);
            }
        }

        return self::of($read, KeptFrom::DefaultBranch);
    }

    public function map(): KeptMap|Missing|CannotJudge
    {
        return $this->map;
    }

    public function from(): KeptFrom
    {
        return $this->from;
    }
}
