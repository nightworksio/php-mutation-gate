<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_slice;
use function array_unique;
use function array_values;

use Closure;

use function count;
use function explode;
use function file_get_contents;
use function implode;
use function is_file;
use function is_int;
use function min;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Format\Fit;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

use function preg_match;

use const PREG_UNMATCHED_AS_NULL;

use function sprintf;
use function str_replace;

/**
 * Why a mutation run made no mutant where its opening run failed no test
 * and still exited non-zero: PHPUnit fails a run on a warning, deprecation
 * or notice where phpunit.xml says so, and Pest's own output leaves out one
 * raised outside a test. The opening tests run again alone, as the run
 * selected them and within the opening run's own limit, logging PHPUnit's
 * events, and the issues the log names are quoted.
 */
final readonly class OpeningIssues
{
    private const string ENDED = "Pest's opening run failed no test, yet ended %s, so Pest made no mutant.";

    private const string FAILS
        = 'PHPUnit fails such a run on a warning, deprecation or notice where phpunit.xml says to.';

    private const string SAID = "%s %s %s Pest said:\n%s";

    private const string CODE = 'with exit code %d';

    private const string FAILED = 'with no exit code';

    private const string RAISED = "Run again alone, the opening tests raised:\n  %s\n";

    private const string UNSHOWN = 'Run again alone, the opening tests could not show which: %s.';

    private const string STOPPED = 'they did not finish in the time they had';

    private const string UNLOGGED = "they wrote no log of PHPUnit's events";

    private const string NONE_RAISED = 'PHPUnit logged no warning, deprecation or notice';

    /** The counts on Pest's summary of the tests it ran, such as `1 failed, 2 passed`. */
    private const string TESTS = '/^\s*Tests:\s+(?<counts>.+?)\s+\(\d+ assertions?/m';

    /** A count of tests that failed, which a run of passing tests never has. */
    private const string FAILING = '/\b\d+ failed\b/';

    /** The kinds of issue PHPUnit logs that fail a run where phpunit.xml says to. */
    private const string KINDS = '(?:PHP |PHPUnit )?(?:Warning|Deprecation|Notice|Error)';

    /**
     * An issue PHPUnit logs: whether the runner raised it, its kind, its
     * details, and where it was raised. Its message is on the next line, but
     * for one the runner raised where no file is named, whose details are its
     * message.
     */
    private const string ISSUE
        = '/^(?:(?<runner>Test Runner)|Test) Triggered (?<kind>%s) \((?<details>.*)\)(?: in (?<at>.+:\d+))?$/';

    /** The details of an issue that fails no run: one a test, a filter or a baseline ignores, or `@` suppressed. */
    private const string IGNORED = '/, (?:suppressed using operator|ignored by (?:test|filter|baseline))(?:,|$)/';

    /** An issue quoted with where, or in which test, it was raised and its message; and one the runner raised. */
    private const string AT = '%s in %s: %s';

    private const string OWN = '%s, raised by the runner: %s';

    /** @param Closure(): (Seconds|Unlimited) $left the time the request has left when the opening tests run again */
    private function __construct(
        private Shell $shell,
        private Command $again,
        private Closure $left,
        private string $results,
        private string $root,
    ) {
    }

    /**
     * What runs again the tests a request's run opens on, within the time
     * the request has left, for a run recording to these results.
     *
     * @param Closure(): (Seconds|Unlimited) $left
     */
    public static function of(
        Shell $shell,
        Project $project,
        MutationRequest $request,
        WholeSuite|Group|Filter $opensOn,
        Closure $left,
        string $results,
    ): self {
        $again = Invocation::installedIn($project->vendor())->opening($request, $opensOn, self::beside($results));

        return new self($shell, $again, $left, $results, $project->root());
    }

    /** The log the run again writes, beside the results, which a fresh results file removes. */
    public static function beside(string $results): string
    {
        return sprintf('%s.events', $results);
    }

    /**
     * Whether a run stopped after its opening run: it recorded no mutant,
     * printed no summary of mutants, summed up tests none of which failed,
     * and exited non-zero.
     */
    public function endedAfterOpening(Ran $ran): bool
    {
        $output = $ran->output();

        return ! $ran->succeeded()
            && ! $ran->wasStopped()
            && Records::in($this->results) instanceof CannotJudge
            && Summary::in($output) instanceof CannotJudge
            && preg_match(self::TESTS, $output, $tests) === 1
            && preg_match(self::FAILING, $tests['counts']) !== 1;
    }

    /** Why such a run cannot be judged, with the issues its opening tests raise run again, or why they show none. */
    public function why(Ran $opening): CannotJudge
    {
        $events = self::beside($this->results);
        $again = $this->shell->run($this->again->within($this->limit($opening)));
        $logged = is_file($events);
        $raised = $logged ? $this->raised((string) file_get_contents($events)) : [];
        $code = $opening->exitCode();

        return CannotJudge::because(sprintf(
            self::SAID,
            sprintf(self::ENDED, is_int($code) ? sprintf(self::CODE, $code) : self::FAILED),
            self::FAILS,
            match (true) {
                $again->wasStopped() => sprintf(self::UNSHOWN, self::STOPPED),
                ! $logged => sprintf(self::UNSHOWN, self::UNLOGGED),
                $raised === [] => sprintf(self::UNSHOWN, self::NONE_RAISED),
                default => sprintf(self::RAISED, implode("\n  ", $raised)),
            },
            $opening->output(),
        ));
    }

    /** The limit of the run again: Pest's for the opening run's seconds, within the time the request has left. */
    private function limit(Ran $opening): Seconds|Unlimited
    {
        $took = $opening->duration();
        $left = ($this->left)();
        $own = $took instanceof Seconds ? PestTimeLimit::of($took) : $left;

        return $left instanceof Seconds && $own instanceof Seconds
            ? Seconds::of(min($left->seconds(), $own->seconds()))
            : $own;
    }

    /**
     * The issues a log of PHPUnit's events names, each once, with paths from
     * the project's root: the first few, and how many more.
     *
     * @return list<string>
     */
    private function raised(string $log): array
    {
        $lines = explode("\n", $log);
        $next = [...array_slice($lines, 1), ''];
        $quoted = [];
        $pattern = sprintf(self::ISSUE, self::KINDS);

        foreach ($lines as $at => $line) {
            $matched = preg_match($pattern, $line, $issue, PREG_UNMATCHED_AS_NULL) === 1;

            if ($matched && preg_match(self::IGNORED, $issue['details']) !== 1) {
                $quoted[] = Fit::verbatim($this->quoted($issue, $next[$at]));
            }
        }

        $once = array_values(array_unique($quoted));

        return count($once) > Fit::SHOWN
            ? [...array_slice($once, 0, Fit::SHOWN), Fit::more(count($once) - Fit::SHOWN)]
            : $once;
    }

    /**
     * An issue as it is quoted: with where it was raised, or the test that
     * raised it, and the message on the line after it; or, raised by the
     * runner, with the message its details hold.
     *
     * @param array{runner: string|null, kind: string, details: string, at: string|null} $issue
     */
    private function quoted(array $issue, string $next): string
    {
        return match (true) {
            $issue['at'] !== null => sprintf(self::AT, $issue['kind'], $this->relative($issue['at']), $next),
            $issue['runner'] !== null => sprintf(self::OWN, $issue['kind'], $issue['details']),
            default => sprintf(self::AT, $issue['kind'], $issue['details'], $next),
        };
    }

    /** A path from the project's root, where it is under it. */
    private function relative(string $path): string
    {
        return str_replace(sprintf('%s/', $this->root), '', $path);
    }
}
