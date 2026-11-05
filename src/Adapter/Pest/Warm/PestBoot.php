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
 * What a booted warm worker boots before it forks, read as a fresh run of
 * its suites reads it up to its first test: the project's PHPUnit config,
 * which the worker's script loads once Pest's kernel has booted, the suites
 * that run includes, the bootstraps PHPUnit runs before it builds them, and
 * why the worker may boot nothing. A worker of the verification tail
 * (ADR-0075) boots nothing without a config, or with one that caches
 * PHPUnit's test index, which may leave a fresh run's test files unloaded; a
 * worker of mutants' runs (ADR-0091), which loads no test file, forks
 * unbooted without a config.
 *
 * @internal
 */
final readonly class PestBoot
{
    /** What PHPUnit's config says whether it caches the test index by, from PHPUnit 13.4. */
    private const string INDEX = 'cacheTestIndex';

    private const string NO_CONFIG = '%s found no PHPUnit config in %s, so it booted nothing of the project.';

    private const string UNREAD = '%s could not read the tests %s names, so its runs ran fresh: %s';

    private const string LEFT_OPEN
        = '%s left %d socket open once it booted, which every child would share, so its runs ran fresh.';

    private const string TAIL = 'The tail\'s warm worker';

    private const string MUTANTS = 'A warm worker of mutants\' runs';

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

    /**
     * Why the worker boots nothing where the project has no PHPUnit config;
     * nothing for a worker of mutants' runs, which then forks each run
     * unbooted, as ADR-0023's workers do.
     */
    public function unconfigured(): string
    {
        return $this->spec->isForMutants() ? '' : sprintf(self::NO_CONFIG, self::TAIL, $this->spec->root());
    }

    /**
     * Why the worker forks nothing from a boot of this config: a tail's
     * worker loads every test file, which a config that caches PHPUnit's
     * test index may leave unloaded, and PHPUnit before 13.4 caches none;
     * nothing where it may fork, as a worker of mutants' runs, which loads no
     * test file, always may.
     */
    public function refusal(LoadedFromFileConfiguration $xml): string
    {
        $phpunit = $xml->phpunit();
        $indexed = ! $this->spec->isForMutants()
            && in_array(self::INDEX, get_class_methods($phpunit), strict: true)
            && $phpunit->cacheTestIndex();

        return $indexed ? sprintf(self::INDEXED, $xml->filename()) : '';
    }

    /** Why every run of the worker runs fresh where it could not read the tests this config names. */
    public function unread(string $config, string $reason): string
    {
        return sprintf(self::UNREAD, $this->worker(), $config, $reason);
    }

    /** Why every run of the worker runs fresh where its boot left sockets open; nothing where it left none. */
    public function leftOpen(int $sockets): string
    {
        return $sockets > 0 ? sprintf(self::LEFT_OPEN, $this->worker(), $sockets) : '';
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

    /** The worker, as its messages name it. */
    private function worker(): string
    {
        return $this->spec->isForMutants() ? self::MUTANTS : self::TAIL;
    }
}
