<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function array_key_exists;
use function mb_substr;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;

use function preg_match;
use function sprintf;

/**
 * What the lists PHPUnit prints after a run name of the tests that failed
 * it, read a line at a time (see PrintedList): the tests that failed or met
 * an error; the tests whose issues failed it; and each place a warning was
 * raised in a test itself, by the file and line PHPUnit printed. A test is
 * named as the coverage map names it: `<class>::<method>`, with `#<data set>`
 * for a data set's, which a list of failures prints as `<class>::<method>#0
 * with data (…)` or `…@<name> with data (…)`, and a list of the tests that
 * triggered a warning as `* <class>::<method>#<data set>`, with ` (2 times)`
 * where it triggered it more than once.
 *
 * @phpstan-type Place array{file: string, line: int}
 */
final readonly class PrintedTests
{
    /** The header of a list, by the kind of entry it lists, `There was` or `1 test triggered` before it. */
    private const string HEADER = '/^(?:(?<there>There (?:was|were))|\d+ tests? triggered) \d+ (?<kind>.+?)s?:$/D';

    /** One test in a list of tests, and the data set it ran with where it ran with one. */
    private const string ENTRY = '/^\d+\) (?<test>[^\s#@]+::[^\s#@]+)(?:(?<set>[#@].*?) with data \(.*\))?$/D';

    /** One warning in a list of warnings, by the file and line it was raised at. */
    private const string ISSUE = '/^\d+\) (?<file>.+):(?<line>\d+)$/D';

    /** What a warning raised outside the test itself lists the tests that triggered it after. */
    private const string TRIGGERED = 'Triggered by:';

    /** One test that triggered a warning. */
    private const string TRIGGER = '/^\* (?<test>[^\s#]+::[^\s#]+(?:#.*?)?)(?: \(\d+ times\))?$/D';

    /**
     * @param list<Place>    $places
     * @param Place|NotGiven $pending the place of the warning being read, until a list of the tests that triggered it
     */
    private function __construct(
        private PrintedList $list,
        private TestIds $defects,
        private TestIds $issued,
        private array $places,
        private array|NotGiven $pending,
    ) {
    }

    public static function none(): self
    {
        return new self(PrintedList::Other, TestIds::none(), TestIds::none(), [], NotGiven::value());
    }

    /** These, and what the next line names, on a run that fails on these issues. */
    public function read(string $line, FailingIssues $failing): self
    {
        if (preg_match(self::HEADER, $line, $header) === 1) {
            $list = PrintedList::headed($header['kind'], $header['there'] !== '', $failing);

            return new self($list, $this->defects, $this->issued, $this->ended()->places, NotGiven::value());
        }

        return match ($this->list) {
            PrintedList::Defects => $this->withDefects($this->entry($line, $this->defects)),
            PrintedList::Issues => $this->withIssued($this->entry($line, $this->issued)),
            PrintedList::Warnings => $this->warned($line),
            PrintedList::Other => $this,
        };
    }

    /** These, the last warning read as raised in a test itself where no test is listed for it. */
    public function ended(): self
    {
        $pending = $this->pending;

        return $pending instanceof NotGiven
            ? $this
            : new self($this->list, $this->defects, $this->issued, [...$this->places, $pending], NotGiven::value());
    }

    /** The tests that failed or met an error. */
    public function defects(): TestIds
    {
        return $this->defects;
    }

    /** The tests whose issues failed the run, a list naming them. */
    public function issued(): TestIds
    {
        return $this->issued;
    }

    /** @return list<Place> each place a warning was raised in a test itself */
    public function places(): array
    {
        return $this->places;
    }

    /** These, and what a line of a list of warnings names. */
    private function warned(string $line): self
    {
        $issue = preg_match(self::ISSUE, $line, $at) === 1;
        $trigger = preg_match(self::TRIGGER, $line, $named) === 1;

        return match (true) {
            $issue => $this->ended()->withPending(['file' => $at['file'], 'line' => (int) $at['line']]),
            $line === self::TRIGGERED => $this->withPending(NotGiven::value()),
            $trigger => $this->withIssued($this->issued->with(TestId::of($named['test']))),
            default => $this,
        };
    }

    /** @param Place|NotGiven $pending */
    private function withPending(array|NotGiven $pending): self
    {
        return new self($this->list, $this->defects, $this->issued, $this->places, $pending);
    }

    private function withDefects(TestIds $defects): self
    {
        return new self($this->list, $defects, $this->issued, $this->places, $this->pending);
    }

    private function withIssued(TestIds $issued): self
    {
        return new self($this->list, $this->defects, $issued, $this->places, $this->pending);
    }

    /** These tests, and the one a line of a list of tests names, as the coverage map names it. */
    private function entry(string $line, TestIds $tests): TestIds
    {
        if (preg_match(self::ENTRY, $line, $entry) !== 1) {
            return $tests;
        }

        $set = array_key_exists('set', $entry) ? sprintf('#%s', mb_substr($entry['set'], 1)) : '';

        return $tests->with(TestId::of(sprintf('%s%s', $entry['test'], $set)));
    }
}
