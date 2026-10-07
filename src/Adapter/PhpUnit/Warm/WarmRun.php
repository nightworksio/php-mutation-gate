<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit\Warm;

use function array_map;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\SilenceLimit;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

/**
 * One mutant's run as a warm worker's child makes it: PHPUnit's command line,
 * the variables the child is told, the limit it is stopped at, the file the
 * override serves the mutated file in place of, with the guard it says so
 * in, and the silence limit it is stopped at too, where it has one.
 */
final readonly class WarmRun
{
    private const string SILENCE = 'silence';

    private const string PROGRESS = 'progress';

    /**
     * @param list<string>          $argv        PHPUnit's command line, its script first
     * @param array<string, string> $environment each variable the child is told, by its name
     */
    private function __construct(
        private array $argv,
        private array $environment,
        private float $limit,
        private string $original,
        private string $mutated,
        private string $guard,
        private SilenceLimit|NotGiven $silence,
    ) {
    }

    /**
     * @param list<string>          $argv        PHPUnit's command line, its script first
     * @param array<string, string> $environment each variable the child is told, by its name
     */
    public static function of(
        array $argv,
        array $environment,
        float $limit,
        string $original,
        string $mutated,
        string $guard,
        SilenceLimit|NotGiven $silence,
    ): self {
        return new self($argv, $environment, $limit, $original, $mutated, $guard, $silence);
    }

    /** The run a job wrote. */
    public static function read(Node $at): self
    {
        $environment = [];

        foreach ($at->field('environment')->entries() as $name => $value) {
            $environment[sprintf('%s', $name)] = $value->text();
        }

        return new self(
            array_map(static fn(Node $argument): string => $argument->text(), $at->field('argv')->items()),
            $environment,
            $at->field('limit')->number(),
            $at->field('original')->text(),
            $at->field('mutated')->text(),
            $at->field('guard')->text(),
            self::silenceIn($at),
        );
    }

    public function written(): Json
    {
        $told = [];

        foreach ($this->environment as $name => $value) {
            $told[] = Member::of($name, $value);
        }

        return Json::object(
            Member::of('argv', Json::items(...$this->argv)),
            Member::of('environment', Json::object(...$told)),
            Member::of('limit', $this->limit),
            Member::of('original', $this->original),
            Member::of('mutated', $this->mutated),
            Member::of('guard', $this->guard),
            ...$this->silenceMembers(),
        );
    }

    /** @return list<string> */
    public function argv(): array
    {
        return $this->argv;
    }

    /** @return array<string, string> */
    public function environment(): array
    {
        return $this->environment;
    }

    /** The seconds the child may run before it is stopped with every process it started. */
    public function limit(): float
    {
        return $this->limit;
    }

    public function original(): string
    {
        return $this->original;
    }

    public function mutated(): string
    {
        return $this->mutated;
    }

    public function guard(): string
    {
        return $this->guard;
    }

    /** How long the child may go with no test starting or ending; none where only its limit stops it. */
    public function silence(): SilenceLimit|NotGiven
    {
        return $this->silence;
    }

    /** The silence limit a job wrote for the run; none where it wrote none. */
    private static function silenceIn(Node $at): SilenceLimit|NotGiven
    {
        $silence = $at->field(self::SILENCE);

        return $silence->isPresent()
            ? SilenceLimit::of(Seconds::of($silence->number()), $at->field(self::PROGRESS)->text())
            : NotGiven::value();
    }

    /** @return list<Member> the silence limit and the file whose growth is the child's progress, where it has one */
    private function silenceMembers(): array
    {
        return $this->silence instanceof SilenceLimit
            ? [
                Member::of(self::SILENCE, $this->silence->limit()->seconds()),
                Member::of(self::PROGRESS, $this->silence->progress()),
            ]
            : [];
    }
}
