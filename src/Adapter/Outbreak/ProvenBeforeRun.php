<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Outbreak;

use function array_key_exists;
use function is_string;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Mutant\BeforeRunPrograms;
use NightWorksIO\MutationGate\Core\Mutant\BeforeRunProofs;
use NightWorksIO\MutationGate\Core\Mutant\PrintsDeclarations;
use NightWorksIO\MutationGate\Core\Mutant\ProvesBeforeRun;
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
final readonly class ProvenBeforeRun implements ProvesBeforeRun
{
    private const string PROOF = <<<'SAID'
        outbreak.so %s compiled it to its original's op arrays before any test ran,
        and it declares what its original declares, so no test can fail on it (ADR-0130).
        SAID;

    public function __construct(private OpArrays $compiler, private PrintsDeclarations $printer)
    {
    }

    public function proven(array $pairs): BeforeRunProofs
    {
        $programs = [];

        foreach ($pairs as [$key, $original, $mutant]) {
            $programs[BeforeRunPrograms::original($original)] = $original;
            $programs[BeforeRunPrograms::mutant($key)] = $mutant;
        }

        $compiled = $pairs === [] ? NotGiven::value() : $this->compiler->digests($programs);

        if ($compiled instanceof NotGiven) {
            return new BeforeRunProofs([], []);
        }

        [$digests, $version] = $compiled;

        return new BeforeRunProofs(
            $this->provenOf($pairs, $digests, Reason::that(sprintf(self::PROOF, $version))),
            $this->digestsOf($pairs, $digests),
        );
    }

    public function declarations(Contents $program): string|NotGiven
    {
        return $this->printer->declarations($program);
    }

    /**
     * Each mutant's digest, by its key, where it compiled.
     *
     * @param  list<array{string, Contents, Contents}> $pairs
     * @param  array<string, string>                   $digests by program
     * @return array<string, string>
     */
    private function digestsOf(array $pairs, array $digests): array
    {
        $programs = [];

        foreach ($pairs as [$key]) {
            $after = BeforeRunPrograms::mutant($key);
            $programs = array_key_exists($after, $digests) ? [...$programs, $key => $digests[$after]] : $programs;
        }

        return $programs;
    }

    /**
     * The proof of each mutant that compiled to its original's op arrays and declares what its original declares.
     *
     * @param  list<array{string, Contents, Contents}> $pairs
     * @param  array<string, string>                   $digests by program
     * @return array<string, Reason>
     */
    private function provenOf(array $pairs, array $digests, Reason $proof): array
    {
        $declared = [];
        $proven = [];

        foreach ($pairs as [$key, $original, $mutant]) {
            $before = BeforeRunPrograms::original($original);

            if (! $this->compiledAlike($digests, $before, BeforeRunPrograms::mutant($key))) {
                continue;
            }

            $declared[$before] ??= $this->printer->declarations($original);
            $theirs = $declared[$before];
            $proven = is_string($theirs) && $theirs === $this->printer->declarations($mutant)
                ? [...$proven, $key => $proof]
                : $proven;
        }

        return $proven;
    }

    /**
     * Whether both programs compiled, to the same op arrays.
     *
     * @param array<string, string> $digests by program
     */
    private function compiledAlike(array $digests, string $before, string $after): bool
    {
        return array_key_exists($before, $digests)
            && array_key_exists($after, $digests)
            && $digests[$before] === $digests[$after];
    }
}
