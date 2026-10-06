<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function file_get_contents;
use function json_encode;
use function sprintf;

/**
 * The two files of Infection that `infection:patch` changes, copied into a
 * vendor directory with Composer's list of what it installed: as the release
 * the patch supports ships them, or as the Infection runner contracts'
 * library holds them.
 */
final readonly class InfectionSource
{
    /** The files the patch changes, relative to Infection's source. */
    public const array FILES = [
        'Process/Factory/MutantProcessContainerFactory.php',
        'Process/Runner/MutationTestingRunner.php',
    ];

    private function __construct(private string $source, private string $suffix)
    {
    }

    /**
     * As the supported release ships them, from tests/Fixtures. Each file there
     * ends in .phps, so no tool reads it as this repository's code.
     */
    public static function pristine(): self
    {
        return new self(Tree::at('tests/Fixtures/InfectionSource/src'), 's');
    }

    /** As the Infection runner contracts' library holds them. */
    public static function installed(): self
    {
        return new self(Tree::at('tests/Contract/Runner/infection-fixture/vendor/infection/infection/src'), '');
    }

    /** A new vendor directory holding the files, with Composer listing Infection at this release. */
    public function vendor(string $release = '0.35.6'): string
    {
        return $this->into(Scratch::directory(), $release);
    }

    /** The vendor directory, once it holds the files and Composer lists Infection at this release. */
    public function into(string $vendor, string $release = '0.35.6'): string
    {
        foreach (self::FILES as $file) {
            $contents = (string) file_get_contents(sprintf('%s/%s%s', $this->source, $file, $this->suffix));
            Scratch::write($vendor, sprintf('infection/infection/src/%s', $file), $contents);
        }

        Scratch::write($vendor, 'composer/installed.json', (string) json_encode(['packages' => [
            ['name' => 'infection/infection', 'version' => $release, 'source' => ['reference' => 'abc']],
        ]]));

        return $vendor;
    }
}
