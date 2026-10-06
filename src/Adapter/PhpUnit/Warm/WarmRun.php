<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit\Warm;

use function array_map;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;

use function sprintf;

/**
 * One mutant's run as a warm worker's child makes it: PHPUnit's command line,
 * the variables the child is told, the limit it is stopped at, and the file
 * the override serves the mutated file in place of, with the guard it says so
 * in.
 */
final readonly class WarmRun
{
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
    ): self {
        return new self($argv, $environment, $limit, $original, $mutated, $guard);
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
}
