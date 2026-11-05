<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli;

use function array_any;
use function count;
use function is_file;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Composer\Autoloader;
use NightWorksIO\MutationGate\Core\Composer\Installed;
use NightWorksIO\MutationGate\Core\Composer\Manifest;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\OwnArchive;
use NightWorksIO\MutationGate\Core\Runner\IniPath;
use NightWorksIO\MutationGate\Core\ThisPackage;

use function sprintf;

/**
 * The gate as its PHAR starts it in a project (ADR-0022): the project's
 * vendor directory, and its autoloader, which the PHAR loads after its own
 * so extensions and mutator sets are found as usual. A project whose
 * autoloader declares the archive's own classes, a copy of the gate it
 * installs with Composer or is, is refused, since two copies of one class
 * cannot coexist; an archive whose classes are scoped under a namespace of
 * their own, as the judge's are, runs beside either (ADR-0140). An archive
 * whose path PHP would not read as it is, in the setting that has each PHP
 * process the gate starts load it, is refused too.
 *
 * @internal
 */
final readonly class FromArchive
{
    private const string INSTALLED
        = 'This project installs the gate with Composer, and two copies cannot coexist: run vendor/bin/%s.';

    private const string IS_THE_GATE
        = 'This project is the gate, whose classes the archive\'s would clash with: run bin/%s, or a judge\'s archive.';

    private const string UNPREPENDABLE
        = 'PHP reads a ", a ${ or a \\\\ in a setting as ini syntax, so it cannot load the PHAR at %s. Move it.';

    public function __construct(private OwnArchive $archive, private string $project)
    {
    }

    /** Why the gate's PHAR does not run in the project; nothing where it does. */
    public function refusing(): CannotJudge|NotGiven
    {
        $clashing = $this->clashing();

        return match (true) {
            $clashing instanceof CannotJudge => $clashing,
            ! IniPath::isPlain($this->archive->file())
                => CannotJudge::because(sprintf(self::UNPREPENDABLE, $this->archive->file())),
            default => NotGiven::value(),
        };
    }

    /** The project's vendor directory, where Composer installed its packages. */
    public function vendor(): string
    {
        return ComposerVendor::on($this->project);
    }

    /** The project's autoloader, where Composer wrote one. */
    public function autoloader(): string|NotGiven
    {
        $autoloader = Root::of($this->vendor())->at(Autoloader::fileIn(Path::root()))->value();

        return is_file($autoloader) ? $autoloader : NotGiven::value();
    }

    /** Why the project's autoloader would declare the archive's own classes: as the gate, or a copy it installs. */
    private function clashing(): CannotJudge|NotGiven
    {
        $root = Directory::at($this->project)->read(Manifest::fileIn(Path::root()));
        $manifest = $root instanceof Contents ? Manifest::decode($root, Path::root()) : $root;
        $file = Installed::fileIn(Path::root());
        $contents = Directory::at($this->vendor())->read($file);
        $installed = $contents instanceof Contents ? Installed::decode($contents, $file) : $contents;

        return match (true) {
            $manifest instanceof Manifest && $this->declaresOwn($manifest)
                => CannotJudge::because(sprintf(self::IS_THE_GATE, ThisPackage::NAME)),
            $installed instanceof Installed && array_any([...$installed], $this->declaresOwn(...))
                => CannotJudge::because(sprintf(self::INSTALLED, ThisPackage::NAME)),
            default => NotGiven::value(),
        };
    }

    /** Whether a manifest's autoload declares a class of the archive's own. */
    private function declaresOwn(Manifest $manifest): bool
    {
        return count($manifest->classLocations()->files(ThisPackage::class)) > 0;
    }
}
