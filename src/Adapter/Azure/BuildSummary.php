<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Azure;

use function file_put_contents;
use function is_dir;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\CiRun;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Cost\SavingsWindow;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Report\Markdown;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Port\Reporter;
use Psr\Clock\ClockInterface;

use function sprintf;

/**
 * The reporter `azure-summary`: the verdict written to a file in the agent's temporary directory, then attached to the
 * build's summary with the `##vso[task.uploadsummary]` logging command printed where the agent reads it.
 *
 * @internal
 */
final readonly class BuildSummary implements Reporter
{
    private const string UNSET = 'AGENT_TEMPDIRECTORY is not set, so there is no build summary to write.';

    private const string UNWRITTEN = 'The build summary could not be written to %s.';

    private const string COMMAND = "##vso[task.uploadsummary]%s\n";

    private const string FILE = '%s/mutation-gate-summary.md';

    private const string AGENT_READS = 'php://stdout';

    private function __construct(
        private string $directory,
        private string $run,
        private ClockInterface $clock,
        private SavingsWindow $window,
        private string $to,
    ) {
    }

    /** The summary in this directory, linking this run, its logging command printed to this stream or file. */
    public static function printingTo(
        string $to,
        string $directory,
        string $run,
        ClockInterface $clock,
        SavingsWindow $window,
    ): self {
        return new self($directory, $run, $clock, $window, $to);
    }

    /** The summary this job's variables name, counting what was saved lately by this clock. */
    public static function configured(Options $options, Variables $environment, ClockInterface $clock): self
    {
        $run = CiRun::read($environment);

        return new self(
            $environment->valueOf('AGENT_TEMPDIRECTORY'),
            $run instanceof CannotTell ? '' : $run->url(),
            $clock,
            SavingsWindow::in($options),
            self::AGENT_READS,
        );
    }

    public function report(Verdict $verdict): Written|NotWritten
    {
        if ($this->directory === '') {
            return NotWritten::because(self::UNSET);
        }

        $file = sprintf(self::FILE, $this->directory);
        $markdown = Markdown::summary($verdict, $this->run, $this->window, $this->clock->now());

        return ! is_dir($this->directory)
            || file_put_contents($file, $markdown) === false
            || file_put_contents($this->to, sprintf(self::COMMAND, $file)) === false
                ? NotWritten::because(sprintf(self::UNWRITTEN, $file))
                : Written::to($file);
    }
}
