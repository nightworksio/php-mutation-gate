<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli;

use function is_file;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Composer\Autoloader;
use NightWorksIO\MutationGate\Core\Composer\Installed;
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
 * so extensions and mutator sets are found as usual. A project that
 * installs the gate with Composer is refused, since two copies of the
 * gate's classes cannot coexist; so is an archive whose path PHP would not
 * read as it is in the setting that has each PHP process the gate starts
 * load it.
 *
 * @internal
 */
final readonly class FromArchive
{
    private const string INSTALLED
        = 'This project installs the gate with Composer, and two copies cannot coexist: run vendor/bin/%s.';

    private const string UNPREPENDABLE
        = 'PHP reads a ", a ${ or a \\\\ in a setting as ini syntax, so it cannot load the PHAR at %s. Move it.';

    public function __construct(private OwnArchive $archive, private string $project)
    {
    }

    /** Why the gate's PHAR does not run in the project; nothing where it does. */
    public function refusing(): CannotJudge|NotGiven
    {
        $file = Installed::fileIn(Path::root());
        $contents = Directory::at($this->vendor())->read($file);
        $installed = $contents instanceof Contents ? Installed::decode($contents, $file) : $contents;

        return match (true) {
            $installed instanceof Installed && $installed->has(ThisPackage::COMPOSER)
                => CannotJudge::because(sprintf(self::INSTALLED, ThisPackage::NAME)),
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
}
