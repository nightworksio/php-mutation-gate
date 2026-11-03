<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Test;

use function array_filter;
use function array_key_exists;
use function array_map;
use function array_values;
use function in_array;
use function mb_stripos;
use function mb_strtolower;

use NightWorksIO\MutationGate\Core\Assertion\Call;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Format\Bytes;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Php\DependsReader;
use NightWorksIO\MutationGate\Core\Php\Names;
use NightWorksIO\MutationGate\Core\Php\Tokens;

use function sprintf;
use function str_starts_with;
use function usort;

/**
 * Where each test a file may declare stands, by the method its coverage id
 * names: each method of a named class (MethodDeclarations), and each Pest
 * test called with a literal description (PestDeclarations). A method the
 * file declares twice is left out, as no id tells which it is. Whether a
 * test may depend on another is read too: from a Pest test's `->depends()`,
 * a PHPUnit attribute whose name begins with `Depends`, or an `@depends`
 * annotation.
 *
 * @internal
 */
final readonly class TestDeclarations
{
    /** How a docblock declares a dependence on another test. */
    private const string ANNOTATED = '@depends';

    /**
     * @param array<string, Declared> $declared each test declared once, by its method, in the order they stand
     * @param list<Declared>          $imports  each import the file declares at its top, by its spelling
     */
    private function __construct(
        private string $text,
        private array $declared,
        private array $imports,
        private bool $dependent,
    ) {
    }

    public static function in(Contents $file): self
    {
        $tokens = Tokens::in($file);
        $all = [...MethodDeclarations::in($tokens), ...PestDeclarations::in($tokens)];
        usort($all, static fn(Declared $one, Declared $other): int => $one->from() <=> $other->from());
        $declared = [];
        $twice = [];

        foreach ($all as $test) {
            $twice = array_key_exists($test->method(), $declared) ? [...$twice, $test->method()] : $twice;
            $declared[$test->method()] = $test;
        }

        foreach ($twice as $method) {
            unset($declared[$method]);
        }

        return new self(
            $file->text(),
            $declared,
            ImportDeclarations::in($tokens),
            self::declaresDependence($tokens, $file),
        );
    }

    /** Whether a test of the file may depend on another, so that a change to one may change what another does. */
    public function dependent(): bool
    {
        return $this->dependent;
    }

    /** The source of the declaration of a method; none where the file declares no such test once. */
    public function of(string $method): string|NotGiven
    {
        if (! array_key_exists($method, $this->declared)) {
            return NotGiven::value();
        }

        $test = $this->declared[$method];

        return Bytes::slice($this->text, $test->from(), $test->to() - $test->from());
    }

    /**
     * What the file's tests share: the file, with the declaration of each of
     * these methods it holds left out, so that a test added or removed
     * leaves it as it was.
     *
     * @param list<string> $methods
     */
    public function shared(array $methods): string
    {
        return $this->without(array_values(array_filter(
            $this->declared,
            static fn(Declared $test): bool => in_array($test->method(), $methods, strict: true),
        )));
    }

    /**
     * What the file's tests share but its imports: the file, with the
     * declaration of each of these methods and each import left out.
     *
     * @param list<string> $methods
     */
    public function sharedButImports(array $methods): string
    {
        $left = [...$this->imports];

        foreach ($this->declared as $test) {
            $left = in_array($test->method(), $methods, strict: true) ? [...$left, $test] : $left;
        }

        return $this->without($left);
    }

    /** @return list<string> each import the file declares at its top, spelt as a statement, in the order they stand */
    public function imports(): array
    {
        return array_map(static fn(Declared $import): string => $import->method(), $this->imports);
    }

    /**
     * The file, with each of these declarations left out.
     *
     * @param list<Declared> $left
     */
    private function without(array $left): string
    {
        usort($left, static fn(Declared $one, Declared $other): int => $one->from() <=> $other->from());
        $kept = '';
        $from = 0;

        foreach ($left as $declaration) {
            $kept = sprintf('%s%s', $kept, Bytes::slice($this->text, $from, $declaration->from() - $from));
            $from = $declaration->to();
        }

        return sprintf('%s%s', $kept, Bytes::from($this->text, $from));
    }

    /** Whether the file declares that a test depends on another, by a chained call, an attribute or an annotation. */
    private static function declaresDependence(Tokens $tokens, Contents $file): bool
    {
        foreach ($tokens->indicesOf(...Names::TOKENS) as $at) {
            $named = str_starts_with(mb_strtolower(Call::nameAt($tokens, $at)), DependsReader::SPELT);
            $declaring = $tokens->is($at - 1, T_OBJECT_OPERATOR) || self::inAttribute($tokens, $at);

            if ($named && $declaring) {
                return true;
            }
        }

        return mb_stripos($file->text(), self::ANNOTATED) !== false;
    }

    /** Whether the token at an index stands directly inside an attribute group. */
    private static function inAttribute(Tokens $tokens, int $at): bool
    {
        return $tokens->is($tokens->enclosing($at), T_ATTRIBUTE);
    }
}
