<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_map;
use function array_slice;
use function is_array;
use function is_int;
use function is_string;
use function max;

use NightWorksIO\MutationGate\Core\Config\KillControl;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Prefix;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

/**
 * A kill's own run, as a replay of it unmutated starts it again (see
 * PrefixReplays): the file its mutant changes, the copy its run served, the
 * arguments Pest started it with, the test files it loaded, how many tests
 * it ran up to its last killer, the key of its order up to there, and the
 * seconds Pest allowed its mutant.
 *
 * @internal
 */
final readonly class PrefixReplay
{
    /**
     * @param list<string>     $arguments the arguments Pest started the run with, its script first
     * @param positive-int     $reach     how many tests the run ran up to its last killer
     */
    private function __construct(
        private Path $file,
        private string $mutated,
        private array $arguments,
        private Paths $loaded,
        private int $reach,
        private string $key,
        private Seconds|Unmeasured $limit,
    ) {
    }

    /**
     * The replay of a kill's own run, where its records say its order and how
     * it started; none under `flaky.killControls: alone` where it loaded every
     * test file, which leaves it to its kill control.
     */
    public static function ofRun(
        Mutant $mutant,
        OwnRun $run,
        string $copy,
        Seconds|Unmeasured $limit,
        Project $project,
        KillControl $control,
    ): self|NotGiven {
        if ($control === KillControl::Alone && $run->narrowedTo() === []) {
            return NotGiven::value();
        }

        $files = Paths::of(...array_map($project->relative(...), $run->narrowedTo()));
        $prefix = $run->prefix($files);
        $key = $prefix instanceof Prefix ? $prefix->key() : NotGiven::value();
        $reach = $run->reach();
        $arguments = $run->arguments();

        return is_string($key)
            && is_int($reach)
            && $reach > 0
            && is_array($arguments)
            && $arguments !== []
            ? self::of($mutant->location()->file(), $copy, $arguments, $files, $reach, $key, $limit)
            : NotGiven::value();
    }

    /**
     * @param list<string> $arguments
     * @param positive-int $reach
     */
    public static function of(
        Path $file,
        string $mutated,
        array $arguments,
        Paths $loaded,
        int $reach,
        string $key,
        Seconds|Unmeasured $limit,
    ): self {
        return new self($file, $mutated, $arguments, $loaded, $reach, $key, $limit);
    }

    /**
     * This replay, allowed the longer of its limit and another kill's of the
     * same order, as it vouches for both: a replay past even that ran past
     * every limit its kills had.
     */
    public function alsoFor(Seconds|Unmeasured $limit): self
    {
        $longer = $this->limit instanceof Seconds && $limit instanceof Seconds
            ? Seconds::of(max($this->limit->seconds(), $limit->seconds()))
            : Unmeasured::duration();

        return new self(
            $this->file,
            $this->mutated,
            $this->arguments,
            $this->loaded,
            $this->reach,
            $this->key,
            $longer,
        );
    }

    /**
     * This replay, run to the end of the whole order its run was to take,
     * which vouches for every prefix of that order (ADR-0089).
     */
    public function toTheEnd(Prefix $planned): self
    {
        $key = $planned->key();

        return new self(
            $this->file,
            $this->mutated,
            $this->arguments,
            $this->loaded,
            max($planned->position(), $this->reach),
            is_string($key) ? $key : $this->key,
            $this->limit,
        );
    }

    /** This replay, allowed the longer of its limit and another's, as it vouches for both. */
    public function alongside(self $other): self
    {
        return $this->alsoFor($other->limit);
    }

    /**
     * How long the replay may take within the time left: its kill's limit,
     * where that is shorter; the time left where no limit is known.
     */
    public function within(Seconds|Unlimited $left): Seconds|Unlimited
    {
        return $this->bindsBefore($left) && $this->limit instanceof Seconds ? $this->limit : $left;
    }

    /** Whether the kill's limit, not the time left, bounds the replay. */
    public function bindsBefore(Seconds|Unlimited $left): bool
    {
        return $this->limit instanceof Seconds
            && ($left instanceof Unlimited || $this->limit->seconds() < $left->seconds());
    }

    /** The seconds Pest allowed the kill's mutant, as a key spells them; none where they are not known. */
    public function limitText(): string
    {
        return $this->limit instanceof Seconds ? (string) $this->limit->seconds() : '';
    }

    public function file(): Path
    {
        return $this->file;
    }

    /** The copy the run served, whose order the replay takes. */
    public function mutated(): string
    {
        return $this->mutated;
    }

    /**
     * The arguments after Pest's script.
     *
     * @return list<string>
     */
    public function options(): array
    {
        return array_slice($this->arguments, 1);
    }

    /** @return positive-int */
    public function reach(): int
    {
        return $this->reach;
    }

    /** Whether a replay that took its tests in an order of this digest took them as the run did. */
    public function matches(string $order): bool
    {
        return Prefix::keyOf($this->loaded, $order) === $this->key;
    }

    /** What tells this replay from another of the same file: the key of its order. */
    public function key(): string
    {
        return $this->key;
    }
}
