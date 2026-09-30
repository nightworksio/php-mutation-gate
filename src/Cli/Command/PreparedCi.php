<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use NightWorksIO\MutationGate\Core\Ci\CiTemplate;
use NightWorksIO\MutationGate\Core\Ci\TemplateValues;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Listed;

/**
 * A CI definition `init --ci` has checked it can make, before anything is
 * written: its templates, what fills them in, whether they are written or
 * printed, what to tell the person beside them, and what a config `init`
 * writes gains for it.
 */
final readonly class PreparedCi
{
    /**
     * @param Listed<CiTemplate> $templates
     * @param Listed<string>     $notes
     */
    private function __construct(
        private Listed $templates,
        private TemplateValues $values,
        private Output $output,
        private Listed $notes,
        private Layer $config,
    ) {
    }

    /**
     * @param Listed<CiTemplate> $templates
     * @param Listed<string>     $notes
     */
    public static function of(
        Listed $templates,
        TemplateValues $values,
        Output $output,
        Listed $notes,
        Layer $config,
    ): self {
        return new self($templates, $values, $output, $notes, $config);
    }

    /** @return Listed<CiTemplate> */
    public function templates(): Listed
    {
        return $this->templates;
    }

    public function values(): TemplateValues
    {
        return $this->values;
    }

    public function output(): Output
    {
        return $this->output;
    }

    /** @return Listed<string> */
    public function notes(): Listed
    {
        return $this->notes;
    }

    /** What a config `init` writes gains for this CI: the file that runs the gate, and the default branch. */
    public function config(): Layer
    {
        return $this->config;
    }
}
