<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Warm;

use function array_map;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\NotGiven;

use function sprintf;

/**
 * What a warm worker of the verification tail boots before it forks
 * (ADR-0075), as the gate leaves it in the directory the workers share: the
 * project's root, its Composer autoloader, Pest's script, and the suites the
 * runs it serves name, none where they name none. Each worker of that
 * directory boots it.
 *
 * @internal
 */
final readonly class BootSpec
{
    private const string FILE = '%s/boot.json';

    /** @param list<string> $suites */
    private function __construct(
        private string $root,
        private string $autoloader,
        private string $script,
        private array $suites,
    ) {
    }

    /** @param list<string> $suites the suites the runs name with `--testsuite`; none where they name none */
    public static function of(string $root, string $autoloader, string $script, array $suites): self
    {
        return new self($root, $autoloader, $script, $suites);
    }

    /** What the workers sharing this directory boot; none where they boot nothing of the project. */
    public static function readIn(string $directory): self|NotGiven
    {
        $at = WholeFile::read(sprintf(self::FILE, $directory));

        try {
            return $at instanceof Node ? self::read($at) : $at;
        } catch (NotInShape) {
            return NotGiven::value();
        }
    }

    /** Left in this directory, whole, for each worker that shares it: whether it was. */
    public function leftIn(string $directory): bool
    {
        $written = Json::object(
            Member::of('root', $this->root),
            Member::of('autoloader', $this->autoloader),
            Member::of('script', $this->script),
            Member::of('suites', Json::items(...$this->suites)),
        );

        return WholeFile::written(sprintf(self::FILE, $directory), $written->line());
    }

    public function root(): string
    {
        return $this->root;
    }

    public function autoloader(): string
    {
        return $this->autoloader;
    }

    public function script(): string
    {
        return $this->script;
    }

    /** @return list<string> */
    public function suites(): array
    {
        return $this->suites;
    }

    private static function read(Node $at): self
    {
        $suites = array_map(static fn(Node $suite): string => $suite->text(), $at->field('suites')->items());

        return new self(
            $at->field('root')->text(),
            $at->field('autoloader')->text(),
            $at->field('script')->text(),
            $suites,
        );
    }
}
