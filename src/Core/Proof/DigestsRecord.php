<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use stdClass;

/**
 * The digests of inputs as the gate's files write them, each kind under its
 * name: a run's in the plan, with every unit's source by its path, and a
 * proof's in the ledger, with its unit's source and each killing test file.
 *
 * @internal the shape of the plan and ledger files
 *
 * @phpstan-type ByPathWritten array<string, string>|stdClass
 * @phpstan-type RunWritten array{mutation: string, sources: ByPathWritten, tests: ByPathWritten}
 * @phpstan-type ProofWritten array{source: string, mutation: string, tests: ByPathWritten}
 */
final readonly class DigestsRecord
{
    /** Where a plan or a proof keeps its digests. */
    public const string FIELD = 'digests';

    /** Where a run's digests keep every unit's source, by its path. */
    private const string SOURCES = 'sources';

    /** @return RunWritten */
    public static function ofRun(Digests $digests): array
    {
        return [
            DigestKind::Mutation->value => $digests->mutation()->value(),
            self::SOURCES => self::byPath($digests->sources()),
            DigestKind::Test->value => self::byPath($digests->tests()),
        ];
    }

    /** @throws NotInShape */
    public static function readRun(Node $record): Digests
    {
        $digests = Digests::of(self::digestIn($record->field(DigestKind::Mutation->value)));

        foreach ($record->field(self::SOURCES)->entries() as $unit => $digest) {
            $digests = $digests->withSource(Path::of($unit), self::digestIn($digest));
        }

        foreach ($record->field(DigestKind::Test->value)->entries() as $file => $digest) {
            $digests = $digests->withTest(Path::of($file), self::digestIn($digest));
        }

        return $digests;
    }

    /** @return ProofWritten */
    public static function ofProof(Inputs $inputs): array
    {
        return [
            DigestKind::Source->value => $inputs->source()->value(),
            DigestKind::Mutation->value => $inputs->mutation()->value(),
            DigestKind::Test->value => self::byPath($inputs->tests()),
        ];
    }

    /** @throws NotInShape */
    public static function readProof(Node $record): Inputs
    {
        $inputs = Inputs::of(
            self::digestIn($record->field(DigestKind::Source->value)),
            self::digestIn($record->field(DigestKind::Mutation->value)),
        );

        foreach ($record->field(DigestKind::Test->value)->entries() as $file => $digest) {
            $inputs = $inputs->withTest(Path::of($file), self::digestIn($digest));
        }

        return $inputs;
    }

    /**
     * @param  ByPath<Digest> $digests
     * @return ByPathWritten
     */
    private static function byPath(ByPath $digests): array|stdClass
    {
        $written = [];

        foreach ($digests as $path => $digest) {
            $written[$path->value()] = $digest->value();
        }

        return $written === [] ? new stdClass() : $written;
    }

    /** @throws NotInShape */
    private static function digestIn(Node $digest): Digest
    {
        return Digest::isSha256($digest->text())
            ? Digest::of($digest->text())
            : throw NotInShape::at($digest->at(), 'a digest');
    }
}
