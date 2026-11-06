<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function implode;

use NightWorksIO\MutationGate\Cli\Config\Formats;
use NightWorksIO\MutationGate\Cli\Config\NoConfigFile;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\ConfigFile;
use NightWorksIO\MutationGate\Core\Config\Format;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Migration\UnifiedDiff;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Suggest\Suggestions;

use function sprintf;

/**
 * What `config:suggest` prints (ADR-0076): each suggestion its rules make
 * of a ledger, with its evidence, and where the config cannot say it, why;
 * then the config file with every change it can say laid over it, as a
 * unified diff in the file's own format, as the gate writes that format.
 * Where the project has no config file, the diff adds `mutation-gate.json`.
 * It writes nothing.
 *
 * @internal
 */
final readonly class SuggestedConfig
{
    public const string NONE = 'Nothing to suggest: no mutator is idle or noisy, and no directory is slow.';

    private const string ITEM = '- %s';

    private const string UNSAID = '  The config cannot say this: %s';

    private const string UNSHOWN = 'The change cannot be shown: %s';

    private const string OTHER_FORMAT = '%s is in a format the gate does not write.';

    public function __construct(private Composed $composed, private Formats $formats, private string $project)
    {
    }

    /** The suggestions, then the diff of the config file, which `written` is what it sets and `located` where. */
    public function said(Ledger $ledger, Layer $written, Path|NoConfigFile $located): string
    {
        $suggestions = Suggestions::of(
            $ledger->learned()->tallies(),
            $ledger->proofs()->newest(),
            $this->composed->settings,
        );

        if ($suggestions === []) {
            return sprintf("%s\n", self::NONE);
        }

        $lines = [];
        $change = Layer::of();

        foreach ($suggestions as $suggestion) {
            $refused = Unsayable::why($this->composed, $suggestion->change());
            $lines[] = sprintf(self::ITEM, $suggestion->why());
            $lines = $refused instanceof NotGiven ? $lines : [...$lines, sprintf(self::UNSAID, $refused)];
            $change = $refused instanceof NotGiven ? $change->over($suggestion->change()) : $change;
        }

        $diff = $this->diff($written, $change, $located);

        return $diff === ''
            ? sprintf("%s\n", implode("\n", $lines))
            : sprintf("%s\n\n%s", implode("\n", $lines), $diff);
    }

    private function diff(Layer $written, Layer $change, Path|NoConfigFile $located): string
    {
        $project = Path::of($this->project);
        $path = $located instanceof Path ? $located : $project->child(Path::of(Format::Json->fileName()));
        $file = ConfigFile::at($path, $project);
        $format = Format::fromExtension($file->extension());
        $shown = $path->relativeTo($project)->value();

        if (! $format instanceof Format) {
            return sprintf("%s\n", sprintf(self::UNSHOWN, sprintf(self::OTHER_FORMAT, $shown)));
        }

        $before = $located instanceof Path ? $this->formats->file($written, $format, $file) : '';
        $after = $this->formats->file($written->over($change), $format, $file);

        return match (true) {
            $before instanceof CannotJudge => sprintf("%s\n", sprintf(self::UNSHOWN, $before->why())),
            $after instanceof CannotJudge => sprintf("%s\n", sprintf(self::UNSHOWN, $after->why())),
            default => UnifiedDiff::between($shown, $before, $after),
        };
    }
}
