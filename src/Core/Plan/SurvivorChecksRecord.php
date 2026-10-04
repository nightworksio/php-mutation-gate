<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use NightWorksIO\MutationGate\Core\Analysis\SurvivorChecks;
use NightWorksIO\MutationGate\Core\Analysis\Unchecked;
use NightWorksIO\MutationGate\Core\Analysis\UncheckedSurvivor;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Mutant\MutantRecord;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\AnalysersRecord;

/**
 * What static analysis's checks of a shard's survivors came to, as the
 * shard's result holds it under `staticChecks`: `analysers`, the time each
 * analyser's checks took, as the ledger's section of that name holds it,
 * and `unchecked`, each survivor left unchecked, by why and its file. A
 * shard that checked nothing holds none of it.
 *
 * @internal the shape of a shard result's `staticChecks`
 *
 * @phpstan-import-type Written from AnalysersRecord
 */
final readonly class SurvivorChecksRecord
{
    public const string SECTION = 'staticChecks';

    private const string UNCHECKED = 'unchecked';

    private const string FILE = 'file';

    /** @return array{}|array{analysers: Written, unchecked: list<array{why: string, file: string, reason?: string}>} */
    public static function of(SurvivorChecks $checks): array
    {
        $analysers = AnalysersRecord::of($checks->histories());
        $unchecked = [];

        foreach ($checks as $survivor) {
            $reason = $survivor->reason();
            $unchecked[] = [
                ShardResultFile::WHY => $survivor->why()->value,
                self::FILE => $survivor->file()->value(),
                ...$reason instanceof NotGiven ? [] : [MutantRecord::REASON => $reason],
            ];
        }

        return $analysers === [] && $unchecked === []
            ? []
            : [AnalysersRecord::SECTION => $analysers, self::UNCHECKED => $unchecked];
    }

    /**
     * The checks a result holds, none where it holds none; a check that could
     * not run with the reason it was given, where the result keeps one.
     *
     * @throws NotInShape
     */
    public static function read(Node $section): SurvivorChecks
    {
        if (! $section->isPresent()) {
            return SurvivorChecks::none();
        }

        $checks = SurvivorChecks::none();

        foreach (AnalysersRecord::read($section->field(AnalysersRecord::SECTION)) as $history) {
            $checks = $checks->timing($history);
        }

        foreach ($section->field(self::UNCHECKED)->items() as $item) {
            $why = Unchecked::tryFrom($item->field(ShardResultFile::WHY)->text())
                ?? throw NotInShape::at($item->field(ShardResultFile::WHY)->at(), 'why a survivor was left unchecked');
            $file = Path::of($item->field(self::FILE)->text());
            $reason = $item->field(MutantRecord::REASON);
            $checks = $checks->leaving(
                $why === Unchecked::Failed && $reason->isPresent()
                    ? UncheckedSurvivor::failed($reason->text(), $file)
                    : UncheckedSurvivor::of($why, $file),
            );
        }

        return $checks;
    }
}
