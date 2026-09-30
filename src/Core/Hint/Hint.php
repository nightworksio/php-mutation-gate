<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Hint;

use function array_map;
use function array_pop;
use function array_slice;
use function count;
use function implode;
use function in_array;
use function iterator_to_array;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Php\Functions;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;

use function sprintf;

/**
 * What the tests miss about one mutant, in one sentence filled in from its
 * diff and from the function around it, naming up to three of the tests that
 * judge it (ADR-0009, decision 7).
 */
final readonly class Hint
{
    /** How many judging tests a hint names before it counts the rest. */
    private const int NAMED = 3;

    private const string BOUNDARY = 'No test uses a value at the boundary of `%s`.';

    private const string CONDITION = 'These tests run the condition, but none asserts on anything that depends on it.';

    private const string LOGICAL = 'No test has exactly one side of the `%s` true.';

    private const string ARITHMETIC = 'No test checks the result of `%s` with a non-zero operand.';

    private const string RETURNED = 'Tests call `%s()`, but none asserts on what it returns.';

    private const string RETURNED_HERE = 'Tests run this return, but none asserts on what it returns.';

    private const string REMOVED = 'Every test passes without the call to `%s()`, so nothing asserts on its effect.';

    private const string REMOVED_HERE = 'Every test passes without `%s`, so nothing asserts on its effect.';

    private const string LITERAL = 'No test depends on this value being `%s`.';

    private const string COLLECTION
        = 'No test notices the item missing, or runs the loop with items and checks the outcome.';

    private const string EXCEPTION = 'No test expects this exception.';

    private const string UNWRAP = 'No test passes a value `%s()` would change.';

    private const string UNWRAP_HERE = 'No test passes a value this call would change.';

    private const string VISIBLE = 'Nothing outside the class calls `%s()`. It can be narrower.';

    private const string VISIBLE_HERE = 'Nothing outside the class calls this. It can be narrower.';

    private const string BECOMES = 'These tests run line %d, but none fails when it becomes `%s`.';

    private const string GOES = 'These tests run line %d, but none fails when it is removed.';

    private const string UNCOVERED = 'No test runs line %d.';

    private const string KILLED = 'A test fails with it in place.';

    private const string ERRORED = 'It crashes its tests, which counts as killed.';

    private const string TIMED_OUT
        = 'Its tests ran far past their usual time with it in place, so the timeout counts as a kill.';

    private const string UNJUDGED = 'Nothing judged it before the run stopped, so it counts as not killed.';

    private const string FLAKY
        = 'Its tests killed it on one run and let it survive on another, so they are the suspects.';

    private const string TOO_SLOW
        = 'Its tests take half its time limit or more, so a timeout says nothing about it. %s';

    private const string HOLD = 'Hold `%s` with a group of the tests that assert on it, or raise `timeouts.seconds`.';

    private const string IGNORED = 'An ignore in the config leaves it out of the score.';

    private const string MARKED = 'A native ignore marker leaves it out of the score.';

    private const string EQUIVALENT = 'It compiles to the same program as the original, so no test can fail on it.';

    private const string JUDGED_BY = '%s It is judged by %s.';

    /** The judgements whose hint names the judging tests: those a score counts as not killed that tests ran. */
    private const array NAMING = [
        MutantJudgement::Survived,
        MutantJudgement::Flaky,
        MutantJudgement::TooSlowToJudge,
        MutantJudgement::Unjudged,
    ];

    private function __construct(private string $text)
    {
    }

    /** A hint as it was written, such as one a file the gate wrote holds. */
    public static function that(string $text): self
    {
        return new self($text);
    }

    /** The hint for a mutant as the gate judged it, the tests that judge it and its file, where there is one. */
    public static function for(
        Mutant $mutant,
        MutantJudgement $judgement,
        TestIds $tests,
        Contents|Missing $source,
    ): self {
        $sentence = match ($judgement) {
            MutantJudgement::Survived => self::missed($mutant, $source),
            MutantJudgement::Uncovered => sprintf(self::UNCOVERED, $mutant->location()->start()->number()),
            MutantJudgement::Killed => self::KILLED,
            MutantJudgement::Errored => self::ERRORED,
            MutantJudgement::KilledByTimeout => self::TIMED_OUT,
            MutantJudgement::Unjudged => self::UNJUDGED,
            MutantJudgement::Flaky => self::FLAKY,
            MutantJudgement::TooSlowToJudge => sprintf(
                self::TOO_SLOW,
                sprintf(self::HOLD, $mutant->location()->file()->value()),
            ),
            MutantJudgement::Ignored => self::IGNORED,
            MutantJudgement::IgnoredByMarker => self::MARKED,
            MutantJudgement::Equivalent => self::EQUIVALENT,
        };
        $naming = in_array($judgement, self::NAMING, strict: true) && count($tests) > 0;

        return new self($naming ? sprintf(self::JUDGED_BY, $sentence, self::named($tests)) : $sentence);
    }

    public function text(): string
    {
        return $this->text;
    }

    /** What the tests miss about a survivor, by its mutator's family. */
    private static function missed(Mutant $mutant, Contents|Missing $source): string
    {
        $change = Change::of($mutant->mutation()->diff());
        $line = $mutant->location()->start();
        $function = $source instanceof Contents ? Functions::in($source)->around($line) : '';

        return match ($mutant->mutation()->family()) {
            MutatorFamily::Boundary => sprintf(self::BOUNDARY, $change->expression()),
            MutatorFamily::Condition => self::CONDITION,
            MutatorFamily::Logical => sprintf(self::LOGICAL, $change->original()),
            MutatorFamily::Arithmetic => sprintf(self::ARITHMETIC, $change->expression()),
            MutatorFamily::ReturnValue => self::about($function, self::RETURNED, self::RETURNED_HERE),
            MutatorFamily::RemovedCall => $change->call() === ''
                ? sprintf(self::REMOVED_HERE, $change->removed())
                : sprintf(self::REMOVED, $change->call()),
            MutatorFamily::Literal => sprintf(self::LITERAL, $change->original()),
            MutatorFamily::Collection => self::COLLECTION,
            MutatorFamily::Exception => self::EXCEPTION,
            MutatorFamily::Unwrap => self::about($change->call(), self::UNWRAP, self::UNWRAP_HERE),
            MutatorFamily::Visibility => self::about($function, self::VISIBLE, self::VISIBLE_HERE),
            MutatorFamily::None => $change->added() === ''
                ? sprintf(self::GOES, $line->number())
                : sprintf(self::BECOMES, $line->number(), $change->added()),
        };
    }

    /** The sentence about a named function, or the one about this code where there is no name. */
    private static function about(string $name, string $named, string $here): string
    {
        return $name === '' ? $here : sprintf($named, $name);
    }

    /** Up to three tests, in backticks, then how many more. */
    private static function named(TestIds $tests): string
    {
        $all = iterator_to_array($tests, preserve_keys: false);
        $named = array_map(
            static fn(TestId $test): string => sprintf('`%s`', $test->value()),
            array_slice($all, 0, self::NAMED),
        );
        $more = count($all) - count($named);

        $last = array_pop($named);

        return match (true) {
            $more > 0 => sprintf('%s, %s and %d more', implode(', ', $named), $last, $more),
            $named !== [] => sprintf('%s and %s', implode(', ', $named), $last),
            default => sprintf('%s', $last),
        };
    }
}
