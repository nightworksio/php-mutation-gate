<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\JudgedCommit;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Port\ChangeSource;
use NightWorksIO\MutationGate\Tests\Fakes\ChangeSourceFake;

/**
 * The flows' checkout, whose working tree a test changes while `watch` looks
 * at it: each look at its files counts, and from a given look on, or once
 * the test changes it, its files are another checkout's, or cannot be told.
 */
final class ChangingCheckout implements ChangeSource
{
    private int $looks = 0;

    private bool $changed = false;

    private function __construct(
        private readonly ChangeSourceFake $before,
        private readonly ChangeSourceFake|CannotTell $after,
        private readonly int $changesAt,
    ) {
    }

    /** The flows' checkout, which becomes this one once the test changes it. */
    public static function becoming(ChangeSourceFake|CannotTell $after): self
    {
        return new self(Flows::checkout(), $after, PHP_INT_MAX);
    }

    /**
     * A checkout of these files at every revision, which becomes this one
     * once the test changes it.
     *
     * @param array<string, string> $files
     */
    public static function holding(array $files, ChangeSourceFake|CannotTell $after): self
    {
        return new self(self::of($files, $files), $after, PHP_INT_MAX);
    }

    /** The flows' checkout, which becomes this one from this look at its files on, counting from one. */
    public static function becomingAt(int $look, ChangeSourceFake|CannotTell $after): self
    {
        return new self(Flows::checkout(), $after, $look);
    }

    /**
     * The flows' checkout, or one of these files, with this file holding
     * this instead in its working tree.
     *
     * @param array<string, string> $files
     */
    public static function with(string $path, string $contents, array $files = Flows::FILES): ChangeSourceFake
    {
        return self::of([...$files, $path => $contents], $files);
    }

    public function change(): void
    {
        $this->changed = true;
    }

    public function changesSince(Revision $base): Changes|CannotTell
    {
        return $this->now()->changesSince($base);
    }

    public function changesFrom(Revision $commit): Changes|CannotTell
    {
        return $this->now()->changesFrom($commit);
    }

    public function judged(Revision $commit): JudgedCommit|CannotTell
    {
        return $this->now()->judged($commit);
    }

    public function readable(JudgedCommit $judged): Revision|CannotTell
    {
        return $this->now()->readable($judged);
    }

    public function fingerprints(): Fingerprints|CannotTell
    {
        ++$this->looks;
        $now = $this->changed || $this->looks >= $this->changesAt ? $this->after : $this->before;

        return $now instanceof CannotTell ? $now : $now->fingerprints();
    }

    public function unstaged(): Paths
    {
        return $this->now()->unstaged();
    }

    public function lastChanged(Paths $paths): ByPath
    {
        return $this->now()->lastChanged($paths);
    }

    public function fileAt(Path $path, Revision $revision): Contents|Missing|CannotTell
    {
        return $this->now()->fileAt($path, $revision);
    }

    public function filesAt(Paths $paths, Revision $revision): ByPath|CannotTell
    {
        return $this->now()->filesAt($paths, $revision);
    }

    /**
     * A checkout whose working tree holds these files, and every commit those.
     *
     * @param array<string, string> $working
     * @param array<string, string> $committed
     */
    private static function of(array $working, array $committed): ChangeSourceFake
    {
        return new ChangeSourceFake(
            Revision::ref('base'),
            Changes::none(),
            [Revision::workingTree()->name() => $working, 'base' => $committed, Flows::MAIN => $committed],
        );
    }

    private function now(): ChangeSourceFake
    {
        return $this->changed && $this->after instanceof ChangeSourceFake ? $this->after : $this->before;
    }
}
