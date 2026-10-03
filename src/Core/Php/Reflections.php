<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

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
 */
final readonly class Reflections
{
    /** The reflection classes that can read a constant, by their names in lower case. */
    private const array CLASSES = [
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
        $tokens = $source->tokens();
        $found = References::none();

        foreach (self::CLASSES as $class) {
            foreach ($source->namesOf($class) as $at) {
                $built = $tokens->is($at - 1, T_NEW) && $tokens->is($at + 1, '(');
                $found = $built ? $found->and($reflections->built($at, $class)) : $found;
            }
        }

        return $found;
    }

    /** What a reflection built at a token reads of the constant. */
    private function built(int $at, string $class): References
    {
        $argument = $at + Tokens::ARGUMENT;
        [$reflected, $after] = $this->writtenAt($argument);
        $shadowing = Shadowing::byConstant($this->symbol->name());
        $reads = $reflected instanceof Names
            ? $this->hierarchy->anyReaches($reflected, $this->symbol->owner(), $shadowing)
                && ($class !== self::CONSTANT || $this->mayName($after))
            : $this->source->namesOf($this->symbol->owner()) !== [];

        return $reads ? References::at(Site::in($this->source, $at)) : References::none();
    }

    /**
     * The class an argument writes out, with where the argument ends, or none
     * where it writes out no class: a class literal, or a string.
     *
     * @return array{Names|NotGiven, int}
     */
    private function writtenAt(int $argument): array
    {
        $tokens = $this->source->tokens();
        $literal = $argument + self::LITERAL;

        if ($tokens->is($argument, T_CONSTANT_ENCAPSED_STRING) && $tokens->is($argument + 1, ',', ')')) {
            return [Names::of(ltrim($tokens->unquoted($argument), '\\')), $argument + 1];
        }

        $isLiteral = $tokens->is($argument + 1, T_DOUBLE_COLON)
            && $tokens->is($argument + 2, T_CLASS)
            && $tokens->is($literal, ',', ')');

        return match (true) {
            ! $isLiteral => [NotGiven::value(), $argument],
            OwnMember::isSelf($tokens, $argument) => [$this->source->classAround($argument)->names(), $literal],
            OwnMember::isParent($tokens, $argument) => [$this->source->classAround($argument)->parents(), $literal],
            $tokens->is($argument, ...Names::TOKENS) => [
                $this->source->scope()->resolve($tokens->text($argument)),
                $literal,
            ],
            default => [NotGiven::value(), $argument],
        };
    }

    /**
     * Whether the name a reflection of one constant is built with, after the
     * class argument that ends at a token, is the constant's or not written out.
     */
    private function mayName(int $end): bool
    {
        $tokens = $this->source->tokens();
        $name = $end + 1;
        $written = $tokens->is($end, ',')
            && $tokens->is($name, T_CONSTANT_ENCAPSED_STRING)
            && $tokens->is($name + 1, ')');

        return ! $written || $tokens->unquoted($name) === $this->symbol->name();
    }
}
