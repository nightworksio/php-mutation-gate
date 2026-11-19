<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function array_fill_keys;
use function ltrim;

use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * Where one file builds a reflection that can read a class constant by a
 * name no token spells: each `new` of a reflection class, read where it is
 * built. One built on a class written out, as `Owner::class`, `self::class`,
 * `parent::class` or a string, reads the constant where that class reaches
 * its owner, and a
 * `ReflectionClassConstant` only where the name it is built with is the
 * constant's or is not written out. One built on anything else, such as a
 * variable or an object, reads it where the file names the owner. A
 * reflection that reads it sits in the file that builds it, so the tests
 * that run that file judge the mutant, and the read is never ambiguous.
 *
 * @internal
 */
final readonly class Reflections
{
    /** The reflection classes that can read a constant, by their names in lower case. */
    public const array CLASSES = [
        'reflectionclass',
        self::CONSTANT,
        'reflectionenum',
        'reflectionobject',
    ];

    /** The reflection of one constant, built with its class and its name. */
    private const string CONSTANT = 'reflectionclassconstant';

    /** How many tokens past its name a class literal ends: `::`, then `class`, then what follows. */
    private const int LITERAL = 3;

    private function __construct(private Symbol $symbol, private Source $source, private Hierarchy $hierarchy)
    {
    }

    public static function of(Symbol $symbol, Source $source, Hierarchy $hierarchy): References
    {
        $reflections = new self($symbol, $source, $hierarchy);
        $found = References::none();

        foreach ($source->reflected() as $site) {
            $found = $found->and($reflections->built($site));
        }

        return $found;
    }

    /**
     * Where a file builds a reflection class that can read a constant, each
     * with what its arguments write out, read once with the file.
     *
     * @return list<ReflectionSite>
     */
    public static function sitesIn(Source $source): array
    {
        $sites = [];

        foreach (self::builtIn($source->tokens(), $source->scope()) as $class => $built) {
            foreach ($built as $at) {
                [$reflected, $after] = self::writtenAt($source, $at + Tokens::ARGUMENT);
                $named = $class === self::CONSTANT ? self::namedAt($source->tokens(), $after) : NotGiven::value();
                $sites[] = ReflectionSite::of($at, $reflected, $named);
            }
        }

        return $sites;
    }

    /**
     * Where a file builds each reflection class that can read a constant,
     * class by class in the order they are listed: each name right after a
     * `new` and right before a `(` that stands for one.
     *
     * @return array<string, list<int>>
     */
    private static function builtIn(Tokens $tokens, Scope $scope): array
    {
        $built = array_fill_keys(self::CLASSES, []);

        foreach ($tokens->indicesOf(T_NEW) as $new) {
            $named = self::namedAfter($tokens, $scope, $new);

            foreach (self::CLASSES as $class) {
                $built[$class] = $named->meet(Names::of($class)) ? [...$built[$class], $new + 1] : $built[$class];
            }
        }

        return $built;
    }

    /** What the name right after a `new` and right before a `(` stands for; nothing where there is none. */
    private static function namedAfter(Tokens $tokens, Scope $scope, int $new): Names
    {
        $at = $new + 1;

        return $tokens->is($at, ...Names::TOKENS) && $tokens->is($at + 1, '(')
            ? $scope->resolve($tokens->text($at))
            : Names::of();
    }

    /** What a reflection built at a site reads of the constant. */
    private function built(ReflectionSite $site): References
    {
        $shadowing = Shadowing::byConstant($this->symbol->name());
        $reflected = $site->reflected();
        $named = $site->named();
        $reads = $reflected instanceof Names
            ? $this->hierarchy->anyReaches($reflected, $this->symbol->owner(), $shadowing)
                && ($named instanceof NotGiven || $named === $this->symbol->name())
            : $this->source->namesOf($this->symbol->owner()) !== [];

        return $reads ? References::at(Site::in($this->source, $site->at())) : References::none();
    }

    /**
     * The class an argument writes out, with where the argument ends, or none
     * where it writes out no class: a class literal, or a string.
     *
     * @return array{Names|NotGiven, int}
     */
    private static function writtenAt(Source $source, int $argument): array
    {
        $tokens = $source->tokens();
        $literal = $argument + self::LITERAL;

        if ($tokens->is($argument, T_CONSTANT_ENCAPSED_STRING) && $tokens->is($argument + 1, ',', ')')) {
            return [Names::of(ltrim($tokens->unquoted($argument), '\\')), $argument + 1];
        }

        $isLiteral = $tokens->is($argument + 1, T_DOUBLE_COLON)
            && $tokens->is($argument + 2, T_CLASS)
            && $tokens->is($literal, ',', ')');

        return match (true) {
            ! $isLiteral => [NotGiven::value(), $argument],
            OwnMember::isSelf($tokens, $argument) => [$source->classAround($argument)->names(), $literal],
            OwnMember::isParent($tokens, $argument) => [$source->classAround($argument)->parents(), $literal],
            $tokens->is($argument, ...Names::TOKENS) => [
                $source->scope()->resolve($tokens->text($argument)),
                $literal,
            ],
            default => [NotGiven::value(), $argument],
        };
    }

    /**
     * The name a reflection of one constant is built with, after the class
     * argument that ends at a token; none where it is not written out.
     */
    private static function namedAt(Tokens $tokens, int $end): string|NotGiven
    {
        $name = $end + 1;
        $written = $tokens->is($end, ',')
            && $tokens->is($name, T_CONSTANT_ENCAPSED_STRING)
            && $tokens->is($name + 1, ')');

        return $written ? $tokens->unquoted($name) : NotGiven::value();
    }
}
