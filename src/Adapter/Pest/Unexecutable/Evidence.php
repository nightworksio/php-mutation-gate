<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Unexecutable;

use function array_filter;
use function end;
use function explode;
use function implode;

use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\Fit;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;
use function trim;

/**
 * What a trial run that judged nothing says of itself, for the reason the
 * mutant is left unjudged: how it ended, the exit code or the limit it was
 * stopped at, the first test that failed, or where none did and it failed,
 * the last line it printed, and the test files it ran.
 */
final readonly class Evidence
{
    private const string EXITED = 'exit code %d';

    private const string UNREAD = 'no exit code';

    private const string STOPPED = 'stopped at its limit of %s';

    private const string FAILED = 'first failing test %s: %s';

    private const string PRINTED = 'last printed %s';

    private const string RAN = 'ran %s';

    /** What parts one piece of evidence from the next. */
    private const string PARTS = '; ';

    /** The most characters of a line the run printed that the evidence keeps. */
    private const int KEPT = 200;

    private function __construct(
        private Ran $ran,
        private Seconds $limit,
        private FailedFirst|NotGiven $failed,
        private Paths $tests,
    ) {
    }

    /**
     * @param FailedFirst|NotGiven $failed the first test that failed, or none
     * @param Paths               $tests  the test files the run ran
     */
    public static function of(Ran $ran, Seconds $limit, FailedFirst|NotGiven $failed, Paths $tests): self
    {
        return new self($ran, $limit, $failed, $tests);
    }

    public function text(): string
    {
        return implode(self::PARTS, [$this->ending(), ...$this->failure(), sprintf(self::RAN, $this->files())]);
    }

    private function ending(): string
    {
        $code = $this->ran->exitCode();

        return match (true) {
            $this->ran->wasStopped() => sprintf(self::STOPPED, $this->limit->written()),
            $code instanceof NotGiven => self::UNREAD,
            default => sprintf(self::EXITED, $code),
        };
    }

    /** @return list<string> */
    private function failure(): array
    {
        $failed = $this->failed;

        return match (true) {
            $failed instanceof FailedFirst => [sprintf(self::FAILED, $failed->test(), $this->kept($failed->said()))],
            $this->ran->succeeded() => [],
            default => $this->printed(),
        };
    }

    /**
     * The last line the run printed that holds anything, where it printed one.
     *
     * @return list<string>
     */
    private function printed(): array
    {
        $lines = array_filter(explode("\n", $this->ran->output()), static fn(string $line): bool => trim($line) !== '');

        return $lines === [] ? [] : [sprintf(self::PRINTED, $this->kept(Fit::plain(end($lines))))];
    }

    private function kept(string $line): string
    {
        return Fit::line($line, self::KEPT);
    }

    private function files(): string
    {
        $files = [];

        foreach ($this->tests as $test) {
            $files[] = $test->value();
        }

        return Fit::named($files);
    }
}
