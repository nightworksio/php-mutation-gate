<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report\Page;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\Report\JsonReport;
use NightWorksIO\MutationGate\Core\Report\Term;
use NightWorksIO\MutationGate\Core\Troubleshooting\Guide;

use function sprintf;

/**
 * What the HTML report says of a word a reader new to mutation testing may
 * not know, where the word appears: a "what is this?" that opens, without a
 * script, on what it means and a link to the guide of the gate that rendered
 * the report, read from the JSON report's `provenance`.
 *
 * @internal
 *
 * @phpstan-import-type Report from JsonReport
 */
final readonly class Glossary
{
    private const string WHAT = <<<'HTML'
        <details class="term"><summary>What is this?</summary><p>%s <a href="%s">More in the guide</a></p></details>
        HTML;

    private const string INTRO = '<p class="intro">%s <a href="%s">How to read this report</a></p>';

    private function __construct(private Guide $guide)
    {
    }

    /** @param Report $report */
    public static function of(array $report): self
    {
        $provenance = $report['provenance'];

        return new self(
            array_key_exists('gate', $provenance)
                ? Guide::ofInstalled($provenance['gate']['version'])
                : Guide::unreleased(),
        );
    }

    /** A term's "what is this?". */
    public function explain(Term $term): string
    {
        return sprintf(self::WHAT, Html::prose($term->meaning()), Html::text($this->guide->term($term)));
    }

    /** The plain sentence on what mutation testing is, which opens the report. */
    public function intro(): string
    {
        $term = Term::MutationTesting;

        return sprintf(self::INTRO, Html::prose($term->meaning()), Html::text($this->guide->term($term)));
    }

    /** Where the guide says what a term means, for the page's script. */
    public function link(Term $term): string
    {
        return $this->guide->term($term);
    }
}
