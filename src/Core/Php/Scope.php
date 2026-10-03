<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function array_filter;
use function array_key_exists;
use function array_last;
use function array_map;
use function array_values;
use function count;
use function explode;
use function ltrim;
use function mb_strlen;
use function mb_strtolower;
use function mb_substr;

use PhpToken;

use function sprintf;
use function str_starts_with;

/**
 * The namespace a file declares and the names it imports, which together say
 * what a name written in it stands for.
 */
final readonly class Scope
{
    /** What ends one clause of a `use` statement. */
    private const array CLAUSE_ENDS = [',', '}', ';'];

    /** How a name relative to the current namespace begins. */
    private const string RELATIVE = 'namespace\\';

    /**
     * @param array<string, non-empty-list<string>> $imports every name imported under each alias, in the order
     *                                                       imported, by the alias in lower case
     */
    private function __construct(private string $namespace, private array $imports)
    {
    }

    public static function global(): self
    {
        return new self('', []);
    }

    /** This scope, inside a namespace. */
    public function inside(string $namespace): self
    {
        return new self($namespace, $this->imports);
    }

    /** This scope, with every name a `use` statement imports, given as its significant tokens. */
    public function importing(PhpToken ...$statement): self
    {
        $imports = $this->imports;
        $prefix = '';
        $clause = [];

        foreach ($statement as $token) {
            [$prefix, $clause, $imports] = match (true) {
                $token->is('{') => [$this->prefixIn($clause), [], $imports],
                $token->is(self::CLAUSE_ENDS) => [$prefix, [], $this->withImport($imports, $prefix, $clause)],
                default => [$prefix, [...$clause, $token], $imports],
            };
        }

        return new self($this->namespace, $imports);
    }

    /** A class or function declared here, fully qualified. */
    public function declared(string $name): string
    {
        return ltrim(sprintf('%s\\%s', $this->namespace, $name), '\\');
    }

    /**
     * What a name written here may stand for. An unqualified name that is not
     * imported may be the namespace's own or, for a function, the global one.
     */
    public function resolve(string $name): Names
    {
        if (str_starts_with($name, '\\')) {
            return Names::of(ltrim($name, '\\'));
        }

        if (str_starts_with(mb_strtolower($name), self::RELATIVE)) {
            return Names::of($this->declared(mb_substr($name, mb_strlen(self::RELATIVE))));
        }

        $imported = $this->importedAs($name);

        return $imported === [] ? Names::of($this->declared($name), $name) : Names::of(array_last($imported));
    }

    /**
     * What a name written here may stand for, of whatever kind it is: where a
     * class and a function or constant are imported under one alias, each of
     * them, as PHP keeps the kinds apart.
     */
    public function resolveAny(string $name): Names
    {
        $imported = $this->importedAs($name);

        return $imported === [] ? $this->resolve($name) : Names::of(...$imported);
    }

    /**
     * Every name a name written here stands for through an import, in the
     * order imported; none where its first segment is not imported.
     *
     * @return list<string>
     */
    private function importedAs(string $name): array
    {
        $segments = explode('\\', $name, 2);
        $alias = mb_strtolower($segments[0]);

        return array_map(
            static fn(string $imported): string => array_key_exists(1, $segments)
                ? sprintf('%s\\%s', $imported, $segments[1])
                : $imported,
            array_key_exists($alias, $this->imports) ? $this->imports[$alias] : [],
        );
    }

    /**
     * These imports, with the name one clause of a `use` statement imports
     * under its alias; as they are for a clause that names nothing.
     *
     * @param  array<string, non-empty-list<string>> $imports
     * @param  list<PhpToken>                        $clause
     * @return array<string, non-empty-list<string>>
     */
    private function withImport(array $imports, string $prefix, array $clause): array
    {
        $names = $this->namesIn($clause);

        if ($names === []) {
            return $imports;
        }

        $name = ltrim(sprintf('%s%s', $prefix, $names[0]->text), '\\');
        $imports[mb_strtolower(count($names) > 1 ? $names[1]->text : $this->lastSegmentOf($name))][] = $name;

        return $imports;
    }

    /**
     * The prefix a group of imports shares, such as `App\Domain\`.
     *
     * @param list<PhpToken> $clause
     */
    private function prefixIn(array $clause): string
    {
        $names = $this->namesIn($clause);

        return $names === [] ? '' : sprintf('%s\\', $names[0]->text);
    }

    /**
     * The names among a clause's tokens: the name imported, and its alias.
     *
     * @param  list<PhpToken> $clause
     * @return list<PhpToken>
     */
    private function namesIn(array $clause): array
    {
        return array_values(array_filter($clause, static fn(PhpToken $token): bool => $token->is(Names::UNRELATIVE)));
    }

    private function lastSegmentOf(string $name): string
    {
        $segments = explode('\\', $name);

        return array_last($segments);
    }
}
