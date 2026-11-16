<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Discovery;

use function array_filter;
use function array_values;

use Closure;
use Error;

use function is_a;

use LogicException;
use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Composer\Installed;
use NightWorksIO\MutationGate\Core\Composer\Manifest;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Registry\Origin;
use NightWorksIO\MutationGate\Core\ThisPackage;
use NightWorksIO\MutationGate\Extension\Extension;
use NightWorksIO\MutationGate\Extension\Extensions;
use RuntimeException;

use function sprintf;

/**
 * Every extension the project's Composer manifests name, constructed and
 * registered, and, under the gate's PHAR, those the gate's own manifest in
 * the archive names, in place of any the first-party packages the project
 * installs, or is, name (ADR-0022, ADR-0140). This is the one class that
 * constructs a class by a name it read, which is what naming an extension in
 * `composer.json` asks for.
 *
 * @internal
 */
final readonly class Discovery
{
    public function __construct(private Directory $project, private Directory $vendor, private Directory|NotGiven $own)
    {
    }

    /** The registry of every declared extension, or of the first-party packages' alone. */
    public function extensions(bool $firstPartyOnly): Extensions|CannotJudge
    {
        $declared = $this->declared();

        if ($declared instanceof CannotJudge) {
            return $declared;
        }

        return $this->register(array_filter(
            $declared,
            static fn(Declared $one): bool => ! $firstPartyOnly || Origin::of($one->origin)->isFirstParty(),
        ));
    }

    /** @return list<Declared>|CannotJudge */
    private function declared(): array|CannotJudge
    {
        $root = $this->read($this->project, Manifest::fileIn(Path::root())->value(), Manifests::root(...));
        $installed = $this->read($this->vendor, Installed::fileIn(Path::root())->value(), Manifests::installed(...));
        $own = $this->own instanceof Directory
            ? $this->read($this->own, Manifest::fileIn(Path::root())->value(), Manifests::root(...))
            : [];

        return match (true) {
            $own instanceof CannotJudge => $own,
            $root instanceof CannotJudge => $root,
            $installed instanceof CannotJudge => $installed,
            default => [...$own, ...$this->besideOwn([...$root, ...$installed])],
        };
    }

    /**
     * What the project's manifests declare, less the first-party packages'
     * under the gate's PHAR, whose own manifest declares those: a copy of the
     * gate the project installs, or is, names classes of its own, not the
     * archive's.
     *
     * @param  list<Declared> $declared
     * @return list<Declared>
     */
    private function besideOwn(array $declared): array
    {
        return $this->own instanceof Directory
            ? array_values(array_filter(
                $declared,
                static fn(Declared $one): bool => ! Origin::of($one->origin)->isFirstParty(),
            ))
            : $declared;
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
        $registry = Extensions::of(Origin::of(ThisPackage::COMPOSER));

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

        try {
            return $registry->merge(new ($one->class)()->extend(Extensions::of(Origin::of($one->origin))));
        } catch (Error|LogicException|RuntimeException $failed) {
            return CannotJudge::because(sprintf(
                '%s names %s as a mutation-gate extension, and it failed as it started: %s',
                $one->origin,
                $one->class,
                $failed->getMessage(),
            ));
        }
    }
}
