<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpStan;

use function array_any;
use function array_map;
use function fnmatch;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\ShellPattern;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;

use function rtrim;
use function sprintf;
use function str_starts_with;
use function strpbrk;

/**
 * The files PHPStan analyses, as `phpstan dump-parameters --json` gives
 * them (ADR-0020, decision 7): under one of its `paths`, and under none of
 * its `excludePaths`, each a file, a directory, or a pattern PHPStan
 * matches with `fnmatch`. Every one is absolute, as PHPStan resolves it.
 */
final readonly class Scope
{
    private const string UNREAD = 'PHPStan did not say which files it analyses: %s';

    /** The exclusions PHPStan dumps: of what it neither analyses nor scans, and of what it scans alone. */
    private const array EXCLUSIONS = ['analyseAndScan', 'analyse'];

    /**
     * @param list<string> $paths
     * @param list<string> $excluded
     */
    private function __construct(private array $paths, private array $excluded)
    {
    }

    /** The scope PHPStan's dumped parameters give; none where they cannot be read. */
    public static function dumped(string $json): self|CannotJudge
    {
        $parameters = Node::decode($json, 'the parameters');

        try {
            $paths = self::texts($parameters->field('paths'));
            $excluded = self::exclusions($parameters->field('excludePaths'));
        } catch (NotInShape $refused) {
            return CannotJudge::because(sprintf(self::UNREAD, $refused->getMessage()));
        }

        return new self($paths, $excluded);
    }

    /** Whether PHPStan analyses this file, by its absolute path. */
    public function holds(string $file): bool
    {
        return array_any($this->paths, static fn(string $path): bool => self::under($file, $path))
            && ! array_any($this->excluded, static fn(string $excluded): bool => self::under($file, $excluded));
    }

    /**
     * Every exclusion, of either kind; a list of them, as an older config
     * writes it, is of both.
     *
     * @return list<string>
     *
     * @throws NotInShape
     */
    private static function exclusions(Node $excludePaths): array
    {
        if ($excludePaths->kind() === Kind::List) {
            return self::texts($excludePaths);
        }

        $excluded = [];

        foreach (self::EXCLUSIONS as $kind) {
            $exclusions = $excludePaths->field($kind);
            $excluded = $exclusions->isPresent() ? [...$excluded, ...self::texts($exclusions)] : $excluded;
        }

        return $excluded;
    }

    /**
     * @return list<string>
     *
     * @throws NotInShape
     */
    private static function texts(Node $list): array
    {
        return array_map(static fn(Node $item): string => $item->text(), $list->items());
    }

    /** Whether a file is this path, inside it, or matched by it as a pattern. */
    private static function under(string $file, string $path): bool
    {
        return $file === $path
            || str_starts_with($file, sprintf('%s/', rtrim($path, '/')))
            || (strpbrk($path, ShellPattern::WILDCARDS) !== false && fnmatch($path, $file));
    }
}
