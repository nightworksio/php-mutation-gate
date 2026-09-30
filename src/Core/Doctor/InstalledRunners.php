<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor;

/** Which runners Composer installed, and whether the config or the command line chooses one. */
final readonly class InstalledRunners
{
    private function __construct(private bool $pest, private bool $infection, private bool $chosen)
    {
    }

    public static function of(bool $pest, bool $infection, bool $chosen): self
    {
        return new self($pest, $infection, $chosen);
    }

    /** Whether both are installed and nothing chooses between them, which zero-config cannot do. */
    public function leaveTheChoiceOpen(): bool
    {
        return $this->pest && $this->infection && ! $this->chosen;
    }
}
