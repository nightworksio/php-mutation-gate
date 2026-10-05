<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_key_exists;
use function array_map;

use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordField;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\WrittenBytes;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * What the mutants' own processes of one Pest run recorded, each line kept
 * by the mutated copy it ran on, as {@see Records} reads them. Two mutants
 * that leave the same source share their copy, so what their runs recorded
 * is kept together.
 */
final class OwnRuns
{
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

    /** @var array<string, int> how many tests the own runs on each mutated copy ran, together, by the copy */
    private array $ran = [];

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
        );
    }

    /** The seconds a patched run allowed the own runs on this mutated copy, where it recorded them. */
    public function limitOf(string $mutated): Seconds|NotGiven
    {
        return array_key_exists($mutated, $this->limited) ? Seconds::of($this->limited[$mutated]) : NotGiven::value();
    }

    /**
     * A test that failed an assertion.
     *
     * @throws NotInShape
     */
    public function killed(Node $record): void
    {
        $this->killers[$this->mutatedIn($record)][] = $record->field(RecordField::Test->value)->text();
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
    }

    /** @throws NotInShape */
    public function exhausted(Node $record): void
    {
        $this->exhausted[$this->mutatedIn($record)] = WrittenBytes::read($record->field(RecordField::Bytes->value));
    }

    /** @throws NotInShape */
    public function preloaded(Node $record): void
    {
        $this->preloaded[$this->mutatedIn($record)] = true;
    }

    /** @throws NotInShape */
    public function limited(Node $record): void
    {
        $seconds = $record->field(RecordField::Seconds->value);

        if ($seconds->number() <= 0.0) {
            throw NotInShape::at($seconds->at(), 'a number of seconds above 0');
        }

        $this->limited[$this->mutatedIn($record)] = $seconds->number();
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

    /** @throws NotInShape */
    private function mutatedIn(Node $record): string
    {
        return $record->field(RecordField::Mutated->value)->text();
    }
}
