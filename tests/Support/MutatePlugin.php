<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function file_get_contents;
use function sprintf;

/**
 * The files of pest-plugin-mutate that `pest:patch` changes, copied into
 * a vendor directory: as the version composer.json allows ships them, or as
 * this repository's vendor directory holds them.
 */
final readonly class MutatePlugin
{
    /** The files the patch changes, relative to the plugin's source. */
    public const array FILES = [
        'MutationTest.php',
        'Plugins/Mutate.php',
        'Tester/MutationTestRunner.php',
        'Support/StreamWrapper.php',
    ];

    private function __construct(private string $source, private string $suffix)
    {
    }

    /**
     * As the allowed version ships them, from tests/Fixtures. Each file there
     * ends in .phps, so no tool reads it as this repository's code.
     */
    public static function pristine(): self
    {
        return new self(Tree::at('tests/Fixtures/PestPluginMutate/src'), 's');
    }

    /** As this repository's vendor directory holds them, patched where its Composer hook ran. */
    public static function installed(): self
    {
        return new self(Tree::at('vendor/pestphp/pest-plugin-mutate/src'), '');
    }

    /** A new vendor directory holding the files. */
    public function vendor(): string
    {
        return $this->into(Scratch::directory());
    }

    /** The vendor directory, once it holds the files. */
    public function into(string $vendor): string
    {
        foreach (self::FILES as $file) {
            $contents = (string) file_get_contents(sprintf('%s/%s%s', $this->source, $file, $this->suffix));
            Scratch::write($vendor, sprintf('pestphp/pest-plugin-mutate/src/%s', $file), $contents);
        }

        return $vendor;
    }
}
