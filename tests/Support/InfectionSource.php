<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function file_get_contents;
use function json_encode;

use NightWorksIO\MutationGate\Core\Composer\Installed;
use NightWorksIO\MutationGate\Core\Composer\Package;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;

use function sprintf;

/**
 * The files of Infection and of its include-interceptor that
 * `infection:patch` changes, copied into a vendor directory with Composer's
 * list of what it installed: as a release the patch supports ships them, or
 * as the Infection runner contracts' library holds them, at whichever
 * release its leg installed.
 */
final readonly class InfectionSource
{
    /** The files the patch changes, relative to Infection's source. */
    public const array FILES = [
        'Process/Factory/MutantProcessContainerFactory.php',
        'Process/Runner/MutationTestingRunner.php',
        'Process/Runner/ParallelProcessRunner.php',
    ];

    /**
     * Each release the patch supports, by the release whose files in
     * tests/Fixtures it ships unchanged: its files are the same.
     */
    public const array SHIPS_AS = [
        '0.35.0' => '0.35.0',
        '0.35.1' => '0.35.0',
        '0.35.2' => '0.35.0',
        '0.35.3' => '0.35.0',
        '0.35.4' => '0.35.4',
        '0.35.5' => '0.35.5',
        '0.35.6' => '0.35.5',
    ];

    /** The file of include-interceptor the patch changes, relative to its source. */
    public const string INTERCEPTOR = 'IncludeInterceptor.php';

    /**
     * Each include-interceptor release Infection ~0.35.0 allows, by the
     * release whose file in tests/Fixtures it ships unchanged.
     */
    public const array INTERCEPTOR_SHIPS_AS = ['0.2.5' => '1.0.0', '1.0.0' => '1.0.0'];

    private const string LIBRARY = 'tests/Contract/Runner/infection-fixture/vendor';

    private function __construct(
        private string $source,
        private string $interceptor,
        private string $suffix,
        private string $release,
        private string $interceptorRelease,
    ) {
    }

    /**
     * As a release ships them, from tests/Fixtures. Each file there ends in
     * .phps, so no tool reads it as this repository's code.
     */
    public static function pristine(string $release = '0.35.6', string $interceptor = '1.0.0'): self
    {
        return new self(
            Tree::at(sprintf('tests/Fixtures/InfectionSource/%s', self::SHIPS_AS[$release] ?? $release)),
            Tree::at(sprintf('tests/Fixtures/IncludeInterceptor/%s', self::INTERCEPTOR_SHIPS_AS[$interceptor] ?? $interceptor)),
            's',
            $release,
            $interceptor,
        );
    }

    /** As the Infection runner contracts' library holds them, at the releases its leg installed. */
    public static function installed(): self
    {
        return new self(
            Tree::at(sprintf('%s/infection/infection/src', self::LIBRARY)),
            Tree::at(sprintf('%s/infection/include-interceptor/src', self::LIBRARY)),
            '',
            self::installedRelease(),
            self::installedRelease(Package::IncludeInterceptor),
        );
    }

    /** The release of Infection, or of another package, the Infection runner contracts' library installed. */
    public static function installedRelease(Package $package = Package::Infection): string
    {
        $file = Path::of(Tree::at(sprintf('%s/composer/installed.json', self::LIBRARY)));
        $installed = Installed::decode(Contents::of((string) file_get_contents($file->value())), $file);
        $versions = $installed instanceof Installed ? [...$installed->versionsOf($package->value)] : [];

        return $versions === [] ? '' : $versions[0]->release();
    }

    public function release(): string
    {
        return $this->release;
    }

    public function interceptorRelease(): string
    {
        return $this->interceptorRelease;
    }

    /** A new vendor directory holding the files, with Composer listing Infection at their release, or at another. */
    public function vendor(string $listed = ''): string
    {
        return $this->into(Scratch::directory(), $listed);
    }

    /**
     * The vendor directory, once it holds the files and Composer lists
     * Infection at their release, or at another, and include-interceptor at
     * its own.
     */
    public function into(string $vendor, string $listed = ''): string
    {
        foreach (self::FILES as $file) {
            $contents = (string) file_get_contents(sprintf('%s/%s%s', $this->source, $file, $this->suffix));
            Scratch::write($vendor, sprintf('infection/infection/src/%s', $file), $contents);
        }

        $interceptor = (string) file_get_contents(sprintf('%s/%s%s', $this->interceptor, self::INTERCEPTOR, $this->suffix));
        Scratch::write($vendor, sprintf('infection/include-interceptor/src/%s', self::INTERCEPTOR), $interceptor);
        Scratch::write($vendor, 'composer/installed.json', (string) json_encode(['packages' => [
            [
                'name' => 'infection/infection',
                'version' => $listed === '' ? $this->release : $listed,
                'source' => ['reference' => 'abc'],
            ],
            [
                'name' => 'infection/include-interceptor',
                'version' => $this->interceptorRelease,
                'source' => ['reference' => 'def'],
            ],
        ]]));

        return $vendor;
    }
}
