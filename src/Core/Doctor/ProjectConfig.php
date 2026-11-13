<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Tree\Trees;

/**
 * A project's config as doctor read it (ADR-0017): the effective settings,
 * or why there are none; the trees its tree source found, or why it found
 * none; and the config file's own layer, its context blocks as it writes
 * them (ADR-0071). A part nobody read is not given.
 *
 * @internal
 */
final readonly class ProjectConfig
{
    private function __construct(
        private Settings|Invalid|CannotJudge|NotGiven $settings,
        private Trees|Invalid|CannotJudge|NotGiven $trees,
        private Layer|NotGiven $written,
    ) {
    }

    public static function none(): self
    {
        return new self(NotGiven::value(), NotGiven::value(), NotGiven::value());
    }

    public function withSettings(Settings|Invalid|CannotJudge $settings): self
    {
        return new self($settings, $this->trees, $this->written);
    }

    public function withTrees(Trees|Invalid|CannotJudge $trees): self
    {
        return new self($this->settings, $trees, $this->written);
    }

    public function withWritten(Layer $written): self
    {
        return new self($this->settings, $this->trees, $written);
    }

    public function settings(): Settings|Invalid|CannotJudge|NotGiven
    {
        return $this->settings;
    }

    public function trees(): Trees|Invalid|CannotJudge|NotGiven
    {
        return $this->trees;
    }

    /** The config file's own layer, its context blocks as it writes them; none where it was not read. */
    public function written(): Layer|NotGiven
    {
        return $this->written;
    }
}
