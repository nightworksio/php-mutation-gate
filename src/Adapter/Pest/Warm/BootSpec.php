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
 * What a warm worker boots before it forks, as the gate leaves it in the
 * directory the workers share: the project's root, its Composer autoloader,
 * Pest's script, the suites the runs it serves name, none where they name
 * none, and whether its workers serve mutants' runs (ADR-0091), loading no
 * test file and serving each file's schema, or the verification tail's
 * (ADR-0075), loading every test file of those suites. Each worker of that
 * directory boots it.
 *
 * @internal
 */
final readonly class BootSpec
{
    private const string FILE = '%s/boot.json';

    private const string MUTANTS = 'forMutants';

    /** @param list<string> $suites */
    private function __construct(
        private string $root,
        private string $autoloader,
        private string $script,
        private array $suites,
        private bool $mutants = false,
    ) {
    }

    /** @param list<string> $suites the suites the runs name with `--testsuite`; none where they name none */
    public static function of(string $root, string $autoloader, string $script, array $suites): self
    {
        return new self($root, $autoloader, $script, $suites);
    }

    /** The same, booted by a worker of mutants' runs: no test file, and each file's schema in its place (ADR-0091). */
    public function forMutants(): self
    {
        return new self($this->root, $this->autoloader, $this->script, $this->suites, mutants: true);
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
            Member::of(self::MUTANTS, $this->mutants),
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

    /** Whether the workers serve mutants' runs, loading no test file and each file's schema in its place. */
    public function isForMutants(): bool
    {
        return $this->mutants;
    }

    private static function read(Node $at): self
    {
        $suites = array_map(static fn(Node $suite): string => $suite->text(), $at->field('suites')->items());

        return new self(
            $at->field('root')->text(),
            $at->field('autoloader')->text(),
            $at->field('script')->text(),
            $suites,
            $at->field(self::MUTANTS)->isPresent() && $at->field(self::MUTANTS)->boolean(),
        );
    }
}
