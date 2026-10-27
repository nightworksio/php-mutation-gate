<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core;

use function array_last;
use function array_map;
use function implode;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\ShellWord;
use NightWorksIO\MutationGate\Core\Hook\Hook;

use function sprintf;
use function str_contains;

/**
 * The gate as it was started, which every command it writes or prints for
 * itself names (ADR-0022): the binary Composer linked into the project, or
 * the PHAR the gate ran from, run itself where it can be or by PHP where it
 * cannot, each as a shell in the project's root reaches it.
 */
final readonly class GateCommand
{
    /** Where Composer links the gate where nothing configures its binaries elsewhere. */
    private const string INSTALLED = 'vendor/bin/mutation-gate';

    /** What a shell runs a PHP file with that cannot run itself. */
    private const string PHP = 'php';

    /** How a shell is told a file in its own directory is the one to run, not one on its path. */
    private const string HERE = './%s';

    private const string SEPARATOR = '/';

    private const string WRITE = '--write';

    private const string FULL_MATRIX = '--kill-matrix=full';

    private const string BUDGET = '--budget=<duration>';

    private const string PLAN = '--plan=.mutation-gate/plan.json';

    private const string RESULTS = '--results=.mutation-gate/results';

    /** How a Composer script runs a PHP file under the PHP Composer itself runs on. */
    private const string COMPOSER_PHP = '@php';

    private const string BETWEEN = ' ';

    /** @param non-empty-list<string> $words */
    private function __construct(private array $words)
    {
    }

    /** The gate Composer linked into `vendor/bin`. */
    public static function installed(): self
    {
        return new self([self::INSTALLED]);
    }

    /** The gate at this path, which a shell runs itself. */
    public static function at(Path $binary): self
    {
        return new self([self::reached($binary)]);
    }

    /** The gate at this path, which PHP runs, as a file a shell cannot run itself. */
    public static function byPhp(Path $file): self
    {
        return new self([self::PHP, $file->value()]);
    }

    /** The command that reruns one mutant under its tests (ADR-0014). */
    public function reproduce(string $mutant): string
    {
        return $this->with(Verb::Reproduce, $mutant);
    }

    /** The command that explains one mutant or cluster, running nothing (ADR-0014). */
    public function explain(string $id): string
    {
        return $this->with(Verb::Explain, $id);
    }

    /** The command that writes the test stub of a cluster (ADR-0022). */
    public function stub(string $cluster): string
    {
        return $this->with(Verb::Stub, $cluster);
    }

    /** The command that writes the baseline from the last verdict's scores (ADR-0003). */
    public function baselineWritten(): string
    {
        return $this->with(Verb::Baseline, self::WRITE);
    }

    /** The command that runs the gate with more time, the duration left for the reader to choose (ADR-0008). */
    public function budgeted(): string
    {
        return $this->with(Verb::Run, self::BUDGET);
    }

    /** The command that runs the gate recording every killer of every mutant (ADR-0014). */
    public function fullMatrix(): string
    {
        return $this->with(Verb::Run, self::FULL_MATRIX);
    }

    /** A command of the gate that reads the plan `plan` wrote, with these arguments after it (ADR-0006). */
    public function planned(Verb $verb, string ...$arguments): string
    {
        return $this->with($verb, self::PLAN, ...$arguments);
    }

    /** The command that judges the plan `plan` wrote from every shard's results (ADR-0006). */
    public function plannedVerdict(): string
    {
        return $this->planned(Verb::Verdict, self::RESULTS);
    }

    /** A command of the gate as a Composer script runs it, under the PHP Composer runs on. */
    public function scripted(Verb $verb): string
    {
        $file = array_last($this->words);

        return implode(self::BETWEEN, [self::COMPOSER_PHP, ShellWord::of($file), $verb->value]);
    }

    /** The command line that runs this command of the gate with these arguments, as a shell reads it. */
    public function with(Verb|Hook $verb, string ...$arguments): string
    {
        return implode(self::BETWEEN, [$this->gate(), $verb->value, ...$arguments]);
    }

    /** The gate alone, as a shell reads it, which runs it as `run` does. */
    public function gate(): string
    {
        return implode(self::BETWEEN, array_map(ShellWord::of(...), $this->words));
    }

    /** A path as a shell runs it: one with no directory in it is named from the shell's own. */
    private static function reached(Path $binary): string
    {
        $path = $binary->value();

        return str_contains($path, self::SEPARATOR) ? $path : sprintf(self::HERE, $path);
    }
}
