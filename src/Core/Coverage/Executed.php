<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Coverage;

use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;

/**
 * What a coverage run's tests executed beyond its lines, where its report
 * states it: the methods of each file some test ran and the lines they
 * span, and the packages the tests loaded.
 */
final readonly class Executed
{
    /** @param ByPath<ExecutedMethods> $methods each file's executed methods */
    private function __construct(private ByPath $methods, private LoadedPackages $loaded)
    {
    }

    public static function unrecorded(): self
    {
        return new self(ByPath::none(), LoadedPackages::unrecorded());
    }

    /** This, with these methods of a file executed, after any it already holds for it. */
    public function executing(Path $file, ExecutedMethod ...$methods): self
    {
        if ($methods === []) {
            return $this;
        }

        $held = $this->methods->at($file, ExecutedMethods::none());

        return new self($this->methods->with($file, ExecutedMethods::of(...$held, ...$methods)), $this->loaded);
    }

    /** This, with what the tests loaded. */
    public function loading(LoadedPackages $loaded): self
    {
        return new self($this->methods, $loaded);
    }

    /** This, holding the methods of these files alone. */
    public function onlyFor(Paths $files): self
    {
        $methods = ByPath::none();

        foreach ($this->methods as $file => $executed) {
            $methods = $files->has($file) ? $methods->with($file, $executed) : $methods;
        }

        return new self($methods, $this->loaded);
    }

    /**
     * The methods of each file some test ran, for the files whose report
     * states them.
     *
     * @return ByPath<ExecutedMethods>
     */
    public function methods(): ByPath
    {
        return $this->methods;
    }

    /** What the tests loaded, where the run recorded it. */
    public function loaded(): LoadedPackages
    {
        return $this->loaded;
    }
}
