<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Warm;

use function array_key_exists;
use function array_map;
use function is_file;

use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\NotGiven;

use function rename;
use function sprintf;
use function unlink;

/**
 * A mutant's own run as Pest started it, handed to the warm worker of its
 * place (ADR-0023): Pest's command line, its script first, every variable
 * of its environment, the directory it started in, and the process that
 * asked, which waits for the run and stands for it: a run whose asker has
 * gone is stopped, or never started.
 *
 * @internal
 */
final readonly class Request
{
    private const string TAKEN = '%s.taken';

    /**
     * @param list<string>          $argv        Pest's command line, its script first
     * @param array<string, string> $environment each variable, by its name
     */
    private function __construct(
        private string $id,
        private int $asker,
        private array $argv,
        private array $environment,
        private string $directory,
    ) {
    }

    /**
     * @param list<string>          $argv        Pest's command line, its script first
     * @param array<string, string> $environment each variable, by its name
     */
    public static function of(string $id, int $asker, array $argv, array $environment, string $directory): self
    {
        return new self($id, $asker, $argv, $environment, $directory);
    }

    /**
     * The request left in this file, taken from it, so no other worker takes
     * it; or none, where none is left or it is not a request.
     */
    public static function takenFrom(string $file): self|NotGiven
    {
        $taken = sprintf(self::TAKEN, $file);
        $at = is_file($file) && rename($file, $taken) ? WholeFile::read($taken) : NotGiven::value();

        if (is_file($taken)) {
            unlink($taken);
        }

        try {
            return $at instanceof Node ? self::read($at) : $at;
        } catch (NotInShape) {
            return NotGiven::value();
        }
    }

    /** Left in this file whole, under its name only once it is all there. */
    public function leftIn(string $file): bool
    {
        return WholeFile::written($file, $this->written()->line());
    }

    public function id(): string
    {
        return $this->id;
    }

    /** The process that asked for the run, and waits for it. */
    public function asker(): int
    {
        return $this->asker;
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

    public function directory(): string
    {
        return $this->directory;
    }

    /** The file the run mutates, by its real path; none where the run mutates none. */
    public function mutated(): string
    {
        return array_key_exists(Recorder::MUTANT, $this->environment) ? $this->environment[Recorder::MUTANT] : '';
    }

    private static function read(Node $at): self
    {
        $environment = [];

        foreach ($at->field('environment')->entries() as $name => $value) {
            $environment[sprintf('%s', $name)] = $value->text();
        }

        return new self(
            $at->field('id')->text(),
            $at->field('asker')->integer(),
            array_map(static fn(Node $argument): string => $argument->text(), $at->field('argv')->items()),
            $environment,
            $at->field('directory')->text(),
        );
    }

    private function written(): Json
    {
        $told = [];

        foreach ($this->environment as $name => $value) {
            $told[] = Member::of($name, $value);
        }

        return Json::object(
            Member::of('id', $this->id),
            Member::of('asker', $this->asker),
            Member::of('argv', Json::items(...$this->argv)),
            Member::of('environment', Json::object(...$told)),
            Member::of('directory', $this->directory),
        );
    }
}
