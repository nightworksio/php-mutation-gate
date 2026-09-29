<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_any;
use function array_keys;
use function array_map;
use function basename;
use function explode;
use function file_get_contents;
use function implode;
use function in_array;
use function is_file;
use function is_string;
use function preg_match;
use function preg_quote;

use RuntimeException;

use function sprintf;
use function trim;

/** What ARCHITECTURE.md claims is enforced, and everything that could enforce it. */
final readonly class Rules
{
    /**
     * Files that name identifiers without enforcing anything: the reader of
     * the rules, their checker, and the fixture registry, which holds a
     * violation of every rule verbatim.
     */
    private const array THAT_ONLY_NAME_THEM = ['Rules.php', 'TheRulesAreRealTest.php', 'Fixtures.php'];

    /** The configuration files a rule's identifier can be carried in. */
    private const array CONFIGURATION = [
        'phpstan.neon',
        'phpunit.xml',
        'composer.json',
        'rector.php',
        'composer-dependency-analyser.php',
        '.github/workflows/ci.yml',
    ];

    /**
     * Every rule ARCHITECTURE.md documents, with the mechanism it claims.
     *
     * @return array<string, string> identifier => claimed enforcement
     */
    public static function documented(): array
    {
        return self::read(self::text('ARCHITECTURE.md'));
    }

    /**
     * Every rule a document's tables hold, with the mechanism each claims.
     *
     * @return array<string, string>
     */
    public static function read(string $document): array
    {
        $rules = [];

        foreach (explode("\n", $document) as $line) {
            if (preg_match('/^\|\s*\*{0,2}([A-Z]\d{1,2})\*{0,2}\s*\|\s*(.+?)\s*\|\s*(.+?)\s*\|\s*$/u', $line, $found) === 1) {
                $rules[$found[1]] = trim($found[3]);
            }
        }

        if ($rules === []) {
            throw new RuntimeException('No rules were read out of ARCHITECTURE.md, so nothing would be checked against it.');
        }

        return $rules;
    }

    /**
     * Everything that could carry a rule's identifier, as text: the
     * configuration, every PHP file under tests and phpstan, and src.
     *
     * @return list<string>
     */
    public static function enforcementSources(): array
    {
        return [
            ...array_map(self::text(...), self::CONFIGURATION),
            ...self::php('tests'),
            ...self::php('phpstan'),
            ...self::php('src'),
        ];
    }

    /**
     * Where each kind of mechanism a rule can claim lives.
     *
     * @return array<string, list<string>>
     */
    public static function whereEachKindLives(): array
    {
        return [
            'arch' => self::php('tests/Arch'),
            'phpstan' => [...self::php('phpstan'), self::text('phpstan.neon')],
        ];
    }

    /** Whether a claim names a kind of mechanism, read as a word and not as a hyphenated tag. */
    public static function claimsTheKind(string $claim, string $kind): bool
    {
        return preg_match(sprintf('/(?<![-\w])%s(?![-\w])/i', preg_quote($kind, '/')), $claim) === 1;
    }

    /**
     * Whether any of these sources carries a rule's identifier, bounded so that
     * `H1` is not found inside `H10`.
     *
     * @param list<string> $sources
     */
    public static function carriesTheRule(string $id, array $sources): bool
    {
        $token = sprintf('/(?<![-A-Za-z0-9])%s(?![0-9A-Za-z])/', preg_quote($id, '/'));

        return array_any($sources, static fn(string $source): bool => preg_match($token, $source) === 1);
    }

    /** Every identifier the tables hold, for a message. */
    public static function listed(): string
    {
        return implode(', ', array_map(strval(...), array_keys(self::documented())));
    }

    /** A file of the repository, as text, or nothing where it is not there. */
    private static function text(string $path): string
    {
        $contents = is_file(Tree::at($path)) ? file_get_contents(Tree::at($path)) : '';

        return is_string($contents) ? $contents : '';
    }

    /**
     * Every PHP file under a directory that may enforce a rule, as text.
     *
     * @return list<string>
     */
    private static function php(string $directory): array
    {
        $found = [];

        foreach (Tree::filesUnder($directory) as $path) {
            if (! in_array(basename($path), self::THAT_ONLY_NAME_THEM, strict: true)) {
                $found[] = self::text($path);
            }
        }

        return $found;
    }
}
