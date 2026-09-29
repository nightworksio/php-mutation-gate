<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

/** Throwaway projects on disk, their manifests written as JSON, removed by `Scratch::sweep()`. */
final readonly class Project
{
    /**
     * The project the tree source contract suite reads: the root's autoload
     * names src/Domain, src/Http and src/Generated, src/Domain's manifest
     * declares a floor of 100, and src/Generated's a floor of 0 with a reason.
     */
    public static function ofTheFixture(): string
    {
        return self::with([
            'composer.json' => '{"name": "acme/app", "autoload": {"psr-4": {"App\\\\Domain\\\\": "src/Domain/", "App\\\\Http\\\\": "src/Http/", "App\\\\Generated\\\\": "src/Generated/"}}}',
            'src/Domain/composer.json' => '{"extra": {"mutation-gate": {"floor": 100}}}',
            'src/Generated/composer.json' => '{"extra": {"mutation-gate": {"floor": 0, "floorReason": "Generated on every build"}}}',
        ]);
    }

    /**
     * A project holding these files, by their paths.
     *
     * @param array<string, string> $files
     */
    public static function with(array $files): string
    {
        $root = Scratch::directory();

        foreach ($files as $path => $contents) {
            Scratch::write($root, $path, $contents);
        }

        return $root;
    }
}
