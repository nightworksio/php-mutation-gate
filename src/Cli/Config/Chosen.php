<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Config;

use function array_map;

use Closure;

use function is_a;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Choice;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Extension\Configurable;
use NightWorksIO\MutationGate\Extension\Extension;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Extension\Options;
use NightWorksIO\MutationGate\Extension\Origin;
use NightWorksIO\MutationGate\Port\CiPlan;
use NightWorksIO\MutationGate\Port\ProofStore;
use NightWorksIO\MutationGate\Port\Reporter;
use NightWorksIO\MutationGate\Port\Runner;
use NightWorksIO\MutationGate\Port\TreeSource;

use function sprintf;
use function str_contains;

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
        return self::built('runner', Runner::class, $choice, $this->extensions->runner(...));
    }

    public function treeSource(Choice $choice): TreeSource|Invalid|CannotJudge
    {
        return self::built('treeSource', TreeSource::class, $choice, $this->extensions->treeSource(...));
    }

    public function proofStore(Choice $choice): ProofStore|Invalid|CannotJudge
    {
        return self::built('proofs.store', ProofStore::class, $choice, $this->extensions->proofStore(...));
    }

    public function ciPlan(Choice $choice): CiPlan|Invalid|CannotJudge
    {
        return self::built('ci.plan', CiPlan::class, $choice, $this->extensions->ciPlan(...));
    }

    /** The reporter of the `reports` entry at this index. */
    public function reporter(Choice $choice, int $index): Reporter|Invalid|CannotJudge
    {
        return self::built(sprintf('reports[%d]', $index), Reporter::class, $choice, $this->extensions->reporter(...));
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
            $extended = self::extended($registry, $class, $file);

            if ($extended instanceof CannotJudge) {
                return $extended;
            }

            $registry = $extended;
        }

        return $registry;
    }

    private static function extended(Extensions $registry, string $class, string $file): Extensions|CannotJudge
    {
        return is_a($class, Extension::class, allow_string: true)
            ? $registry->merge(new $class()->extend(new Extensions(Origin::of($file))))
            : CannotJudge::because(sprintf(
                '%s names %s in extensions, and it is not a class that implements %s.',
                $file,
                $class,
                Extension::class,
            ));
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>                                       $port
     * @param  Closure(Name, Options): (T|Invalid|CannotJudge)        $registered
     * @return T|Invalid|CannotJudge
     */
    private static function built(string $setting, string $port, Choice $choice, Closure $registered): object
    {
        $options = Options::ofJson($choice->options());
        $built = str_contains($choice->use(), '\\')
            ? self::fromClass($choice->use(), $port, $options)
            : $registered(Name::of($choice->use()), $options);

        return $built instanceof Invalid ? self::under(sprintf('%s.with', $setting), $built) : $built;
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>       $port
     * @return T|Invalid|CannotJudge
     */
    private static function fromClass(string $class, string $port, Options $options): object
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
    private static function under(string $with, Invalid $invalid): Invalid
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
