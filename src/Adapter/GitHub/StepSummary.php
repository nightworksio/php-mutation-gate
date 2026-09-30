<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitHub;

use function array_key_exists;
use function dirname;
use function file_put_contents;
use function getenv;
use function is_dir;

use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Extension\Configurable;
use NightWorksIO\MutationGate\Extension\Options;
use NightWorksIO\MutationGate\Port\Reporter;

use function sprintf;

/**
 * The reporter `github-summary`: the verdict appended to the step summary
 * `GITHUB_STEP_SUMMARY` names, with every mutant counted as not killed in one
 * table, as much of it as one step's summary holds (ADR-0009, decision 3).
 */
final readonly class StepSummary implements Configurable, Reporter
{
    private const string UNSET = 'GITHUB_STEP_SUMMARY is not set, so there is no step summary to write.';

    private function __construct(private string $file, private string $run)
    {
    }

    /** A summary appended to this file, linking to this run. */
    public static function appendingTo(string $file, string $run): self
    {
        return new self($file, $run);
    }

    public static function fromOptions(Options $options): self
    {
        $environment = getenv();
        $read = static fn(string $name): string => array_key_exists($name, $environment) ? $environment[$name] : '';

        return new self($read('GITHUB_STEP_SUMMARY'), $read('GITHUB_RUN_ID') === '' ? '' : sprintf(
            '%s/%s/actions/runs/%s',
            $read('GITHUB_SERVER_URL') === '' ? 'https://github.com' : $read('GITHUB_SERVER_URL'),
            $read('GITHUB_REPOSITORY'),
            $read('GITHUB_RUN_ID'),
        ));
    }

    public function report(Verdict $verdict): Written|NotWritten
    {
        if ($this->file === '') {
            return NotWritten::because(self::UNSET);
        }

        return ! is_dir(dirname($this->file))
            || file_put_contents($this->file, Markdown::summary($verdict, $this->run), FILE_APPEND) === false
            ? NotWritten::because(sprintf('The step summary could not be written to %s.', $this->file))
            : Written::to($this->file);
    }
}
