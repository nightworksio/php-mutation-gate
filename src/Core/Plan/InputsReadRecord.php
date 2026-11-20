<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Proof\JudgingRunRecord;
use NightWorksIO\MutationGate\Core\Proof\PassedRecord;
use NightWorksIO\MutationGate\Core\Proof\RunProfileRecord;

/**
 * What a plan file says its run reads, at its top level: `inputs`, the
 * digest of every input, where the plan read them (ADR-0198), and
 * `ownScopeCoverage`, true only where its coverage map was measured against
 * the map its own scope keeps (ADR-0023).
 *
 * @internal the shape of the plan file
 *
 * @phpstan-type Written array{inputs?: string, ownScopeCoverage?: true}
 */
final readonly class InputsReadRecord
{
    /** @return Written */
    public static function of(InputsRead $read): array
    {
        $digest = $read->digest();

        return [
            ...$digest instanceof Digest ? [JudgingRunRecord::INPUTS => $digest->value()] : [],
            ...$read->isOnOwnScopeCoverage() ? [PassedRecord::OWN_SCOPE_COVERAGE => true] : [],
        ];
    }

    /**
     * What a plan file says its run reads.
     *
     * @throws NotInShape where a field it holds is not so shaped
     */
    public static function read(Node $file): InputsRead
    {
        $digest = $file->field(JudgingRunRecord::INPUTS);
        $read = match (true) {
            ! $digest->isPresent() => InputsRead::none(),
            Digest::isSha256($digest->text()) => InputsRead::none()->digested(Digest::of($digest->text())),
            default => throw NotInShape::at($digest->at(), 'a digest'),
        };

        return RunProfileRecord::isTrue($file->field(PassedRecord::OWN_SCOPE_COVERAGE))
            ? $read->onOwnScopeCoverage()
            : $read;
    }
}
