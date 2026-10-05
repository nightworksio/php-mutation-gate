<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Opcache;

use function array_key_exists;
use function hash;
use function in_array;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\Runner\Processes;

use function sprintf;

/**
 * Which mutants are their original program (ADR-0013, decision 10): those
 * that compile to their original's optimized opcodes and declare what it
 * declares. Each original is compiled once however many of its mutants are
 * checked, and declarations are compared only for a mutant whose opcodes
 * are its original's, which few are. Opcache that dumps nothing proves none
 * of them.
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
    public static function of(string $binary, DiskPath $directory, Processes $parallel, string|false $disabled): self
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
        $programs = [];

        foreach ($pairs as [$key, $original, $mutant]) {
            $programs[$this->originalOf($original)] = $original;
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
        $checks = [];
        $undecided = [];

        foreach ($pairs as $pair) {
            [, $original, $mutant] = $pair;
            $named = $this->originalOf($original);
            $reached = ($checks[$named] ??= RuntimeChecks::in($original))->reached($mutant);
            $undecided = $reached ? $undecided : [...$undecided, $pair];
        }

        return $undecided;
    }

    /**
     * Of the pairs whose mutant compiled to its original's opcodes, the keys
     * of those whose mutant also declares what its original declares.
     *
     * @param  list<array{string, Contents, Contents}> $pairs
     * @param  array<string, Opcodes|Uncompiled>       $compiled by program
     * @return list<string>
     */
    private function declaringTheSame(array $pairs, array $compiled): array
    {
        $declared = [];
        $proven = [];

        foreach ($pairs as [$key, $original, $mutant]) {
            $named = $this->originalOf($original);

            if (! $this->sameOpcodes($compiled, $named, sprintf(self::MUTANT, $key))) {
                continue;
            }

            $before = $declared[$named] ??= Declarations::of($original);
            $after = Declarations::of($mutant);
            $proven = $before instanceof Declarations && $after instanceof Declarations && $before->same($after)
                ? [...$proven, $key]
                : $proven;
        }

        return $proven;
    }

    /** @param array<string, Opcodes|Uncompiled> $compiled by program */
    private function sameOpcodes(array $compiled, string $original, string $mutant): bool
    {
        $before = array_key_exists($original, $compiled) ? $compiled[$original] : Uncompiled::Failed;
        $after = array_key_exists($mutant, $compiled) ? $compiled[$mutant] : Uncompiled::Failed;

        return $before instanceof Opcodes && $after instanceof Opcodes && $before->same($after);
    }

    /** An original's name among the programs, one for every mutant of the same text. */
    private function originalOf(Contents $original): string
    {
        return sprintf(self::ORIGINAL, hash('sha256', $original->text()));
    }
}
