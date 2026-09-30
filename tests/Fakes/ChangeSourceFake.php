<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Fakes;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Fingerprint;
use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Port\ChangeSource;

use function sprintf;

/**
 * A repository with one base and a working tree, each file held as text by
 * revision name.
 */
final readonly class ChangeSourceFake implements ChangeSource
{
    /** @param array<string, array<string, string>> $files what each file holds, by revision name and path */
    public function __construct(private Revision $base, private Changes $changes, private array $files)
    {
    }

    /** The repository of the contract suite's fixture: a base, and a working tree that changed one line and added a file. */
    public static function ofTheFixture(): self
    {
        return new self(
            Revision::ref('fixture-base'),
            Changes::of(
                Change::modified(Path::of('src/Money.php'), Lines::of(Line::of(2))),
                Change::added(Path::of('src/Limit.php'), Lines::of(Line::of(1))),
            ),
            [
                'fixture-base' => ['src/Money.php' => "<?php\nreturn 1;\n"],
                Revision::workingTree()->name() => ['src/Money.php' => "<?php\nreturn 2;\n", 'src/Limit.php' => "<?php\n"],
            ],
        );
    }

    public function changesSince(Revision $base): Changes|CannotTell
    {
        return $base->name() === $this->base->name() ? $this->changes : CannotTell::because(sprintf('%s is not a revision this repository has.', $base->name()));
    }

    public function fingerprints(): Fingerprints
    {
        $fingerprints = Fingerprints::none();

        foreach ($this->files[Revision::workingTree()->name()] as $path => $text) {
            $fingerprints = $fingerprints->with(Fingerprint::of(Path::of($path), Digest::sha256Of($text)));
        }

        return $fingerprints;
    }

    /** Every file the working tree changed, none of it staged. */
    public function unstaged(): Paths
    {
        $unstaged = Paths::none();

        foreach ($this->changes as $change) {
            $unstaged = $unstaged->with($change->path());
        }

        return $unstaged;
    }

    /** What a file held at a revision; git cannot tell for a revision the repository does not have. */
    public function fileAt(Path $path, Revision $revision): Contents|Missing|CannotTell
    {
        $files = $this->filesAt(Paths::of($path), $revision);

        return $files instanceof CannotTell ? $files : $files->at($path, Missing::at($path));
    }

    /** @return ByPath<Contents|Missing>|CannotTell */
    public function filesAt(Paths $paths, Revision $revision): ByPath|CannotTell
    {
        if (! array_key_exists($revision->name(), $this->files)) {
            return CannotTell::because(sprintf('%s is not a revision this repository has.', $revision->name()));
        }

        $files = $this->files[$revision->name()];

        return ByPath::mapping($paths, static fn(Path $path): Contents|Missing => array_key_exists($path->value(), $files)
            ? Contents::of($files[$path->value()])
            : Missing::at($path));
    }
}
