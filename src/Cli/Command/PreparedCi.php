<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use NightWorksIO\MutationGate\Core\Ci\CiTemplate;
use NightWorksIO\MutationGate\Core\Ci\TemplateValues;
use NightWorksIO\MutationGate\Core\Config\Listed;

/**
 * A CI definition `init --ci` has checked it can make, before anything is
 * written: its templates, what fills them in, whether they are written or
 * printed, and what to tell the person beside them.
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
    ) {
    }

    /**
     * @param Listed<CiTemplate> $templates
     * @param Listed<string>     $notes
     */
    public static function of(Listed $templates, TemplateValues $values, Output $output, Listed $notes): self
    {
        return new self($templates, $values, $output, $notes);
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
}
