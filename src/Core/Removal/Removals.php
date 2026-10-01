<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Removal;

use function array_all;
use function array_key_exists;
use function count;
use function in_array;

use NightWorksIO\MutationGate\Core\Assertion\TestFiles;
use NightWorksIO\MutationGate\Core\Assertion\Weakness;
use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Hint\Change;
use NightWorksIO\MutationGate\Core\Matrix\KillMatrix;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Php\Declarations;
use NightWorksIO\MutationGate\Core\Php\Declared;
use NightWorksIO\MutationGate\Core\Php\Nameless;
use NightWorksIO\MutationGate\Core\Php\Source;
use NightWorksIO\MutationGate\Core\Verdict\Findings;
use NightWorksIO\MutationGate\Core\Verdict\JudgedKill;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;

/**
 * The surviving removals that suggest deleting what they call, where four
 * facts hold together (ADR-0025, decision 11):
 *
 * - a Removed-call mutant survived, so it is covered;
 * - it removed a whole statement that is one call, whose callee its file's
 *   class, imports or namespace lead to among the survivors' own files;
 * - the callee's body holds another mutant, and every other one survived,
 *   but those proven equivalent or ignored, which say nothing either way;
 * - at least one test judges it, and none of those is weak.
 */
final readonly class Removals
{
    /**
     * What each such removal says of its callee, from the survivors' own
     * files and the test files the verdict read.
     *
     * @param ByPath<Contents> $sources the survivors' own files
     */
    public static function findings(
        TreeVerdicts $verdicts,
        KillMatrix $matrix,
        ByPath $sources,
        TestFiles $tests,
    ): Findings {
        $removals = self::removals($verdicts);

        if ($removals === []) {
            return Findings::none();
        }

        $read = self::read($sources);
        $declarations = Declarations::of(...$read);
        $byFile = self::byFile($verdicts);
        $findings = Findings::none();

        foreach ($removals as $removal) {
            $callee = self::calleeOf($removal, $declarations, $read);
            $removable = $callee instanceof Declared
                && self::isPseudoTested($callee, $removal, $byFile)
                && self::assertsOtherThings($removal, $matrix, $tests);
            $findings = $removable ? $findings->with($removal->mutant()->id(), Removable::callee($callee)) : $findings;
        }

        return $findings;
    }

    /** @return list<JudgedMutant> the Removed-call mutants that survived */
    private static function removals(TreeVerdicts $verdicts): array
    {
        $removals = [];

        foreach ($verdicts as $tree) {
            foreach ($tree->survivors() as $survivor) {
                if (
                    $survivor->judgement() === MutantJudgement::Survived
                    && $survivor->mutant()->mutation()->family() === MutatorFamily::RemovedCall
                ) {
                    $removals[] = $survivor;
                }
            }
        }

        return $removals;
    }

    /**
     * Each of the survivors' own files, read for what it declares.
     *
     * @param  ByPath<Contents>      $sources
     * @return array<string, Source> by path
     */
    private static function read(ByPath $sources): array
    {
        $read = [];

        foreach ($sources as $path => $contents) {
            $read[$path->value()] = Source::read($path, $contents, test: false);
        }

        return $read;
    }

    /**
     * Every mutant of the verdict, by its file.
     *
     * @return array<string, list<JudgedMutant|JudgedKill>>
     */
    private static function byFile(TreeVerdicts $verdicts): array
    {
        $byFile = [];

        foreach ($verdicts->mutants() as $judged) {
            $byFile[$judged->mutant()->location()->file()->value()][] = $judged;
        }

        return $byFile;
    }

    /**
     * The function or method a removal's whole statement calls, where it is
     * one call its file leads to a declaration of.
     *
     * @param array<string, Source> $read
     */
    private static function calleeOf(JudgedMutant $removal, Declarations $declarations, array $read): Declared|Nameless
    {
        $change = Change::of($removal->mutant()->mutation()->diff());
        $statement = $change->added() === '' ? CallStatement::read($change->removedTokens()) : Nameless::code();
        $location = $removal->mutant()->location();
        $file = $location->file()->value();

        if ($statement instanceof Nameless || ! array_key_exists($file, $read)) {
            return Nameless::code();
        }

        $source = $read[$file];
        $at = self::firstTokenOn($source, $location->start()->number());
        $callee = $statement->calleeIn($declarations, $source, $at);

        return $callee instanceof Declared ? $callee : Nameless::code();
    }

    /** Where the first token of a line stands among a source's tokens, or past the last where none does. */
    private static function firstTokenOn(Source $source, int $line): int
    {
        $tokens = $source->tokens();
        $at = 0;

        while ($at < $tokens->count() && $tokens->line($at) < $line) {
            ++$at;
        }

        return $at;
    }

    /**
     * Whether the callee's body holds another mutant, and every other one
     * survived, those proven equivalent or ignored aside.
     *
     * @param array<string, list<JudgedMutant|JudgedKill>> $byFile
     */
    private static function isPseudoTested(Declared $callee, JudgedMutant $removal, array $byFile): bool
    {
        $judgements = self::othersIn($callee, $removal, $byFile);

        return in_array(MutantJudgement::Survived, $judgements, strict: true) && array_all(
            $judgements,
            static fn(MutantJudgement $judgement): bool => $judgement === MutantJudgement::Survived
                || self::saysNothing($judgement),
        );
    }

    /**
     * The judgement of every other mutant in the callee's body.
     *
     * @param  array<string, list<JudgedMutant|JudgedKill>> $byFile
     * @return list<MutantJudgement>
     */
    private static function othersIn(Declared $callee, JudgedMutant $removal, array $byFile): array
    {
        $file = $callee->file()->value();
        $others = [];

        foreach (array_key_exists($file, $byFile) ? $byFile[$file] : [] as $other) {
            $mutant = $other->mutant();

            $itself = $mutant->id()->value() === $removal->mutant()->id()->value();

            if ($callee->holds($mutant->location()->start()) && ! $itself) {
                $others[] = $other->judgement();
            }
        }

        return $others;
    }

    /** Whether a judgement says nothing of the tests either way: proven equivalent, or left out by an ignore. */
    private static function saysNothing(MutantJudgement $judgement): bool
    {
        return match ($judgement) {
            MutantJudgement::Equivalent, MutantJudgement::Ignored, MutantJudgement::IgnoredByMarker => true,
            MutantJudgement::Killed,
            MutantJudgement::Errored,
            MutantJudgement::KilledByTimeout,
            MutantJudgement::KilledByMemoryCap,
            MutantJudgement::KilledByStaticAnalysis,
            MutantJudgement::Survived,
            MutantJudgement::Uncovered,
            MutantJudgement::Unjudged,
            MutantJudgement::Flaky,
            MutantJudgement::TooSlowToJudge,
            MutantJudgement::TooHeavyToJudge => false,
        };
    }

    /** Whether a test judges the removal, and none of those is weak: they assert things, only not this. */
    private static function assertsOtherThings(JudgedMutant $removal, KillMatrix $matrix, TestFiles $tests): bool
    {
        $judging = Weakness::judgedBy($removal, $matrix);

        return count($judging) > 0 && count(Weakness::weakAmong($judging, $matrix, $tests)) === 0;
    }
}
