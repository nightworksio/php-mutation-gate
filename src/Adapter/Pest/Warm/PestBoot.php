<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Warm;

use function explode;
use function get_class_methods;
use function in_array;
use function is_file;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitConfig;
use PHPUnit\TextUI\XmlConfiguration\LoadedFromFileConfiguration;

use function sprintf;

/**
 * What a warm worker of the verification tail boots before it forks
 * (ADR-0075), read as a fresh run of every test file of its suites reads it
 * up to its first test: the project's PHPUnit config, which the worker's
 * script loads once Pest's kernel has booted, the suites that run includes,
 * the bootstraps PHPUnit runs before it builds them, and why the worker may
 * boot nothing: no config, or one that caches PHPUnit's test index, which
 * may leave a fresh run's test files unloaded.
 *
 * @internal
 */
final readonly class PestBoot
{
    /** What PHPUnit's config says whether it caches the test index by, from PHPUnit 13.4. */
    private const string INDEX = 'cacheTestIndex';

    private const string NO_CONFIG
        = 'The tail\'s warm worker found no PHPUnit config in %s, so it booted nothing of the project.';

    private const string INDEXED
        = 'PHPUnit caches its test index for %s, which may leave test files unloaded, so nothing was booted.';

    private function __construct(private BootSpec $spec)
    {
    }

    public static function of(BootSpec $spec): self
    {
        return new self($spec);
    }

    /**
     * The project's PHPUnit config, by its path, as PHPUnit looks for it in
     * the project's root; none where it has none.
     *
     * @return non-empty-string|NotGiven
     */
    public function config(): string|NotGiven
    {
        foreach (PhpUnitConfig::cases() as $name) {
            $path = sprintf('%s/%s', $this->spec->root(), $name->value);

            if (is_file($path)) {
                return $path;
            }
        }

        return NotGiven::value();
    }

    /** Why the worker boots nothing where the project has no PHPUnit config. */
    public function unconfigured(): string
    {
        return sprintf(self::NO_CONFIG, $this->spec->root());
    }

    /**
     * Why the worker forks nothing from a boot of this config: it caches
     * PHPUnit's test index, which PHPUnit before 13.4 has none of; nothing
     * where it may fork.
     */
    public function refusal(LoadedFromFileConfiguration $xml): string
    {
        $phpunit = $xml->phpunit();
        $indexed = in_array(self::INDEX, get_class_methods($phpunit), strict: true) && $phpunit->cacheTestIndex();

        return $indexed ? sprintf(self::INDEXED, $xml->filename()) : '';
    }

    /**
     * The suites a run of the runs' suites includes, as PHPUnit merges them:
     * those the runs name, or the config's default, or every one, where it
     * names none.
     *
     * @return list<non-empty-string>
     */
    public function suites(LoadedFromFileConfiguration $xml): array
    {
        $phpunit = $xml->phpunit();
        $named = match (true) {
            $this->spec->suites() !== [] => $this->spec->suites(),
            $phpunit->hasDefaultTestSuite() => explode(',', $phpunit->defaultTestSuite()),
            default => [],
        };
        $suites = [];

        foreach ($named as $suite) {
            $suites = $suite === '' ? $suites : [...$suites, $suite];
        }

        return $suites;
    }

    /**
     * The config's bootstrap and each included suite's, in PHPUnit's order,
     * as it runs them before it builds the suite.
     *
     * @param  list<non-empty-string> $suites
     * @return list<string>
     */
    public function bootstraps(LoadedFromFileConfiguration $xml, array $suites): array
    {
        $phpunit = $xml->phpunit();
        $bootstraps = $phpunit->hasBootstrap() ? [$phpunit->bootstrap()] : [];

        foreach ($phpunit->bootstrapForTestSuite() as $suite => $bootstrap) {
            $included = $suites === [] || in_array($suite, $suites, strict: true);
            $bootstraps = $included ? [...$bootstraps, $bootstrap] : $bootstraps;
        }

        return $bootstraps;
    }
}
