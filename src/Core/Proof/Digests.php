<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;

/**
 * The digests of a run's inputs, as its plan was made (ADR-0008, decision 1):
 * what decides every unit's mutant set besides its source, each unit's
 * source, and each test file with the support it reads, and the commit they
 * were taken at, where the working tree held nothing that commit does not. A
 * proof records its share of them, and a result of other code is judged
 * against them.
 */
final readonly class Digests
{
    /**
     * @param ByPath<Digest> $sources each unit's source digest, by its path
     * @param ByPath<Digest> $tests   each test file's digest, with what it reads, by its path
     */
    private function __construct(
        private Digest $mutation,
        private ByPath $sources,
        private ByPath $tests,
        private Revision|Uncommitted $commit,
    ) {
    }

    /**
     * A run's digests, with what decides its mutant sets besides their
     * sources, no unit or test file yet, and no commit they stand for.
     */
    public static function of(Digest $mutation): self
    {
        return new self($mutation, ByPath::none(), ByPath::none(), Uncommitted::tree());
    }

    public function withSource(Path $unit, Digest $digest): self
    {
        return clone($this, ['sources' => $this->sources->with($unit, $digest)]);
    }

    public function withTest(Path $file, Digest $digest): self
    {
        return clone($this, ['tests' => $this->tests->with($file, $digest)]);
    }

    /** These digests, taken at this commit from a working tree that held nothing it does not. */
    public function takenAt(Revision $commit): self
    {
        return clone($this, ['commit' => $commit]);
    }

    /** The commit these digests were taken at, or none where the working tree held more. */
    public function commit(): Revision|Uncommitted
    {
        return $this->commit;
    }

    /** What decides every unit's mutant set besides its source. */
    public function mutation(): Digest
    {
        return $this->mutation;
    }

    public function sourceOf(Path $unit): Digest|Missing
    {
        return $this->sources->at($unit, Missing::at($unit));
    }

    /** A test file's digest, with the support it reads. */
    public function testOf(Path $file): Digest|Missing
    {
        return $this->tests->at($file, Missing::at($file));
    }

    /** @return ByPath<Digest> each unit's source digest, by its path */
    public function sources(): ByPath
    {
        return $this->sources;
    }

    /** @return ByPath<Digest> each test file's digest, with what it reads, by its path */
    public function tests(): ByPath
    {
        return $this->tests;
    }

    /**
     * What a proof of this unit records of its inputs, with each of these
     * killing test files the run has a digest of; nothing where the unit's
     * source has none.
     */
    public function inputsOf(Path $unit, Paths $killers): Inputs|Undigested
    {
        $source = $this->sourceOf($unit);

        if (! $source instanceof Digest) {
            return Undigested::proof();
        }

        $inputs = $this->commit instanceof Revision
            ? Inputs::of($source, $this->mutation)->takenAt($this->commit)
            : Inputs::of($source, $this->mutation);

        foreach ($killers as $file) {
            $digest = $this->testOf($file);
            $inputs = $digest instanceof Digest ? $inputs->withTest($file, $digest) : $inputs;
        }

        return $inputs;
    }
}
