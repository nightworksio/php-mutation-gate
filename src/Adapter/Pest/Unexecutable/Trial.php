<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Unexecutable;

use function array_key_exists;
use function dirname;
use function file_get_contents;
use function implode;
use function is_file;
use function is_string;
use function microtime;

use NightWorksIO\MutationGate\Adapter\Pest\Command;
use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\Invocation;
use NightWorksIO\MutationGate\Adapter\Pest\MemoryScan;
use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Adapter\Pest\Shell;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitOption;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\JUnitLog;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

use function sprintf;

/**
 * Runs the tests that judge a mutant of a line that is not executable, with
 * Pest's override serving the mutated copy in place of the original, as Pest
 * serves its own mutants. The tests must pass on their own first, once for
 * each set of them. A run that loaded the original before the override, never
 * loaded it, or ran where opcache could serve a cached original, judges
 * nothing, and the reason says what the run did (see Evidence), from what it
 * printed and the JUnit log it writes beside the guard.
 */
final class Trial
{
    private const string ALONE = 'the selected tests fail on their own';

    private const string UNGUARDED = 'the run wrote no guard, so the gate cannot tell the mutated file ran';

    private const string BEFORE = 'loaded before the override';

    private const string NEVER = 'never loaded';

    private const string OPCACHE = 'opcache.enable_cli or opcache.file_cache on';

    /** A reason, then what the run did. */
    private const string SAID = '%s (%s)';

    /** @var array<string, Outcome|true> each set of test files' outcome on its own where they fail, by their paths */
    private array $alone = [];

    public function __construct(
        private readonly Project $project,
        private readonly Shell $shell,
        private readonly Invocation $invocation,
        private readonly WholeSuite|Group $judgedBy,
        private readonly Withheld $withheld,
        private readonly Seconds|Unmeasured $limit,
        private readonly string $guard,
        private readonly MemoryScan $scan,
    ) {
    }

    /** The time Pest allows each mutant, from its opening run. */
    public function limit(): Seconds|Unmeasured
    {
        return $this->limit;
    }

    /** What the tests in some files find of a mutant whose mutated copy of a file is kept at a path. */
    public function of(Paths $tests, Path $original, string $copy): Outcome
    {
        $alone = $this->alone($tests);

        if ($alone instanceof Outcome) {
            return $alone;
        }

        $this->project->without($this->guard, $this->log());
        $started = microtime(as_float: true);
        $ran = $this->shell->run($this->judging($tests)->with([
            Recorder::MUTANT => $this->project->absolute($original),
            Recorder::MUTATED => $copy,
            GateVariable::Guard->value => $this->guard,
        ]));
        $took = Seconds::of(microtime(as_float: true) - $started);

        return ($ran->wasStopped() ? Outcome::timedOut() : $this->guarded($ran, $tests))->took($took);
    }

    /** What a set of test files finds on its own where it fails there, run once for each set. */
    private function alone(Paths $tests): Outcome|true
    {
        $key = implode("\n", $this->valuesOf($tests));

        if (! array_key_exists($key, $this->alone)) {
            $this->project->without($this->log());
            $ran = $this->shell->run($this->judging($tests));
            $this->alone[$key] = $ran->succeeded() ? true : $this->unjudged(self::ALONE, $ran, $tests);
        }

        return $this->alone[$key];
    }

    private function judging(Paths $tests): Command
    {
        $log = sprintf('%s=%s', PhpUnitOption::LogJunit->value, $this->log());

        return $this->scan->onto($this->invocation->judging($tests, $this->judgedBy, $this->withheld, $log)
            ->within($this->limit instanceof Seconds ? $this->limit : Unlimited::time()));
    }

    /** The JUnit log each run writes, beside the guard. */
    private function log(): string
    {
        return sprintf('%s/%s', dirname($this->guard), JUnitLog::NAME);
    }

    /** What a run that finished found, where its guard says the mutated copy is what ran. */
    private function guarded(Ran $ran, Paths $tests): Outcome
    {
        $text = is_file($this->guard) ? file_get_contents($this->guard) : false;

        return is_string($text)
            ? $this->read(Node::decode($text), $ran, $tests)
            : $this->unjudged(self::UNGUARDED, $ran, $tests);
    }

    private function read(Node $seen, Ran $ran, Paths $tests): Outcome
    {
        try {
            return match (true) {
                $seen->field('before')->boolean() => $this->unjudged(self::BEFORE, $ran, $tests),
                $seen->field('opcache')->boolean() => $this->unjudged(self::OPCACHE, $ran, $tests),
                ! $seen->field('loaded')->boolean() => $this->unjudged(self::NEVER, $ran, $tests),
                $ran->succeeded() => Outcome::survived(),
                default => Outcome::killed(),
            };
        } catch (NotInShape) {
            return $this->unjudged(self::UNGUARDED, $ran, $tests);
        }
    }

    /** A mutant left unjudged for a reason, which says what the run did. */
    private function unjudged(string $reason, Ran $ran, Paths $tests): Outcome
    {
        $evidence = Evidence::of($ran, $this->limit, FailedFirst::in($this->log()), $tests);

        return Outcome::unjudged(sprintf(self::SAID, $reason, $evidence->text()));
    }

    /** @return list<string> */
    private function valuesOf(Paths $tests): array
    {
        $values = [];

        foreach ($tests as $test) {
            $values[] = $test->value();
        }

        return $values;
    }
}
