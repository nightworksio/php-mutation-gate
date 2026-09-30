<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\Change\Commit;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;

use function sprintf;

use stdClass;

/**
 * The digests of inputs as the gate's files write them, each kind under its
 * name: a run's in the plan, with every unit's source by its path, and a
 * proof's in the ledger, with its unit's source and each killing test file,
 * all but the source as indices into the ledger's {@see InputsTable}. Each
 * has the commit its digests were taken at, where they stand for one.
 *
 * @internal the shape of the plan and ledger files
 *
 * @phpstan-type ByPathWritten array<string, string>|stdClass
 * @phpstan-type CommitWritten array{commit?: string}
 * @phpstan-type RunWritten array{mutation: string, sources: ByPathWritten, tests: ByPathWritten, commit?: string}
 * @phpstan-type ProofWritten array{source: string, mutation: int, tests: list<int>, commit?: int}
 */
final readonly class DigestsRecord
{
    /** Where a plan or a proof keeps its digests. */
    public const string FIELD = 'digests';

    /** Where a run's digests keep every unit's source, by its path. */
    private const string SOURCES = 'sources';

    /** Where digests keep the commit they were taken at. */
    private const string COMMIT = 'commit';

    /** @return RunWritten */
    public static function ofRun(Digests $digests): array
    {
        return [
            DigestKind::Mutation->value => $digests->mutation()->value(),
            self::SOURCES => self::byPath($digests->sources()),
            DigestKind::Test->value => self::byPath($digests->tests()),
            ...self::commit($digests->commit()),
        ];
    }

    /** @throws NotInShape */
    public static function readRun(Node $record): Digests
    {
        $digests = Digests::of(self::digestIn($record->field(DigestKind::Mutation->value)));

        foreach ($record->field(self::SOURCES)->entries() as $unit => $digest) {
            $digests = $digests->withSource(Path::of(sprintf('%s', $unit)), self::digestIn($digest));
        }

        foreach ($record->field(DigestKind::Test->value)->entries() as $file => $digest) {
            $digests = $digests->withTest(Path::of(sprintf('%s', $file)), self::digestIn($digest));
        }

        $commit = self::commitIn($record);

        return $commit instanceof Revision ? $digests->takenAt($commit) : $digests;
    }

    /** @return ProofWritten the digests a proof records, those it shares with others as indices into the table */
    public static function ofProof(Inputs $inputs, InputsTable $table): array
    {
        $tests = [];

        foreach ($inputs->tests() as $file => $digest) {
            $tests[] = $table->test($file, $digest);
        }

        $commit = $inputs->commit();

        return [
            DigestKind::Source->value => $inputs->source()->value(),
            DigestKind::Mutation->value => $table->mutation($inputs->mutation()),
            DigestKind::Test->value => $tests,
            ...$commit instanceof Revision ? [self::COMMIT => $table->commit($commit)] : [],
        ];
    }

    /** @throws NotInShape */
    public static function readProof(Node $record, InputsTable $table): Inputs
    {
        $inputs = Inputs::of(
            self::digestIn($record->field(DigestKind::Source->value)),
            $table->mutationAt($record->field(DigestKind::Mutation->value)),
        );

        foreach ($record->field(DigestKind::Test->value)->items() as $index) {
            [$file, $digest] = $table->testAt($index);
            $inputs = $inputs->withTest($file, $digest);
        }

        $commit = $record->field(self::COMMIT);

        return $commit->isPresent() ? $inputs->takenAt($table->commitAt($commit)) : $inputs;
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

    /** @return CommitWritten the commit digests were taken at, where they stand for one */
    private static function commit(Revision|Uncommitted $commit): array
    {
        return $commit instanceof Revision ? [self::COMMIT => $commit->name()] : [];
    }

    /**
     * The commit digests were taken at, or none where they record none.
     *
     * @throws NotInShape
     */
    private static function commitIn(Node $record): Revision|Uncommitted
    {
        $field = $record->field(self::COMMIT);
        $commit = $field->isPresent() ? Commit::parse($field->text()) : Uncommitted::tree();

        return match (true) {
            $commit instanceof Commit => $commit->revision(),
            $commit instanceof Uncommitted => $commit,
            default => throw NotInShape::at($field->at(), 'a commit'),
        };
    }

    /** @throws NotInShape */
    private static function digestIn(Node $digest): Digest
    {
        return Digest::isSha256($digest->text())
            ? Digest::of($digest->text())
            : throw NotInShape::at($digest->at(), 'a digest');
    }
}
