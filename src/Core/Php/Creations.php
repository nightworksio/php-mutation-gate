<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function mb_strtolower;

/**
 * Where one file creates an instance of a class, which gives each instance
 * property its default: `new` of the owner or of a class that reaches it,
 * `new self`, `new static` and `new parent` in such a class, and the body of
 * the constructor such a class declares, which runs however the instance is
 * made, by a container or by reflection too. `new` of a class held in a
 * variable is a creation the scan cannot follow.
 *
 * @internal
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

        foreach ($source->tokens()->indicesOf(T_FUNCTION) as $function) {
            $found = $found->and($creations->constructed($function));
        }

        return $found;
    }

    private function created(int $new): References
    {
        $tokens = $this->source->tokens();
        $class = $new + 1;
        $around = $this->source->classAround($new);
        $site = Site::in($this->source, $new);

        return match (true) {
            $tokens->is($class, T_STATIC),
            OwnMember::isSelf($tokens, $class) => $this->reachedBy($around->names(), $site),
            OwnMember::isParent($tokens, $class) => $this->reachedBy($around->parents(), $site),
            $tokens->is($class, ...Names::TOKENS) => $this->reachedBy(
                $this->source->scope()->resolve($tokens->text($class)),
                $site,
            ),
            $tokens->is($class, T_VARIABLE, '(') => References::unknown(),
            default => References::none(),
        };
    }

    /** The body of a constructor a `function` at an index declares in a class that reaches the owner, run by it. */
    private function constructed(int $function): References
    {
        $tokens = $this->source->tokens();
        $name = $tokens->functionName($function);
        $body = $this->source->shape()->bodyOf($function);
        $constructs = $name !== Tokens::NONE
            && $body !== Tokens::NONE
            && mb_strtolower($tokens->text($name)) === Enclosing::CONSTRUCTOR;

        if (! $constructs) {
            return References::none();
        }

        $last = $this->source->lineOf($tokens->closing($body));
        $run = Site::runBy($this->source, $function, $this->source->lineOf($body), $last);

        return $this->reachedBy($this->source->classAround($function)->names(), $run);
    }

    private function reachedBy(Names $names, Site $site): References
    {
        return $this->hierarchy->anyReaches($names, $this->symbol->owner(), Shadowing::none())
            ? References::at($site)
            : References::none();
    }
}
