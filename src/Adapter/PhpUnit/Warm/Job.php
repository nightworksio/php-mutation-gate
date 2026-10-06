<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit\Warm;

use function array_map;
use function count;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * What the warm workers of one mutation run share (ADR-0023, decisions 12 and
 * 13): the project's autoloader and PHPUnit's config, which each worker boots
 * once, the files the run mutates, which no worker may have loaded once it
 * booted, the time after which no run starts, and every run, in the order
 * they are claimed.
 */
final readonly class Job
{
    /**
     * @param list<string>   $mutated each file the run mutates, by its absolute path
     * @param list<WarmRun>  $runs
     */
    private function __construct(
        private string $autoloader,
        private string|NotGiven $config,
        private array $mutated,
        private float|NotGiven $end,
        private array $runs,
    ) {
    }

    /**
     * @param list<string>  $mutated each file the run mutates, by its absolute path
     * @param list<WarmRun> $runs
     */
    public static function of(
        string $autoloader,
        string|NotGiven $config,
        array $mutated,
        float|NotGiven $end,
        array $runs,
    ): self {
        return new self($autoloader, $config, $mutated, $end, $runs);
    }

    /** The job a gate wrote. */
    public static function read(string $text): self
    {
        $at = Node::decode($text);
        $config = $at->field('config');
        $end = $at->field('end');

        return new self(
            $at->field('autoloader')->text(),
            $config->isPresent() ? $config->text() : NotGiven::value(),
            array_map(static fn(Node $file): string => $file->text(), $at->field('mutated')->items()),
            $end->isPresent() ? $end->number() : NotGiven::value(),
            array_map(WarmRun::read(...), $at->field('runs')->items()),
        );
    }

    public function written(): Json
    {
        $runs = array_map(static fn(WarmRun $run): Json => $run->written(), $this->runs);
        $members = [
            Member::of('autoloader', $this->autoloader),
            Member::of('mutated', Json::items(...$this->mutated)),
            Member::of('runs', Json::items(...$runs)),
            ...($this->config instanceof NotGiven ? [] : [Member::of('config', $this->config)]),
            ...($this->end instanceof NotGiven ? [] : [Member::of('end', $this->end)]),
        ];

        return Json::object(...$members);
    }

    /** The project's Composer autoloader, which each worker requires first. */
    public function autoloader(): string
    {
        return $this->autoloader;
    }

    /** PHPUnit's config, as PHPUnit finds it in the project's root, or none. */
    public function config(): string|NotGiven
    {
        return $this->config;
    }

    /** @return list<string> each file the run mutates, by its absolute path */
    public function mutated(): array
    {
        return $this->mutated;
    }

    /** When, on the wall clock in seconds, no run starts any more; never, where none is given. */
    public function end(): float|NotGiven
    {
        return $this->end;
    }

    public function count(): int
    {
        return count($this->runs);
    }

    public function run(int $at): WarmRun
    {
        return $this->runs[$at];
    }
}
