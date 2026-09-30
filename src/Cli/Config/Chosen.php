<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Config;

use function array_map;
use function class_exists;

use Closure;
use Error;

use function is_a;

use LogicException;
use NightWorksIO\MutationGate\Cli\Registry\Lookup;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Choice;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Registry\Origin;
use NightWorksIO\MutationGate\Extension\Configurable;
use NightWorksIO\MutationGate\Extension\Extension;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Port\CiPlan;
use NightWorksIO\MutationGate\Port\ProofStore;
use NightWorksIO\MutationGate\Port\Reporter;
use NightWorksIO\MutationGate\Port\Runner;
use NightWorksIO\MutationGate\Port\TreeSource;
use RuntimeException;

use function sprintf;

/**
 * The adapters and extensions a config chooses, built (ADR-0002). A name is
 * looked up among what extensions registered. A class is built from its
 * options through `Configurable`, and checks them itself; its problems are
 * reported under the setting's `with`, as the gate's own are. This is the
 * one file besides extension discovery that builds a class by a name it read.
 */
final readonly class Chosen
{
    public function __construct(private Extensions $extensions)
    {
    }

    public function runner(Choice $choice): Runner|Invalid|CannotJudge
    {
        return $this->built('runner', Runner::class, $choice, Lookup::in($this->extensions)->runner(...));
    }

    public function treeSource(Choice $choice): TreeSource|Invalid|CannotJudge
    {
        return $this->built('treeSource', TreeSource::class, $choice, Lookup::in($this->extensions)->treeSource(...));
    }

    public function proofStore(Choice $choice): ProofStore|Invalid|CannotJudge
    {
        return $this->built('proofs.store', ProofStore::class, $choice, Lookup::in($this->extensions)->proofStore(...));
    }

    public function ciPlan(Choice $choice): CiPlan|Invalid|CannotJudge
    {
        return $this->built('ci.plan', CiPlan::class, $choice, Lookup::in($this->extensions)->ciPlan(...));
    }

    /** The reporter of the `reports` entry at this index. */
    public function reporter(Choice $choice, int $index): Reporter|Invalid|CannotJudge
    {
        return $this->built(
            sprintf('reports[%d]', $index),
            Reporter::class,
            $choice,
            Lookup::in($this->extensions)->reporter(...),
        );
    }

    /**
     * The registry with the extension classes a config names added, each as coming from the config file.
     *
     * @param iterable<string> $classes
     */
    public function withExtensions(iterable $classes, string $file): Extensions|CannotJudge
    {
        $registry = $this->extensions;

        foreach ($classes as $class) {
            $extended = $this->extended($registry, $class, $file);

            if ($extended instanceof CannotJudge) {
                return $extended;
            }

            $registry = $extended;
        }

        return $registry;
    }

    private function extended(Extensions $registry, string $class, string $file): Extensions|CannotJudge
    {
        if (! is_a($class, Extension::class, allow_string: true)) {
            return CannotJudge::because(sprintf(
                '%s names %s in extensions, and it is not a class that implements %s.',
                $file,
                $class,
                Extension::class,
            ));
        }

        try {
            return $registry->merge(new $class()->extend(new Extensions(Origin::of($file))));
        } catch (Error|LogicException|RuntimeException $failed) {
            return CannotJudge::because(sprintf(
                '%s names %s in extensions, and it failed as it started: %s',
                $file,
                $class,
                $failed->getMessage(),
            ));
        }
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>                                       $port
     * @param  Closure(Name, Options): (T|Invalid|CannotJudge)        $registered
     * @return T|Invalid|CannotJudge
     */
    private function built(string $setting, string $port, Choice $choice, Closure $registered): object
    {
        $use = $choice->use();
        $built = $use instanceof Name
            ? $this->named($use, $registered($use, $choice->options()))
            : $this->fromClass($use->value(), $port, $choice->options());

        return $built instanceof Invalid ? $this->under(sprintf('%s.with', $setting), $built) : $built;
    }

    /**
     * What a name built, and where nothing is registered under it but a class in the global namespace has it,
     * how to choose that class instead.
     *
     * @template T of object
     *
     * @param  T|Invalid|CannotJudge $built
     * @return T|Invalid|CannotJudge
     */
    private function named(Name $name, object $built): object
    {
        return $built instanceof CannotJudge && class_exists($name->value())
            ? CannotJudge::because(sprintf(
                '%s A class is written with its namespace, so the class %s is \\%s.',
                $built->why(),
                $name->value(),
                $name->value(),
            ))
            : $built;
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>       $port
     * @return T|Invalid|CannotJudge
     */
    private function fromClass(string $class, string $port, Options $options): object
    {
        if (! is_a($class, Configurable::class, allow_string: true) || ! is_a($class, $port, allow_string: true)) {
            return CannotJudge::because(sprintf(
                '%s is not a class that implements %s and %s, so a config cannot choose it.',
                $class,
                $port,
                Configurable::class,
            ));
        }

        $built = $class::fromOptions($options);

        return $built instanceof $port || $built instanceof Invalid
            ? $built
            : CannotJudge::because(sprintf('%s::fromOptions() built something other than a %s.', $class, $port));
    }

    /** A class's problems with its options, each under the setting's `with`. */
    private function under(string $with, Invalid $invalid): Invalid
    {
        return Invalid::because(...array_map(
            static fn(Problem $problem): Problem => Problem::at(
                $problem->path() === '' ? $with : sprintf('%s.%s', $with, $problem->path()),
                $problem->message(),
            ),
            [...$invalid],
        ));
    }
}
