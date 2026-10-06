<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Fakes;

use function array_key_exists;
use function in_array;

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
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Port\ChangeSource;

use function sprintf;

/**
 * A repository with one base and a working tree, each file held as text by
 * revision name, and when each committed file last changed.
 */
final readonly class ChangeSourceFake implements ChangeSource
{
    /**
     * @param array<string, array<string, string>> $files     what each file holds, by revision name and path
     * @param array<string, string>                $changedAt when each committed file last changed, by its path
     * @param list<string>                         $unchanged the revisions nothing changed since, by name
     * @param list<string>                         $alike     the revisions the same changed since as since the base, by name
     * @param array<string, Changes>               $from      what changed from each of these revisions itself, by name
     */
    public function __construct(
        private Revision $base,
        private Changes $changes,
        private array $files,
        private array $changedAt = [],
        private array $unchanged = [],
        private array $alike = [],
        private array $from = [],
    ) {
    }

    /** This repository, where this changed from a revision itself, wherever it stands in history. */
    public function changedFrom(Revision $commit, Changes $changes): self
    {
        return new self(
            $this->base,
            $this->changes,
            $this->files,
            $this->changedAt,
            $this->unchanged,
            $this->alike,
            [...$this->from, $commit->name() => $changes],
        );
    }

    /** This repository, where the same changed since this revision as since its base. */
    public function alsoFrom(Revision $revision): self
    {
        return new self($this->base, $this->changes, $this->files, $this->changedAt, $this->unchanged, [...$this->alike, $revision->name()], $this->from);
    }

    /** This repository, where nothing changed since this revision, as at the commit HEAD is at. */
    public function unchangedSince(Revision $revision): self
    {
        return new self($this->base, $this->changes, $this->files, $this->changedAt, [...$this->unchanged, $revision->name()], $this->alike, $this->from);
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
            ['src/Money.php' => '2026-09-29T10:00:00Z'],
        );
    }

    public function changesSince(Revision $base): Changes|CannotTell
    {
        return match (true) {
            $base->name() === $this->base->name(), in_array($base->name(), $this->alike, strict: true) => $this->changes,
            in_array($base->name(), $this->unchanged, strict: true) => Changes::none(),
            default => CannotTell::because(sprintf('%s is not a revision this repository has.', $base->name())),
        };
    }

    /**
     * What changed from a revision itself: what the fake was told changed from it, or else, from the base, what
     * changed since it, as the fake holds one history.
     */
    public function changesFrom(Revision $commit): Changes|CannotTell
    {
        return array_key_exists($commit->name(), $this->from) ? $this->from[$commit->name()] : $this->changesSince($commit);
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

    /** @return ByPath<Instant> */
    public function lastChanged(Paths $paths): ByPath
    {
        $changed = ByPath::none();

        foreach ($paths as $path) {
            $newest = '';

            foreach ($this->changedAt as $file => $at) {
                $newest = Path::of($file)->within($path) && $at > $newest ? $at : $newest;
            }

            $instant = Instant::parse($newest);
            $changed = $instant instanceof Instant ? $changed->with($path, $instant) : $changed;
        }

        return $changed;
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
