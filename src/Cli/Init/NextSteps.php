<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Init;

use function array_diff;
use function array_map;
use function explode;
use function implode;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Git\Command as Git;
use NightWorksIO\MutationGate\Cli\StartedGate;
use NightWorksIO\MutationGate\Core\Format\ShellWord;
use NightWorksIO\MutationGate\Core\Verb;

use function sort;
use function sprintf;

/**
 * What `init` says to do once it has set the project up (ADR-0017,
 * ADR-0024): `doctor`, the first run, and `git add` of the files it wrote.
 */
final readonly class NextSteps
{
    /** Every file git would add from the project: changed, or new and not ignored. */
    private const array LISTED = ['ls-files', '-z', '--modified', '--others', '--exclude-standard'];

    private const string NEXT = "Next:\n  %s\n  %s";

    private const string ADD = "%s\n  git add %s";

    /** @param list<string> $before the files git would add before `init` wrote anything */
    private function __construct(private string $project, private array $before)
    {
    }

    /** The project as it is before `init` writes anything. */
    public static function before(string $project): self
    {
        return new self($project, self::listed($project));
    }

    /** The commands to run next; `git add` of the files `init` wrote, where it wrote any git would add. */
    public function said(): string
    {
        $gate = StartedGate::in($this->project);
        $next = sprintf(self::NEXT, $gate->with(Verb::Doctor), $gate->gate());
        $written = array_diff(self::listed($this->project), $this->before);
        sort($written);

        return $written === []
            ? $next
            : sprintf(self::ADD, $next, implode(' ', array_map(ShellWord::of(...), $written)));
    }

    /**
     * @return list<string> the files git would add, as the project names them, then the empty name after the NUL
     *                      that ends the last; none outside a repository
     */
    private static function listed(string $project): array
    {
        $listed = Git::in($project)->run(self::LISTED);

        return is_string($listed) ? explode("\0", $listed) : [];
    }
}
