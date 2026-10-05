<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Keyed;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\NotGiven;

use function realpath;
use function sprintf;

/**
 * Where the gate was started from, which a command that holds the gate's keys checks before anything else
 * (ADR-0007 decision 5): it starts only from the gate's own installation, never through Composer's proxy, which
 * names the autoloader it was started through, and never from the working directory's `vendor`, the project's.
 */
final readonly class Installation
{
    private const string NOT_OWN
        = '%s runs from the gate\'s own installation, never the project\'s, so it loads none of the project\'s code.';

    private function __construct(private string $project, private string $vendor, private bool $throughComposer)
    {
    }

    /** Started in this project, from the autoloader in this vendor directory, through Composer's proxy or not. */
    public static function of(string $project, string $vendor, bool $throughComposer): self
    {
        return new self($project, $vendor, $throughComposer);
    }

    /** Why this command may not start from here; nothing where the gate was started from its own installation. */
    public function refusing(string $command): CannotJudge|NotGiven
    {
        $vendor = realpath($this->vendor);
        $projects = realpath(sprintf('%s/vendor', $this->project));
        $own = ! $this->throughComposer && ($vendor === false || $projects === false || $vendor !== $projects);

        return $own ? NotGiven::value() : CannotJudge::because(sprintf(self::NOT_OWN, $command));
    }
}
