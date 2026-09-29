<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_diff;
use function array_flip;
use function array_key_exists;
use function array_keys;
use function file_get_contents;
use function implode;
use function is_array;
use function is_file;
use function is_string;
use function json_decode;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\Runner\Versions;

use function sprintf;

/** The exact versions of the packages the Pest adapter drives, as Composer installed them. */
final readonly class Installed
{
    /** The packages whose versions decide how Pest mutates and judges. */
    private const array DRIVEN = [
        'pestphp/pest',
        'pestphp/pest-plugin-mutate',
        'phpunit/phpunit',
        'phpunit/php-code-coverage',
    ];

    /** Where each package's source reference is, in the order Composer prefers them. */
    private const array ORIGINS = ['source', 'dist'];

    public static function versionsIn(string $manifest): Versions|CannotJudge
    {
        if (! is_file($manifest)) {
            return self::unlisted($manifest, self::DRIVEN);
        }

        $decoded = json_decode(sprintf('%s', file_get_contents($manifest)), associative: true);
        $found = self::found(is_array($decoded) ? self::listIn($decoded, 'packages') : []);
        $missing = array_diff(self::DRIVEN, array_keys($found));

        if ($missing !== []) {
            return self::unlisted($manifest, $missing);
        }

        $versions = Versions::none();

        foreach (self::DRIVEN as $package) {
            $versions = $versions->with($found[$package]);
        }

        return $versions;
    }

    /** @param array<string> $missing */
    private static function unlisted(string $manifest, array $missing): CannotJudge
    {
        return CannotJudge::because(sprintf(
            '%s does not list %s, so the gate cannot say which Pest judges the mutants. Run composer install.',
            $manifest,
            implode(', ', $missing),
        ));
    }

    /**
     * @param array<mixed> $packages
     *
     * @return array<string, Version> the driven packages among these, by name
     */
    private static function found(array $packages): array
    {
        $found = [];

        foreach ($packages as $package) {
            $fields = is_array($package) ? $package : [];
            $name = self::text($fields, 'name');

            if (array_key_exists($name, array_flip(self::DRIVEN))) {
                $found[$name] = Version::of($name, self::text($fields, 'version'), self::reference($fields));
            }
        }

        return $found;
    }

    /** @param array<mixed> $package */
    private static function reference(array $package): string
    {
        foreach (self::ORIGINS as $origin) {
            $reference = self::text(self::listIn($package, $origin), 'reference');

            if ($reference !== '') {
                return $reference;
            }
        }

        return '';
    }

    /**
     * @param array<mixed> $fields
     *
     * @return array<mixed>
     */
    private static function listIn(array $fields, string $key): array
    {
        return array_key_exists($key, $fields) && is_array($fields[$key]) ? $fields[$key] : [];
    }

    /** @param array<mixed> $fields */
    private static function text(array $fields, string $key): string
    {
        return array_key_exists($key, $fields) && is_string($fields[$key]) ? $fields[$key] : '';
    }
}
