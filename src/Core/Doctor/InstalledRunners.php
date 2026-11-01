<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\Runner\Versions;

use function version_compare;

/**
 * Which runners Composer installed, whether the config or the command line
 * chooses one, the releases the chosen runner drives or why the gate drives
 * none of them, and the pest-plugin-mutate release installed.
 */
final readonly class InstalledRunners
{
    private function __construct(
        private bool $pest,
        private bool $infection,
        private bool $chosen,
        private Versions|CannotJudge|NotGiven $releases,
        private Version|NotGiven $pestMutate,
    ) {
    }

    public static function of(bool $pest, bool $infection, bool $chosen): self
    {
        return new self($pest, $infection, $chosen, NotGiven::value(), NotGiven::value());
    }

    /** These, with the releases the chosen runner drives, or why the gate drives none of them. */
    public function releasing(Versions|CannotJudge $releases): self
    {
        return new self($this->pest, $this->infection, $this->chosen, $releases, $this->pestMutate);
    }

    /** These, with the pest-plugin-mutate release Composer installed. */
    public function withPestMutate(Version $installed): self
    {
        return new self($this->pest, $this->infection, $this->chosen, $this->releases, $installed);
    }

    /** Whether both are installed and nothing chooses between them, which zero-config cannot do. */
    public function leaveTheChoiceOpen(): bool
    {
        return $this->pest && $this->infection && ! $this->chosen;
    }

    /** Why the gate drives none of the chosen runner's releases; nothing where it drives them, or nobody read them. */
    public function unsupported(): CannotJudge|NotGiven
    {
        return $this->releases instanceof CannotJudge ? $this->releases : NotGiven::value();
    }

    /** The pest-plugin-mutate release installed where it is a release newer than this one; nothing otherwise. */
    public function pestMutatePast(string $newest): Version|NotGiven
    {
        $installed = $this->pestMutate;

        return $installed instanceof Version && $installed->isRelease()
            && version_compare($installed->release(), $newest, '>')
            ? $installed
            : NotGiven::value();
    }
}
