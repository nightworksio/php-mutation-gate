<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Outbreak;

use function array_key_exists;
use function hash;

use NightWorksIO\MutationGate\Adapter\Opcache\Declarations;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\NotGiven;

use function sprintf;

/**
 * Which mutants are their original program before any test runs (ADR-0130):
 * those `outbreak.so` compiles, in the PHP the tests run on, to their
 * original's op arrays, and whose declarations, printed with every body
 * taken out, are their original's (ADR-0013). Each original is compiled once
 * however many of its mutants are checked. Anything the extension does not
 * compile, or a child that does not load it, proves nothing, and the mutant
 * runs.
 *
 * @internal
 */
final readonly class ProvenBeforeRun
{
    private const string ORIGINAL = 'original:%s';

    private const string MUTANT = 'mutant:%s';

    private const string PROOF = 'outbreak.so %s compiled it to its original\'s op arrays before any test ran, and it '
        . 'declares what its original declares, so no test can fail on it (ADR-0130).';

    public function __construct(private OpArrays $compiler)
    {
    }

    /**
     * The proof of each of these mutants proven equivalent, and the digest of
     * each mutant's op arrays, by its key.
     *
     * @param list<array{string, Contents, Contents}> $pairs each mutant's key, its original and the mutant
     */
    public function proven(array $pairs): BeforeRunProofs
    {
        $programs = [];

        foreach ($pairs as [$key, $original, $mutant]) {
            $programs[$this->originalOf($original)] = $original;
            $programs[sprintf(self::MUTANT, $key)] = $mutant;
        }

        $compiled = $pairs === [] ? NotGiven::value() : $this->compiler->digests($programs);

        if ($compiled instanceof NotGiven) {
            return new BeforeRunProofs([], []);
        }

        [$digests, $version] = $compiled;
        $proof = Reason::that(sprintf(self::PROOF, $version));
        $declared = [];
        $proven = [];
        $programs = [];

        foreach ($pairs as [$key, $original, $mutant]) {
            $before = $this->originalOf($original);
            $after = sprintf(self::MUTANT, $key);
            $programs = array_key_exists($after, $digests) ? [...$programs, $key => $digests[$after]] : $programs;

            if (! array_key_exists($before, $digests) || ($digests[$after] ?? '') !== $digests[$before]) {
                continue;
            }

            $declared[$before] ??= Declarations::of($original);
            $theirs = $declared[$before];
            $its = Declarations::of($mutant);
            $proven = $theirs instanceof Declarations && $its instanceof Declarations && $theirs->same($its)
                ? [...$proven, $key => $proof]
                : $proven;
        }

        return new BeforeRunProofs($proven, $programs);
    }

    /** An original's name among the programs, one for every mutant of the same text. */
    private function originalOf(Contents $original): string
    {
        return sprintf(self::ORIGINAL, hash('sha256', $original->text()));
    }
}
