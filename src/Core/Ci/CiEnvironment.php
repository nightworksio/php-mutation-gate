<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Ci;

/** The environment of a job as a CI plan's marker reads it, to tell the CI the job runs in. */
final readonly class CiEnvironment
{
    private function __construct(private Variables $variables)
    {
    }

    /** The environment these variables are. */
    public static function of(Variables $variables): self
    {
        return new self($variables);
    }

    /** Whether the job runs in the CI this marker marks. */
    public function shows(CiMarker $marker): bool
    {
        return match ($marker->marking()) {
            Marking::SaysTrue => $this->variables->says($marker->variable()),
            Marking::IsSet => $this->variables->has($marker->variable()),
            Marking::Never => false,
        };
    }
}
