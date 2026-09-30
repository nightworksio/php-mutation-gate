<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use function array_flip;
use function array_keys;
use function array_map;
use function array_values;

use Closure;

use function count;

use NightWorksIO\MutationGate\Core\Change\Commit;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;

use function sprintf;
use function strval;

/**
 * What the digests of a ledger's proofs share, each written once in the
 * ledger's `inputs` section: every mutation digest, every killing test file
 * with its digest, and every commit digests were taken at. Each proof points
 * into it by index, so a digest many proofs of one run record costs the
 * ledger its bytes once; only the unit's own source digest is written in the
 * proof.
 *
 * Reading keeps a list only where every entry of it is well formed, since an
 * index past a dropped one would point at the wrong entry; a proof that
 * points past the end of a list is not well formed.
 *
 * @internal the shape of the ledger file
 *
 * @phpstan-type TableWritten array{mutation: list<string>, tests: list<array{string, string}>, commits: list<string>}
 */
final readonly class InputsTable
{
    /** Where a ledger keeps it. */
    public const string SECTION = 'inputs';

    private const string COMMITS = 'commits';

    /** What an index into the table is read as. */
    private const string INDEX = 'an index into the ledger\'s inputs';

    /** A test file's path and its digest as one key of the table, joined by what appears in neither. */
    private const string JOINED = "%s\0%s";

    /** @var array<string, int> each mutation digest's index, by its value */
    private array $mutationIndex;

    /** @var array<string, int> each test file and digest's index, by the two joined */
    private array $testIndex;

    /** @var array<string, int> each commit's index, by its name */
    private array $commitIndex;

    /**
     * @param list<string>                $mutations every mutation digest, each at its index
     * @param list<array{string, string}> $tests     every killing test file and its digest, each at its index
     * @param list<string>                $commits   every commit, each at its index
     */
    private function __construct(private array $mutations, private array $tests, private array $commits)
    {
        $this->mutationIndex = array_flip($mutations);
        $this->testIndex = array_flip(array_map(
            static fn(array $test): string => sprintf(self::JOINED, ...$test),
            $tests,
        ));
        $this->commitIndex = array_flip($commits);
    }

    /** What the inputs these proofs record share, each once, in the order they first record it. */
    public static function of(Proof ...$proofs): self
    {
        $mutations = [];
        $tests = [];
        $commits = [];

        foreach ($proofs as $proof) {
            $inputs = $proof->inputs();
            $commit = $inputs instanceof Inputs ? $inputs->commit() : Uncommitted::tree();
            $mutations += $inputs instanceof Inputs ? [$inputs->mutation()->value() => true] : [];
            $tests += $inputs instanceof Inputs ? self::testsOf($inputs) : [];
            $commits += $commit instanceof Revision ? [$commit->name() => true] : [];
        }

        return new self(
            array_map(strval(...), array_keys($mutations)),
            array_values($tests),
            array_map(strval(...), array_keys($commits)),
        );
    }

    /** A ledger's section, as far as it is well formed. */
    public static function read(Node $section): self
    {
        return new self(
            self::listIn($section->field(DigestKind::Mutation->value), self::digestIn(...)),
            self::listIn($section->field(DigestKind::Test->value), self::testIn(...)),
            self::listIn($section->field(self::COMMITS), self::commitIn(...)),
        );
    }

    /** @return TableWritten */
    public function written(): array
    {
        return [
            DigestKind::Mutation->value => $this->mutations,
            DigestKind::Test->value => $this->tests,
            self::COMMITS => $this->commits,
        ];
    }

    /** The index of a mutation digest the table holds. */
    public function mutation(Digest $digest): int
    {
        return $this->mutationIndex[$digest->value()];
    }

    /** The index of a killing test file and its digest the table holds. */
    public function test(Path $file, Digest $digest): int
    {
        return $this->testIndex[sprintf(self::JOINED, $file->value(), $digest->value())];
    }

    /** The index of a commit the table holds. */
    public function commit(Revision $commit): int
    {
        return $this->commitIndex[$commit->name()];
    }

    /** @throws NotInShape */
    public function mutationAt(Node $index): Digest
    {
        return Digest::of($this->mutations[$this->indexIn($index, count($this->mutations))]);
    }

    /**
     * @return array{Path, Digest}
     *
     * @throws NotInShape
     */
    public function testAt(Node $index): array
    {
        [$file, $digest] = $this->tests[$this->indexIn($index, count($this->tests))];

        return [Path::of($file), Digest::of($digest)];
    }

    /** @throws NotInShape */
    public function commitAt(Node $index): Revision
    {
        return Revision::ref($this->commits[$this->indexIn($index, count($this->commits))]);
    }

    /** @return array<string, array{string, string}> each killing test file and its digest, by the two joined */
    private static function testsOf(Inputs $inputs): array
    {
        $tests = [];

        foreach ($inputs->tests() as $file => $digest) {
            $tests[sprintf(self::JOINED, $file->value(), $digest->value())] = [$file->value(), $digest->value()];
        }

        return $tests;
    }

    /**
     * An index into a list the table holds of this many entries.
     *
     * @throws NotInShape
     */
    private function indexIn(Node $index, int $count): int
    {
        $at = $index->integer();

        return $at >= 0 && $at < $count ? $at : throw NotInShape::at($index->at(), self::INDEX);
    }

    /**
     * A list of the section, each entry as it reads; none where any is not well formed.
     *
     * @template T
     *
     * @param  Closure(Node): T $entry
     * @return list<T>
     */
    private static function listIn(Node $list, Closure $entry): array
    {
        try {
            return array_map($entry, $list->items());
        } catch (NotInShape) {
            return [];
        }
    }

    /** @throws NotInShape */
    private static function digestIn(Node $digest): string
    {
        return Digest::isSha256($digest->text()) ? $digest->text() : throw NotInShape::at($digest->at(), 'a digest');
    }

    /**
     * @return array{string, string}
     *
     * @throws NotInShape
     */
    private static function testIn(Node $test): array
    {
        $items = $test->items();

        return count($items) === 2
            ? [$items[0]->text(), self::digestIn($items[1])]
            : throw NotInShape::at($test->at(), 'a test file and its digest');
    }

    /** @throws NotInShape */
    private static function commitIn(Node $commit): string
    {
        $parsed = Commit::parse($commit->text());

        return $parsed instanceof Commit ? $parsed->id() : throw NotInShape::at($commit->at(), 'a commit');
    }
}
