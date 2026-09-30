<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function mb_strtolower;

/**
 * Where one file creates an instance of a class, which gives each instance
 * property its default: `new` of the owner or of a class that reaches it,
 * `new self`, `new static` and `new parent` in such a class. `new` of a class
 * held in a variable is a creation the scan cannot follow.
 */
final readonly class Creations
{
    private function __construct(private Symbol $symbol, private Source $source, private Hierarchy $hierarchy)
    {
    }

    public static function of(Symbol $symbol, Source $source, Hierarchy $hierarchy): References
    {
        $creations = new self($symbol, $source, $hierarchy);
        $found = References::none();

        foreach ($source->tokens()->indicesOf(T_NEW) as $new) {
            $found = $found->and($creations->created($new));
        }

        return $found;
    }

    private function created(int $new): References
    {
        $tokens = $this->source->tokens();
        $class = $new + 1;
        $said = mb_strtolower($tokens->text($class));
        $around = $this->source->classAround($new);

        return match (true) {
            $tokens->is($class, T_STATIC) || $said === 'self' => $this->reachedBy($around->names(), $new),
            $said === 'parent' => $this->reachedBy($around->parents(), $new),
            $tokens->is($class, ...Names::TOKENS) => $this->reachedBy(
                $this->source->scope()->resolve($tokens->text($class)),
                $new,
            ),
            $tokens->is($class, T_VARIABLE, '(') => References::unknown(),
            default => References::none(),
        };
    }

    private function reachedBy(Names $names, int $at): References
    {
        return $this->hierarchy->anyReaches($names, $this->symbol->owner(), Shadowing::none())
            ? References::at(Site::in($this->source, $at))
            : References::none();
    }
}
