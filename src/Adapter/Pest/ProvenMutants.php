<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_key_exists;
use function array_keys;
use function is_string;

use NightWorksIO\MutationGate\Core\Analysis\Checkable;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\Mutant\PrintsDeclarations;
use NightWorksIO\MutationGate\Core\Mutant\ProvesBeforeRun;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\NotGiven;

use function sprintf;

/**
 * What a hand-off proved of a patched run's planned mutants before they ran
 * (ADR-0130): each proven equivalent, and, under `mutants.duplicates:
 * judge-once`, each that compiles as a mutant made before it at the same
 * lines does, with that one's copy (ADR-0137), all by their mutated copies.
 *
 * @internal
 */
final readonly class ProvenMutants
{
    /**
     * @param array<string, Reason> $equivalent each proof of equivalence, by the mutated copy of the mutant it proved
     * @param array<string, string> $duplicates the copy of the mutant judging each duplicate, by the duplicate's copy
     */
    private function __construct(private array $equivalent, private array $duplicates)
    {
    }

    public static function none(): self
    {
        return new self([], []);
    }

    /**
     * Each planned mutant that is no twin compiled beside its original: those
     * proven equivalent, and, of the rest, where duplicates are judged once,
     * those that compile as one made before them at the same lines.
     */
    public static function among(
        Records $records,
        Project $project,
        ProvesBeforeRun $equivalence,
        bool $duplicatesOnce,
    ): self {
        $pairs = [];
        $prints = [];
        $sites = [];
        $copies = [];

        foreach ($records->planned() as $mutant) {
            $file = $mutant->file()->value();
            $prints[$file] ??= Printed::at($project, $project->relative($file));
            $checkable = Printed::served($prints[$file], $mutant);
            $original = $checkable instanceof Checkable ? $checkable->original() : NotGiven::value();

            if ($mutant->isTwin() || ! $checkable instanceof Checkable || ! $original instanceof Contents) {
                continue;
            }

            $copy = $mutant->mutated()->value();
            $pairs[] = [$copy, $original, $checkable->mutant()];
            $sites[$copy] = sprintf('%s:%d:%d', $file, $mutant->start()->number(), $mutant->end()->number());
            $copies[$copy] = $checkable->mutant();
        }

        $proofs = $equivalence->proven($pairs);
        $duplicates = $duplicatesOnce
            ? self::duplicatesAmong($equivalence, $proofs->proofs, $sites, $proofs->programs, $copies)
            : [];

        return new self($proofs->proofs, $duplicates);
    }

    /** What proved a planned mutant equivalent before it ran; nothing where nothing did. */
    public function equivalenceOf(PlannedMutant $mutant): Reason|NotGiven
    {
        $copy = $mutant->mutated()->value();

        return array_key_exists($copy, $this->equivalent) ? $this->equivalent[$copy] : NotGiven::value();
    }

    /**
     * A planned mutant as the run judges it: the twin of the mutant made
     * before it that compiles as it does, served as that one's copy, where it
     * is a duplicate; as it is otherwise.
     */
    public function judgedAs(PlannedMutant $mutant): PlannedMutant
    {
        $copy = $mutant->mutated()->value();

        return array_key_exists($copy, $this->duplicates)
            ? $mutant->asTwin()->servedAs(DiskPath::of($this->duplicates[$copy]))
            : $mutant;
    }

    /** Whether a planned mutant is decided before it runs: proven equivalent, or judged as another's twin. */
    public function decides(PlannedMutant $mutant): bool
    {
        $copy = $mutant->mutated()->value();

        return array_key_exists($copy, $this->equivalent) || array_key_exists($copy, $this->duplicates);
    }

    /**
     * The mutated copy of every mutant decided before it runs.
     *
     * @return list<string>
     */
    public function copies(): array
    {
        return [...array_keys($this->equivalent), ...array_keys($this->duplicates)];
    }

    /**
     * Of these mutants, by copy, each not proven equivalent whose program is
     * that of one made before it at the same lines: the same op arrays, and
     * the same declarations, which op arrays do not hold; and the copy of
     * that one.
     *
     * @param  array<string, Reason>   $equivalent each proof of equivalence, by the copy it proved
     * @param  array<string, string>   $sites      each mutant's file and lines, by copy, in the order Pest made them
     * @param  array<string, string>   $programs   each mutant's digest of its op arrays, by its copy
     * @param  array<string, Contents> $copies     each mutant's copy as Pest serves it, by its copy
     * @return array<string, string>
     */
    private static function duplicatesAmong(
        PrintsDeclarations $printer,
        array $equivalent,
        array $sites,
        array $programs,
        array $copies,
    ): array {
        $firsts = [];
        $duplicates = [];

        foreach ($sites as $copy => $site) {
            if (array_key_exists($copy, $equivalent) || ! array_key_exists($copy, $programs)) {
                continue;
            }

            $program = sprintf('%s %s', $site, $programs[$copy]);
            $earlier = array_key_exists($program, $firsts) ? $firsts[$program] : [];
            $same = self::declaringAlike($printer, $copies[$copy], $earlier, $copies);

            if ($same === '') {
                $firsts[$program][] = $copy;

                continue;
            }

            $duplicates[$copy] = $same;
        }

        return $duplicates;
    }

    /**
     * The first of these mutants, by copy, that declares what this copy
     * declares; none where none does.
     *
     * @param list<string>            $candidates
     * @param array<string, Contents> $copies
     */
    private static function declaringAlike(
        PrintsDeclarations $printer,
        Contents $copy,
        array $candidates,
        array $copies,
    ): string {
        $declared = $candidates === [] ? NotGiven::value() : $printer->declarations($copy);

        foreach ($candidates as $candidate) {
            if (is_string($declared) && $declared === $printer->declarations($copies[$candidate])) {
                return $candidate;
            }
        }

        return '';
    }
}
