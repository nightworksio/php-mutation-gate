<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_any;
use function array_map;
use function count;
use function is_string;

use NightWorksIO\MutationGate\Core\Composer\Installed;
use NightWorksIO\MutationGate\Core\Composer\Manifest;
use NightWorksIO\MutationGate\Core\Composer\Names;
use NightWorksIO\MutationGate\Core\Composer\PackageFiles;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Lenient;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Registry\Origin;

/**
 * The plugins of a copy of the gate that the project's Pest lists, in the
 * `pest-plugins.json` Composer wrote beside its packages: each a class the
 * autoload of a first-party package declares, the gate's or a mutator set's,
 * the project's own manifest among them where the project is one. What
 * cannot be read lists none.
 *
 * @internal
 */
final readonly class GatePlugins
{
    /** The list Pest reads its plugins from, in the vendor directory. */
    private const string LISTED = 'pest-plugins.json';

    private function __construct(private Names $names)
    {
    }

    /** The plugins of a copy of the gate the project's Pest lists. */
    public static function listedIn(Project $project, PackageFiles $files): self
    {
        $manifests = [...self::root($project, $files), ...self::installed($project, $files)];
        $plugins = [];

        foreach (self::listed($project, $files) as $plugin) {
            $declared = array_any(
                $manifests,
                static fn(Manifest $manifest): bool => count($manifest->classLocations()->files($plugin)) > 0,
            );
            $plugins = $declared ? [...$plugins, $plugin] : $plugins;
        }

        return new self(Names::of(...$plugins));
    }

    public function names(): Names
    {
        return $this->names;
    }

    /** @return list<string> the plugins Pest lists, by class name */
    private static function listed(Project $project, PackageFiles $files): array
    {
        $list = self::read($project, $files, $project->vendor()->child(Path::of(self::LISTED)));

        try {
            $items = $list instanceof Contents ? Node::decode($list->text())->items() : [];
        } catch (NotInShape) {
            $items = [];
        }

        return array_map(Lenient::text(...), $items);
    }

    /** @return list<Manifest> the project's own manifest, where it is a first-party package's */
    private static function root(Project $project, PackageFiles $files): array
    {
        $root = self::read($project, $files, Manifest::fileIn(Path::root()));
        $manifest = $root instanceof Contents ? Manifest::decode($root, Path::root()) : $root;

        return $manifest instanceof Manifest && self::isFirstParty($manifest) ? [$manifest] : [];
    }

    /** @return list<Manifest> the first-party packages Composer installed */
    private static function installed(Project $project, PackageFiles $files): array
    {
        $file = Installed::fileIn(Path::root());
        $contents = self::read($project, $files, Installed::fileIn($project->vendor()));
        $installed = $contents instanceof Contents ? Installed::decode($contents, $file) : $contents;
        $firstParty = [];

        foreach ($installed instanceof Installed ? $installed : [] as $package) {
            $firstParty = self::isFirstParty($package) ? [...$firstParty, $package] : $firstParty;
        }

        return $firstParty;
    }

    private static function isFirstParty(Manifest $manifest): bool
    {
        $name = $manifest->name();

        return is_string($name) && Origin::of($name)->isFirstParty();
    }

    /** What a file of the project holds; none where there is none. */
    private static function read(Project $project, PackageFiles $files, Path $path): Contents|NotGiven
    {
        $text = $files->read($project->absolute($path));

        return is_string($text) ? Contents::of($text) : $text;
    }
}
