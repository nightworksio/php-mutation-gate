<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Bench\Core\Proof;

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\LedgerFile;
use NightWorksIO\MutationGate\Core\Proof\LedgerJson;
use NightWorksIO\MutationGate\Core\Proof\LedgerRetention;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Tests\Support\Moment;

use function sprintf;
use function str_repeat;

use Tally\Attribute\DataProvider;
use Tally\Attribute\Linear;
use Tally\Attribute\Warmup;

/** Writing a ledger's text and reading it back, which every run that keeps proofs does once each. */
final class LedgerJsonBench
{
    /** How many runs at the base the proofs come from. */
    private const int RUNS = 8;

    /** @return iterable<string, array{int, Ledger, string}> */
    public static function ledgers(): iterable
    {
        foreach ([100, 400, 1600] as $proofs) {
            $ledger = self::ledgerOf($proofs);

            yield sprintf('proofs-%d', $proofs) => [$proofs, $ledger, LedgerFile::encode($ledger)];
        }
    }

    #[DataProvider('ledgers')]
    #[Linear('proofs')]
    #[Warmup(1)]
    public function benchText(int $proofs, Ledger $ledger, string $encoded): void
    {
        LedgerJson::text($ledger, LedgerRetention::standard());
    }

    #[DataProvider('ledgers')]
    #[Linear('proofs')]
    #[Warmup(1)]
    public function benchDecode(int $proofs, Ledger $ledger, string $encoded): void
    {
        LedgerFile::decode($encoded);
    }

    /** A ledger of so many proofs at one base, each of a unit of its own, from the few runs at that base. */
    private static function ledgerOf(int $proofs): Ledger
    {
        $base = Digest::of(str_repeat('b', 64));
        $ledger = Ledger::empty()->atBase($base);

        for ($at = 0; $at < $proofs; $at++) {
            $unit = sprintf('src/Unit%05d.php', $at);
            $run = Run::of('main', Moment::at(sprintf('2026-09-30T10:%02d:00Z', $at % self::RUNS)), $base);
            $ledger = $ledger->withProof(Proof::of(Digest::sha256Of($unit), Path::of($unit), Mutants::none(), $run));
        }

        return $ledger;
    }
}
