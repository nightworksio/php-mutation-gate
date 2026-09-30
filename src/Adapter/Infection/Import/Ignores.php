<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection\Import;

use function array_map;

use DateTimeImmutable;

use function implode;
use function mb_strlen;
use function mb_strstr;
use function mb_substr;

use NightWorksIO\MutationGate\Adapter\Infection\AnyMutator;
use NightWorksIO\MutationGate\Adapter\Infection\IgnorePattern;
use NightWorksIO\MutationGate\Adapter\Infection\MutatorSettings;
use NightWorksIO\MutationGate\Core\Config\IgnoredPattern;
use NightWorksIO\MutationGate\Core\Config\Ignores as Part;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\NativeMarkers;
use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Import\Carried;
use NightWorksIO\MutationGate\Core\Import\Import;
use NightWorksIO\MutationGate\Core\Time\Day;

use function sprintf;
use function str_contains;
use function str_ends_with;
use function strpbrk;

/**
 * Infection's `ignore` patterns as `ignores.entries` (ADR-0016, decision 4).
 * A pattern over a class, or a method or line of it, becomes an entry for the
 * class's whole file, and one over a whole namespace, `Acme\Legacy\*`, an
 * entry for its directory. Under a mutator it names that mutator; under a
 * profile, or `global-ignore`, each family the profile holds whole and each
 * other mutator of it by name. Each entry has a reason a person must rewrite,
 * and an end 90 days on. A pattern over source, and one that maps to no
 * entry, stays in Infection's config, and `ignores.native: allow` keeps it
 * working.
 */
final readonly class Ignores
{
    private const string REASON = 'Imported from %s (%s): write the real reason';

    /** How long an imported entry lasts, so none outlives a person's look at it. */
    private const int DAYS = 90;

    private const string KEY = 'mutators.%s';

    private const string ENTRY = 'an ignore of %s in %s until %s, from %s';

    private const string WIDENED = '%s, widened to the whole file';

    private const string KEPT = '%s: %s, so ignores.native: allow keeps it';

    /** How a pattern covers every class of a namespace. */
    private const string NAMESPACE = '\\*';

    /** What makes a pattern name more than one class. */
    private const string WILDCARDS = '*?[';

    public static function of(
        MutatorSettings $mutators,
        string $file,
        ClassFiles $classes,
        Profiles|Unmapped $profiles,
        DateTimeImmutable $now,
    ): Import {
        $expires = Day::on($now->modify(sprintf('+%d days', self::DAYS)));
        $import = Import::none();

        foreach ($mutators->patterns() as $pattern) {
            $key = sprintf(self::KEY, $pattern->key());
            $covered = self::covered($pattern, $classes, $profiles);
            $import = $import->and($covered instanceof Unmapped
                ? self::native($key, sprintf(self::KEPT, $pattern->pattern(), $covered->said()))
                : self::entries($key, $pattern, $covered, sprintf(self::REASON, $file, $pattern->pattern()), $expires));
        }

        return $import;
    }

    /**
     * What a pattern covers, and the names its entries take, or why it maps to no entry.
     *
     * @return array{Glob, list<string>}|Unmapped
     */
    private static function covered(
        IgnorePattern $pattern,
        ClassFiles $classes,
        Profiles|Unmapped $profiles,
    ): array|Unmapped {
        $where = $pattern->isOverSource() ? Unmapped::OverSource : self::where($pattern->pattern(), $classes);

        if ($where instanceof Unmapped) {
            return $where;
        }

        $names = self::mutators($pattern, $profiles);

        return $names instanceof Unmapped ? $names : [$where, $names];
    }

    /** The glob of what a pattern covers: its class's file, or everything under its namespace's directory. */
    private static function where(string $pattern, ClassFiles $classes): Glob|Unmapped
    {
        $class = mb_strstr($pattern, '::', before_needle: true);
        $class = $class === false ? $pattern : $class;
        $namespace = str_ends_with($class, self::NAMESPACE)
            ? mb_substr($class, 0, mb_strlen($class) - mb_strlen(self::NAMESPACE))
            : $class;

        if (strpbrk($namespace, self::WILDCARDS) !== false) {
            return Unmapped::Wildcard;
        }

        $file = $namespace === $class ? $classes->of($class) : $classes->under($namespace);

        return $file instanceof Path ? Glob::of($file->value()) : $file;
    }

    /**
     * The mutator or family names a pattern's entries take: the mutator it is
     * under, or every one its profile or `global-ignore` covers.
     *
     * @return list<string>|Unmapped
     */
    private static function mutators(IgnorePattern $pattern, Profiles|Unmapped $profiles): array|Unmapped
    {
        $mutator = $pattern->mutator();
        $profile = mb_strstr($pattern->key(), '.', before_needle: true);

        return match (true) {
            ! $mutator instanceof AnyMutator => [$mutator],
            $profiles instanceof Unmapped => $profiles,
            $profile === false => $profiles->all(),
            default => $profiles->of($profile),
        };
    }

    /**
     * The entries a pattern becomes: one per name, for what it covers.
     *
     * @param array{Glob, list<string>} $covered
     */
    private static function entries(
        string $key,
        IgnorePattern $pattern,
        array $covered,
        string $reason,
        Day $expires,
    ): Import {
        [$where, $names] = $covered;
        $entries = array_map(
            static fn(string $name): IgnoredPattern => IgnoredPattern::of($where, $name, $reason, $expires),
            $names,
        );
        $from = str_contains($pattern->pattern(), '::')
            ? sprintf(self::WIDENED, $pattern->pattern())
            : $pattern->pattern();
        $said = sprintf(self::ENTRY, implode(', ', $names), $where->value(), $expires->value(), $from);

        return Import::of(Layer::of(Part::of(entries: Listed::of(...$entries))), Carried::imported($key, $said));
    }

    private static function native(string $key, string $because): Import
    {
        return Import::of(Layer::of(Part::of(native: NativeMarkers::Allow)), Carried::stays($key, $because));
    }
}
