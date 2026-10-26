<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Opcache;

use function array_key_exists;
use function array_map;
use function hash;
use function in_array;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;

use function sprintf;

/**
 * Which mutants are their original program (ADR-0013): those
 * that compile to their original's optimized opcodes and declare what it
 * declares, and, where the opcodes print a value only in part, write the
 * constant expressions it writes. Each original is compiled once however
 * many of its mutants are checked, and declarations are compared only for a
 * mutant whose opcodes are its original's, which few are. Opcache that
 * dumps nothing proves none of them.
 */
final readonly class Prover
{
    private const string ORIGINAL = 'original:%s';

    private const string MUTANT = 'mutant:%s';

    /** How long one child may take to compile its programs, in seconds; one that takes longer proves none. */
    private const float CHILD_LIMIT = 60.0;

    /** The setting that disables in a child what the tests' PHP disables. */
    private const string DISABLED = 'disable_functions=%s';

    public function __construct(private Compiler $compiler)
    {
    }

    /**
     * The prover that compiles with this PHP, in this directory, which the gate owns, this many children at once,
     * with the functions the PHP the tests ran on disables, as its `disable_functions` says them, disabled too.
     */
    public static function of(string $binary, DiskPath $directory, ProcessCount $parallel, string|false $disabled): self
    {
        $besides = [sprintf(self::DISABLED, $disabled)];

        return new self(new Compiler($binary, $directory->value(), self::CHILD_LIMIT, $parallel->count(), $besides));
    }

    /**
     * The keys of the mutants proven equivalent, of these pairs of an
     * original and its mutant; or that opcache dumped nothing.
     *
     * @param  list<array{string, Contents, Contents}> $pairs each mutant's key, its original and the mutant
     * @return list<string>|Uncompiled
     */
    public function proven(array $pairs): array|Uncompiled
    {
        $pairs = $this->undecided($pairs);
        $programs = $this->originals($pairs);

        foreach ($pairs as [$key, , $mutant]) {
            $programs[sprintf(self::MUTANT, $key)] = $mutant;
        }

        $compiled = $this->compiler->compiled($programs);

        return in_array(Uncompiled::NoOpcache, $compiled, strict: true)
            ? Uncompiled::NoOpcache
            : $this->declaringTheSame($pairs, $compiled);
    }

    /**
     * The pairs whose mutant changes nothing opcache decides by the PHP it
     * compiles on, which can differ from the one the tests ran on.
     *
     * @param  list<array{string, Contents, Contents}> $pairs
     * @return list<array{string, Contents, Contents}>
     */
    private function undecided(array $pairs): array
    {
        $checks = array_map(RuntimeChecks::in(...), $this->originals($pairs));
        $undecided = [];

        foreach ($pairs as $pair) {
            [, $original, $mutant] = $pair;
            $undecided = $checks[$this->originalOf($original)]->reached($mutant) ? $undecided : [...$undecided, $pair];
        }

        return $undecided;
    }

    /**
     * Of the pairs whose mutant compiled to its original's opcodes, the keys
     * of those whose mutant also declares what its original declares, and,
     * where the opcodes print a value only in part, writes the constant
     * expressions its original writes.
     *
     * @param  list<array{string, Contents, Contents}> $pairs
     * @param  array<string, Opcodes|Uncompiled>       $compiled by program
     * @return list<string>
     */
    private function declaringTheSame(array $pairs, array $compiled): array
    {
        $same = [];
        $hiding = [];

        foreach ($pairs as $pair) {
            [$key, $original] = $pair;
            $opcodes = $this->sharedOpcodes($compiled, $this->originalOf($original), sprintf(self::MUTANT, $key));

            if ($opcodes instanceof Opcodes) {
                $same[] = $pair;
                $hiding[$key] = $opcodes->hidesValues();
            }
        }

        $declared = array_map(Declarations::of(...), $this->originals($same));
        $proven = [];

        foreach ($same as [$key, $original, $mutant]) {
            $before = $declared[$this->originalOf($original)];
            $after = Declarations::of($mutant);
            $proven = $before instanceof Declarations && $after instanceof Declarations && $before->same($after)
                && (! $hiding[$key] || $before->writesTheSameConstants($after))
                ? [...$proven, $key]
                : $proven;
        }

        return $proven;
    }

    /**
     * Each original of these pairs once, by its name among the programs.
     *
     * @param  list<array{string, Contents, Contents}> $pairs
     * @return array<string, Contents>
     */
    private function originals(array $pairs): array
    {
        $originals = [];

        foreach ($pairs as [, $original]) {
            $originals[$this->originalOf($original)] = $original;
        }

        return $originals;
    }

    /**
     * The opcodes an original and its mutant both compiled to; none where they differ or either did not compile.
     *
     * @param array<string, Opcodes|Uncompiled> $compiled by program
     */
    private function sharedOpcodes(array $compiled, string $original, string $mutant): Opcodes|NotGiven
    {
        $before = array_key_exists($original, $compiled) ? $compiled[$original] : Uncompiled::Failed;
        $after = array_key_exists($mutant, $compiled) ? $compiled[$mutant] : Uncompiled::Failed;

        return $before instanceof Opcodes && $after instanceof Opcodes && $before->same($after)
            ? $before
            : NotGiven::value();
    }

    /** An original's name among the programs, one for every mutant of the same text. */
    private function originalOf(Contents $original): string
    {
        return sprintf(self::ORIGINAL, hash('sha256', $original->text()));
    }
}
