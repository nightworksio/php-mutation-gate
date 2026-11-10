<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function ceil;
use function function_exists;
use function hash;
use function is_callable;
use function max;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Adapter\Process\Halting;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Unit\Units;

use function pcntl_alarm;
use function pcntl_signal;
use function pcntl_signal_get_handler;

use const SIG_DFL;
use const SIGALRM;

/**
 * A batch of a round of `watch`, halted as its deadline would halt it once a
 * save moves one of its units (ADR-0146): every `local.watchPoll`, a second
 * at the least, it reads its units' files again, and from the first look that
 * finds one unlike it was as the batch began it halts the processes the
 * batch runs at each look (see Halting). Where `pcntl` is missing, a batch
 * runs to its end.
 *
 * @internal
 */
final class BatchHalt
{
    private const string HASH = 'xxh128';

    private const string UNREAD = '';

    private bool $moved = false;

    /** @var callable|int the handler the alarm had before */
    private mixed $before = SIG_DFL;

    /** @param array<string, string> $digests each unit's file's digest as the batch began, by its path */
    private function __construct(
        private readonly Directory $project,
        private readonly array $digests,
        private readonly int $every,
        private readonly Halting $halting,
    ) {
    }

    /** The batch of these units watched for a save every so often; nothing where this PHP has no alarm. */
    public static function armed(Directory $project, Units $batch, Seconds $every): self|NotGiven
    {
        if (! Halting::signals() || ! function_exists('pcntl_alarm')) {
            return NotGiven::value();
        }

        $digests = [];

        foreach ($batch as $unit) {
            $digests[$unit->path()->value()] = self::digestOf($project, $unit->path());
        }

        $halt = new self($project, $digests, max(1, (int) ceil($every->seconds())), Halting::listened());
        $before = pcntl_signal_get_handler(SIGALRM);
        $halt->before = is_callable($before) ? $before : (int) $before;
        pcntl_signal(SIGALRM, $halt->looked(...));
        pcntl_alarm($halt->every);

        return $halt;
    }

    /** Whether the batch was halted, its alarm and its signal handled as they were before it began. */
    public function disarmed(): bool
    {
        pcntl_alarm(0);
        pcntl_signal(SIGALRM, $this->before);
        $halted = $this->halting->heard();
        $this->halting->letGo();

        return $halted;
    }

    private static function digestOf(Directory $project, Path $file): string
    {
        $read = $project->read($file);

        return $read instanceof Contents ? hash(self::HASH, $read->text()) : self::UNREAD;
    }

    /** One look at the units' files, halting the batch where one has moved, and the next look set. */
    private function looked(): void
    {
        foreach ($this->digests as $path => $digest) {
            $this->moved = $this->moved || self::digestOf($this->project, Path::of($path)) !== $digest;
        }

        if ($this->moved) {
            Halting::halt();
        }

        pcntl_alarm($this->every);
    }
}
