<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection\Import;

use function array_filter;
use function array_map;

use DateTimeImmutable;

use function mb_strstr;

use NightWorksIO\MutationGate\Adapter\Infection\AnyMutator;
use NightWorksIO\MutationGate\Adapter\Infection\IgnorePattern;
use NightWorksIO\MutationGate\Adapter\Infection\MutatorSettings;
use NightWorksIO\MutationGate\Core\Config\IgnoredPattern;
use NightWorksIO\MutationGate\Core\Config\Ignores as Part;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\NativeMarkers;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Import\Carried;
use NightWorksIO\MutationGate\Core\Import\Import;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Time\Day;

use function sprintf;
use function str_contains;
use function str_starts_with;
use function strpbrk;

/**
 * Infection's `ignore` patterns as `ignores.entries` (ADR-0016, decision 4):
 * a pattern over a class, or a method or line of it, becomes an entry for
 * the class's whole file, one per family under `global-ignore`. Each has a
 * reason a person must rewrite, and an end 90 days on. A pattern over
 * source, and one that maps to no entry, stays in Infection's config, and
 * `ignores.native: allow` keeps it working.
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

    private const string OVER_SOURCE = 'the gate has no equivalent of a pattern over source';

    /** The mutator a `global-ignore` entry names in place of each of Infection's: every family. */
    private const string EVERY_FAMILY = 'every family';

    public static function of(
        MutatorSettings $mutators,
        string $file,
        ClassFiles $classes,
        DateTimeImmutable $now,
    ): Import {
        $expires = Day::on($now->modify(sprintf('+%d days', self::DAYS)));
        $import = Import::none();

        foreach ($mutators->patterns() as $pattern) {
            $import = $import->and(self::converted($pattern, $file, $classes, $expires));
        }

        return $import;
    }

    private static function converted(IgnorePattern $pattern, string $file, ClassFiles $classes, Day $expires): Import
    {
        $key = sprintf(self::KEY, $pattern->key());

        if ($pattern->isOverSource()) {
            return self::native($key, sprintf(self::KEPT, $pattern->pattern(), self::OVER_SOURCE));
        }

        $class = self::classOf($pattern->pattern());
        $path = match (true) {
            str_starts_with($pattern->key(), '@') => Unmapped::Profile,
            strpbrk($class, '*?[') !== false => Unmapped::Wildcard,
            default => $classes->of($class),
        };

        return $path instanceof Path
            ? self::entries($key, $pattern, $path, sprintf(self::REASON, $file, $pattern->pattern()), $expires)
            : self::native($key, sprintf(self::KEPT, $pattern->pattern(), $path->said()));
    }

    /** The entries a pattern over a class becomes: one for its mutator, or one per family for every mutator. */
    private static function entries(
        string $key,
        IgnorePattern $pattern,
        Path $path,
        string $reason,
        Day $expires,
    ): Import {
        $mutator = $pattern->mutator();
        $mutators = $mutator instanceof AnyMutator ? self::families() : [$mutator];
        $entries = array_map(
            static fn(string $each): IgnoredPattern => IgnoredPattern::of($path->value(), $each, $reason, $expires),
            $mutators,
        );
        $from = str_contains($pattern->pattern(), '::')
            ? sprintf(self::WIDENED, $pattern->pattern())
            : $pattern->pattern();

        $said = sprintf(
            self::ENTRY,
            $mutator instanceof AnyMutator ? self::EVERY_FAMILY : $mutator,
            $path->value(),
            $expires->value(),
            $from,
        );

        return Import::of(Layer::of(Part::of(entries: Listed::of(...$entries))), Carried::imported($key, $said));
    }

    private static function native(string $key, string $because): Import
    {
        return Import::of(Layer::of(Part::of(native: NativeMarkers::Allow)), Carried::stays($key, $because));
    }

    /** The class a pattern names: all of it before a `::` that names a method, or a method and a line. */
    private static function classOf(string $pattern): string
    {
        $class = mb_strstr($pattern, '::', before_needle: true);

        return $class === false ? $pattern : $class;
    }

    /** @return list<string> every family a gate ignore can name, which together are every mutator that has one */
    private static function families(): array
    {
        $named = array_filter(
            MutatorFamily::cases(),
            static fn(MutatorFamily $family): bool => $family !== MutatorFamily::None,
        );

        return array_map(static fn(MutatorFamily $family): string => $family->value, $named);
    }
}
