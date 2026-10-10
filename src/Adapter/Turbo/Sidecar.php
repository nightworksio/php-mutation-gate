<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Turbo;

use function file_exists;
use function file_put_contents;
use function getmypid;
use function hash;
use function is_dir;
use function is_file;
use function mkdir;

use NightWorksIO\MutationGate\Core\File\Workspace;
use NightWorksIO\MutationGate\Core\Runner\ChildProcess;
use NightWorksIO\MutationGate\Core\Runner\Environment;
use NightWorksIO\MutationGate\Core\Runner\ProcessCommand;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Turbo\Answer;
use NightWorksIO\MutationGate\Core\Turbo\NotAccelerated;
use NightWorksIO\MutationGate\Core\Turbo\Protocol;
use NightWorksIO\MutationGate\Core\Turbo\Request;
use NightWorksIO\MutationGate\Port\Accelerator;
use NightWorksIO\MutationGate\Port\Processes;

use function sprintf;
use function unlink;

/**
 * The helper binary, run once per request: the request is written to a file
 * under `.mutation-gate/turbo`, whose path the helper is given, and the
 * answer is what it prints (ADR-0029). A helper that fails, is stopped at
 * its limit or cannot be started answers nothing.
 */
final readonly class Sidecar implements Accelerator
{
    /** How long one request may take before the helper is stopped and the gate works in PHP. */
    private const float LIMIT = 120.0;

    private const string DIRECTORY = '%s/%s/turbo';

    private const string FILE = '%s/request-%d-%s.json';

    private const string UNWRITTEN = 'The request could not be written to %s.';

    private const string FAILED = 'The helper answered nothing: %s';

    /** @param list<string> $command the helper, as a program and the arguments before its own */
    private function __construct(
        private Processes $processes,
        private string $root,
        private array $command,
        private Environment $environment,
    ) {
    }

    /** @param list<string> $command the helper, as a program and the arguments before its own */
    public static function of(Processes $processes, string $root, array $command, Environment $environment): self
    {
        return new self($processes, $root, $command, $environment);
    }

    public function answer(Request $request): Answer|NotAccelerated
    {
        $directory = sprintf(self::DIRECTORY, $this->root, Workspace::root()->value());
        $file = sprintf(self::FILE, $directory, getmypid(), hash('sha256', $request->text()));
        $written = (is_dir($directory) || (! file_exists($directory) && mkdir($directory, recursive: true)))
            && file_put_contents($file, $request->text()) !== false;

        if (! $written) {
            return NotAccelerated::because(sprintf(self::UNWRITTEN, $file));
        }

        $ran = ChildProcess::of($this->processes->run(
            ProcessCommand::of($this->root, ...[...$this->command, Protocol::ANSWER_COMMAND, $file])
                ->with($this->environment)
                ->within(Seconds::of(self::LIMIT)),
        ));

        if (is_file($file)) {
            unlink($file);
        }

        return $ran->succeeded()
            ? Answer::ofText($ran->output())
            : NotAccelerated::because(sprintf(self::FAILED, $ran->said()));
    }
}
