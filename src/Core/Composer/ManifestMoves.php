<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Composer;

use function array_diff_assoc;
use function array_key_exists;
use function array_keys;
use function array_map;
use function ksort;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;

use function sort;

use const SORT_STRING;

use function sprintf;
use function strval;

/**
 * What a change to a `composer.json` moved (ADR-0056): each package, by
 * name, whose line under one of the package lists was added, removed or
 * changed, and whether a key moved that decides how the project installs and
 * loads, such as `autoload`, `config`, `extra` or `minimum-stability`. The
 * keys that only describe the package or name commands to run, such as
 * `description`, `support` or `scripts`, move nothing.
 *
 * @internal
 */
final readonly class ManifestMoves
{
    private const string UNREADABLE = 'A composer.json, at the base or now, is not a JSON object of Composer\'s keys.';

    /** @param list<string> $packages */
    private function __construct(private array $packages, private bool $beyond)
    {
    }

    /** What moved from the manifest at the base to the manifest now, or why either cannot be read as one. */
    public static function between(Contents $before, Contents $now): self|CannotJudge
    {
        try {
            [$then, $thenRest] = self::read($before);
            [$after, $afterRest] = self::read($now);
        } catch (NotInShape) {
            return CannotJudge::because(self::UNREADABLE);
        }

        $moved = [];

        foreach (array_keys($then + $after) as $list) {
            $was = self::listOf($then, $list);
            $is = self::listOf($after, $list);
            $moved += array_diff_assoc($was, $is) + array_diff_assoc($is, $was);
        }

        $packages = array_map(strval(...), array_keys($moved));
        sort($packages, SORT_STRING);

        return new self($packages, $thenRest !== $afterRest);
    }

    /** @return list<string> each package whose line moved in any list, by name, in byte order */
    public function packages(): array
    {
        return $this->packages;
    }

    /** Whether a key besides the package lists and those that only describe moved, such as `autoload`. */
    public function beyondPackages(): bool
    {
        return $this->beyond;
    }

    /**
     * The manifest's package lists, each by its name, with each package's
     * constraint by the package's name, and every other key that decides, by
     * its name.
     *
     * @return array{array<string, array<string, string>>, array<string, string>}
     */
    private static function read(Contents $manifest): array
    {
        $lists = [];
        $rest = [];

        foreach (Node::decode($manifest->text())->entries() as $key => $value) {
            $key = sprintf('%s', $key);
            $known = ManifestKey::tryFrom($key);

            if (! $known instanceof ManifestKey) {
                $rest[$key] = $value->json();

                continue;
            }

            if ($known->listsPackages()) {
                $lists[$key] = self::constraints($value);
            }
        }

        ksort($rest, SORT_STRING);

        return [$lists, $rest];
    }

    /** @return array<string, string> each package's constraint in one list, by the package's name */
    private static function constraints(Node $packages): array
    {
        $constraints = [];

        foreach ($packages->entries() as $name => $constraint) {
            $constraints[sprintf('%s', $name)] = $constraint->json();
        }

        return $constraints;
    }

    /**
     * One list of a manifest's, none where it has no such list.
     *
     * @param  array<string, array<string, string>> $lists
     * @return array<string, string>
     */
    private static function listOf(array $lists, string $list): array
    {
        return array_key_exists($list, $lists) ? $lists[$list] : [];
    }
}
