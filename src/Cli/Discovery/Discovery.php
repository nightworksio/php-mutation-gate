<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Discovery;

use function array_filter;

use Closure;

use function is_a;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\FirstParty;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Composer\Installed;
use NightWorksIO\MutationGate\Core\Composer\Manifest;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Registry\Origin;
use NightWorksIO\MutationGate\Extension\Extension;
use NightWorksIO\MutationGate\Extension\Extensions;

use function sprintf;

/**
 * Every extension the project's Composer manifests name, constructed and
 * registered. This is the one class that constructs a class by a name it
 * read, which is what naming an extension in `composer.json` asks for.
 */
final readonly class Discovery
{
    public function __construct(private Directory $project, private Directory $vendor)
    {
    }

    /** The registry of every declared extension, or of this package's own alone. */
    public function extensions(bool $firstPartyOnly): Extensions|CannotJudge
    {
        $declared = $this->declared();

        if ($declared instanceof CannotJudge) {
            return $declared;
        }

        return $this->register(array_filter(
            $declared,
            static fn(Declared $one): bool => ! $firstPartyOnly || $one->origin === FirstParty::PACKAGE,
        ));
    }

    /** @return list<Declared>|CannotJudge */
    private function declared(): array|CannotJudge
    {
        $root = $this->read($this->project, Manifest::fileIn(Path::root())->value(), Manifests::root(...));
        $installed = $this->read($this->vendor, Installed::fileIn(Path::root())->value(), Manifests::installed(...));

        return match (true) {
            $root instanceof CannotJudge => $root,
            $installed instanceof CannotJudge => $installed,
            default => [...$root, ...$installed],
        };
    }

    /**
     * @param  Closure(string, string): (list<Declared>|CannotJudge) $parse
     * @return list<Declared>|CannotJudge
     */
    private function read(Directory $directory, string $file, Closure $parse): array|CannotJudge
    {
        $manifest = $directory->read(Path::of($file));

        return match (true) {
            $manifest instanceof Contents => $parse($manifest->text(), $file),
            $manifest instanceof CannotJudge => $manifest,
            default => [],
        };
    }

    /** @param array<int, Declared> $declared */
    private function register(array $declared): Extensions|CannotJudge
    {
        $registry = new Extensions(Origin::of(FirstParty::PACKAGE));

        foreach ($declared as $one) {
            $registry = $this->add($registry, $one);

            if ($registry instanceof CannotJudge) {
                return $registry;
            }
        }

        return $registry;
    }

    private function add(Extensions $registry, Declared $one): Extensions|CannotJudge
    {
        if (! is_a($one->class, Extension::class, allow_string: true)) {
            return CannotJudge::because(sprintf(
                '%s names %s as a mutation-gate extension, and it is not a class that implements %s.',
                $one->origin,
                $one->class,
                Extension::class,
            ));
        }

        return $registry->merge(new ($one->class)()->extend(new Extensions(Origin::of($one->origin))));
    }
}
