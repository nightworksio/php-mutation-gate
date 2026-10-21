<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_key_exists;
use function array_map;
use function count;

use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordField;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Mutant\Ended;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\WrittenBytes;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function preg_match;

/**
 * What the mutants' own processes of one Pest run recorded, each line kept
 * by the mutated copy it ran on, as {@see Records} reads them. Two mutants
 * that leave the same source share their copy, so what their runs recorded
 * is kept together. Where each killer stood in its run's order, and how a
 * failed run ended, is the evidence of a kill (ADR-0014, decision 16).
 */
final class OwnRuns
{
    /** The pattern a digest of a run's order matches: a SHA-256 digest in lowercase hex. */
    private const string DIGEST = '/^[0-9a-f]{64}$/';

    /** @var array<string, list<string>> the tests that failed or errored, in order, by the mutated copy they ran on */
    private array $killers = [];

    /** @var array<string, list<string>> the tests that errored, by the mutated copy they ran on */
    private array $errored = [];

    /** @var array<string, MemoryCap> the memory limit a mutant's own process ran out of, by the copy it ran on */
    private array $exhausted = [];

    /** @var array<string, true> each own run that loaded the original before the mutant was in place, by its copy */
    private array $preloaded = [];

    /** @var array<string, list<string>> the test files each narrowed own run loaded, by the mutated copy it ran on */
    private array $narrowed = [];

    /** @var array<string, float> the seconds a patched run allowed each own run, by the mutated copy it ran on */
    private array $limited = [];

    /** @var array<string, float> the silence limit each own run was stopped at, by the mutated copy it ran on */
    private array $silent = [];

    /** @var array<string, int> how many tests the own runs on each mutated copy ran, together, by the copy */
    private array $ran = [];

    /** @var array<string, Places> where each killer stood in its run's order, by the mutated copy it ran on */
    private array $places = [];

    /** @var array<string, list<Ended>> how each failed own run ended, as Pest's parent saw it, by its copy */
    private array $ended = [];

    /** @var array<string, true> each mutated copy whose own runs PHP recorded a fatal error in */
    private array $fatal = [];

    /** @var array<string, list<list<string>>> the arguments each own run on a copy was started with, by the copy */
    private array $arguments = [];

    /** What the own runs on this mutated copy recorded. */
    public function of(string $mutated): OwnRun
    {
        return OwnRun::of(
            array_key_exists($mutated, $this->killers) ? $this->killers[$mutated] : [],
            array_key_exists($mutated, $this->errored) ? $this->errored[$mutated] : [],
            array_key_exists($mutated, $this->narrowed) ? $this->narrowed[$mutated] : [],
            array_key_exists($mutated, $this->exhausted) ? $this->exhausted[$mutated] : NotGiven::value(),
            array_key_exists($mutated, $this->preloaded),
            array_key_exists($mutated, $this->ran) ? $this->ran[$mutated] : NotGiven::value(),
        )->withEvidence(
            array_key_exists($mutated, $this->places) ? $this->places[$mutated] : Places::none(),
            $this->endingOf($mutated),
        )->startedWith(
            array_key_exists($mutated, $this->arguments) && count($this->arguments[$mutated]) === 1
                ? $this->arguments[$mutated][0]
                : NotGiven::value(),
        );
    }

    /** The seconds a patched run allowed the own runs on this mutated copy, where it recorded them. */
    public function limitOf(string $mutated): Seconds|NotGiven
    {
        return array_key_exists($mutated, $this->limited) ? Seconds::of($this->limited[$mutated]) : NotGiven::value();
    }

    /** The silence limit the own runs on this mutated copy were stopped at; none where they were not. */
    public function silenceOf(string $mutated): Seconds|NotGiven
    {
        return array_key_exists($mutated, $this->silent) ? Seconds::of($this->silent[$mutated]) : NotGiven::value();
    }

    /**
     * A test that failed an assertion.
     *
     * @throws NotInShape
     */
    public function killed(Node $record): void
    {
        $mutated = $this->mutatedIn($record);
        $this->killers[$mutated][] = $record->field(RecordField::Test->value)->text();
        $this->placed($mutated, $record);
    }

    /**
     * A test that errored, which killed the mutant as one that failed does.
     *
     * @throws NotInShape
     */
    public function errored(Node $record): void
    {
        $test = $record->field(RecordField::Test->value)->text();
        $mutated = $this->mutatedIn($record);
        $this->killers[$mutated][] = $test;
        $this->errored[$mutated][] = $test;
        $this->placed($mutated, $record);
    }

    /**
     * How a failed own run ended; a code or a signal the line does not
     * name is not known, and nothing it printed is kept.
     *
     * @throws NotInShape
     */
    public function ended(Node $record): void
    {
        $code = $record->field(RecordField::Code->value);
        $signalled = $record->field(RecordField::Signalled->value);
        $this->ended[$this->mutatedIn($record)][] = Ended::unprinted(
            $code->isPresent() ? $code->integer() : NotGiven::value(),
            $signalled->isPresent() ? $signalled->boolean() : NotGiven::value(),
        );
    }

    /**
     * That PHP recorded a fatal error in an own run, as its error log says.
     *
     * @throws NotInShape
     */
    public function fatal(Node $record): void
    {
        $this->fatal[$this->mutatedIn($record)] = true;
    }

    /** @throws NotInShape */
    public function exhausted(Node $record): void
    {
        $this->exhausted[$this->mutatedIn($record)] = WrittenBytes::read($record->field(RecordField::Bytes->value));
    }

    /** @throws NotInShape */
    public function silent(Node $record): void
    {
        $this->silent[$this->mutatedIn($record)] = $this->secondsIn($record);
    }

    /** @throws NotInShape */
    public function preloaded(Node $record): void
    {
        $this->preloaded[$this->mutatedIn($record)] = true;
    }

    /** @throws NotInShape */
    public function limited(Node $record): void
    {
        $this->limited[$this->mutatedIn($record)] = $this->secondsIn($record);
    }

    /**
     * The arguments an own run was started with; of two runs on one copy,
     * which started how cannot be told, so neither is known.
     *
     * @throws NotInShape
     */
    public function startedWith(Node $record): void
    {
        $this->arguments[$this->mutatedIn($record)][] = array_map(
            static fn(Node $argument): string => $argument->text(),
            $record->field(RecordField::Arguments->value)->items(),
        );
    }

    /** @throws NotInShape */
    public function narrowed(Node $record): void
    {
        $this->narrowed[$this->mutatedIn($record)] = array_map(
            static fn(Node $file): string => $file->text(),
            $record->field(RecordField::Files->value)->items(),
        );
    }

    /**
     * How many tests an own run ran, added to those of any other run on the
     * same copy.
     *
     * @throws NotInShape
     */
    public function ran(Node $record): void
    {
        $mutated = $this->mutatedIn($record);
        $count = $record->field(RecordField::Count->value);

        if ($count->integer() < 0) {
            throw NotInShape::at($count->at(), 'a number of tests, which is never below 0');
        }

        $this->ran[$mutated] = (array_key_exists($mutated, $this->ran) ? $this->ran[$mutated] : 0) + $count->integer();
    }

    /**
     * How the own runs on a copy ended, where exactly one ending is
     * recorded for it: of two, which run ended how cannot be told. A fatal
     * error PHP recorded in a run on it goes with that ending, or, where Pest
     * recorded none, stands alone, its code and signal not known.
     */
    private function endingOf(string $mutated): Ended|NotGiven
    {
        $endings = array_key_exists($mutated, $this->ended) ? $this->ended[$mutated] : [];
        $fatal = array_key_exists($mutated, $this->fatal);

        return match (true) {
            count($endings) === 1 => $fatal ? $endings[0]->withFatal(fatal: true) : $endings[0],
            count($endings) === 0 && $fatal
                => Ended::unprinted(NotGiven::value(), NotGiven::value())->withFatal(fatal: true),
            default => NotGiven::value(),
        };
    }

    /**
     * Where a killer stood, as its line says: a position, from one, and a
     * SHA-256 digest of the order up to it, each where the line gives it,
     * and the process it came from.
     *
     * @throws NotInShape
     */
    private function placed(string $mutated, Node $record): void
    {
        $at = $record->field(RecordField::At->value);
        $order = $record->field(RecordField::Order->value);
        $run = $record->field(RecordField::Run->value);

        if ($at->isPresent() && $at->integer() < 1) {
            throw NotInShape::at($at->at(), 'a position, which counts from one');
        }

        if ($order->isPresent() && preg_match(self::DIGEST, $order->text()) !== 1) {
            throw NotInShape::at($order->at(), 'a SHA-256 digest in lowercase hex');
        }

        $this->places[$mutated] = (array_key_exists($mutated, $this->places) ? $this->places[$mutated] : Places::none())
            ->with(
                $at->isPresent() ? $at->integer() : NotGiven::value(),
                $order->isPresent() ? $order->text() : NotGiven::value(),
                $run->isPresent() ? $run->integer() : NotGiven::value(),
            );
    }

    /** @throws NotInShape */
    private function mutatedIn(Node $record): string
    {
        return $record->field(RecordField::Mutated->value)->text();
    }

    /**
     * The seconds a record holds, above none.
     *
     * @throws NotInShape
     */
    private function secondsIn(Node $record): float
    {
        $seconds = $record->field(RecordField::Seconds->value);

        return $seconds->number() > 0.0
            ? $seconds->number()
            : throw NotInShape::at($seconds->at(), 'a number of seconds above 0');
    }
}
