<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Outbreak;

use function is_string;

use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Outbreak as OutbreakSettings;
use NightWorksIO\MutationGate\Core\Coverage\Conversion;
use NightWorksIO\MutationGate\Core\Coverage\MapOutline;
use NightWorksIO\MutationGate\Core\Coverage\MapSlices;
use NightWorksIO\MutationGate\Core\Coverage\MapVouch;
use NightWorksIO\MutationGate\Core\Coverage\PendingOutline;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Proof\CacheRequest;
use NightWorksIO\MutationGate\Core\Proof\ProofCache;
use NightWorksIO\MutationGate\Core\Reach\GraphAnswer;
use NightWorksIO\MutationGate\Core\Reach\GraphAnswered;
use NightWorksIO\MutationGate\Core\Reach\GraphAsked;
use NightWorksIO\MutationGate\Core\Reach\GraphQuestion;
use NightWorksIO\MutationGate\Core\Reach\ReachGraph;
use NightWorksIO\MutationGate\Core\Runner\ConvertsPhpReports;
use NightWorksIO\MutationGate\Core\Runner\RanReport;
use NightWorksIO\MutationGate\Core\Serve\KeepsProofs;
use NightWorksIO\MutationGate\Core\Watch\NoticesChanges;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Port\MapEngine;

use function sprintf;

/**
 * Outbreak's core, the `outbreak` binary, as the gate's map engine (ADR-0118),
 * which converts a run's PHP report too (ADR-0119), the store of `outbreak
 * cache` (ADR-0131) and the static graph (ADR-0132), which `outbreak serve`
 * answers first where it runs (ADR-0133): each other answer is one run of
 * the binary, its input handed on its standard input and its answer read as
 * one line of JSON in contract 1. Where there is no binary, or a run fails,
 * ends with another code, runs past its time or answers otherwise, the engine
 * vouches for nothing, splits nothing and converts nothing, and the gate does
 * the work itself.
 *
 * @internal
 */
final readonly class Outbreak implements ConvertsPhpReports, MapEngine, ProofCache, ReachGraph
{
    private const string NO_BINARY = 'No Outbreak binary is in use.';

    private const string UNREAD = 'Outbreak did not read the report: %s';

    private const string UNGRAPHED = 'Outbreak\'s graph answered nothing: %s';

    /** The option the binary takes how many threads it writes on with. */
    private const string THREADS = '--threads';


    private function __construct(
        private string|NotGiven $binary,
        private string $root,
        private OutbreakSettings $settings,
        private (NoticesChanges&ReachGraph&KeepsProofs)|NotGiven $served = new NotGiven(),
    ) {
    }

    /**
     * The engine a config asks for, in a project at this root: none where
     * `outbreak.enabled` is off; the binary `outbreak.binary` names, from the
     * root where it is relative; or else the one the package ships in a
     * directory for this machine, where its checksum is the one listed. Each
     * run takes `outbreak.timeout` at most, and splits on `outbreak.threads`.
     */
    public static function configured(OutbreakSettings $settings, string $root, string $shipped): self
    {
        $named = $settings->binary();
        $binary = match (true) {
            ! $settings->enabled() => NotGiven::value(),
            $named instanceof NotGiven => Bundled::in($shipped),
            default => Path::of($named)->isAbsolute() ? $named : sprintf('%s/%s', $root, $named),
        };

        return $binary instanceof NotGiven ? self::none($root) : self::at($binary, $root, $settings);
    }

    /**
     * The engine the settings beneath a runner's options ask for (see
     * `Core\Config\Outbreak::beneath()`), as `configured()` finds it; the
     * default settings' where the options hold none.
     */
    public static function beneath(Options $options, string $root, string $shipped): self
    {
        return self::configured(OutbreakOptions::binary($options), $root, $shipped);
    }

    /**
     * The binary at a path, in a project at this root, where it speaks this
     * contract; no binary where it does not or cannot say. Each run of it is
     * stopped after the settings' timeout, and splits on their threads.
     */
    public static function at(string $binary, string $root, OutbreakSettings|NotGiven $settings = new NotGiven()): self
    {
        $settled = $settings instanceof OutbreakSettings ? $settings : OutbreakSettings::of();
        $said = BinaryRun::ran($binary, Command::Contract->words(), '', $settled->timeout());

        return new self($said instanceof Node ? $binary : NotGiven::value(), $root, $settled);
    }

    /**
     * The engine with the state `outbreak serve` keeps for the project, where
     * the binary speaks and `outbreak.serve.enabled` is on; as it is otherwise.
     */
    public function servedBy(NoticesChanges&ReachGraph&KeepsProofs $served): self
    {
        return $this->binary instanceof NotGiven || ! $this->settings->serve()->enabled()
            ? $this
            : new self($this->binary, $this->root, $this->settings, $served);
    }

    /** No binary, in a project at this root: every answer declines. */
    public static function none(string $root): self
    {
        return new self(NotGiven::value(), $root, OutbreakSettings::of());
    }

    /** Whether a binary is in use. */
    public function isInUse(): bool
    {
        return ! $this->binary instanceof NotGiven;
    }

    public function vouches(Contents $text): MapVouch
    {
        $said = $this->ran(Command::Vouch->words(), $text->text());

        try {
            $vouched = $said instanceof Node && $said->field('vouched')->boolean();
        } catch (NotInShape) {
            $vouched = false;
        }

        return $vouched ? MapVouch::Vouched : MapVouch::Unvouched;
    }

    public function outlining(RanReport $report): PendingOutline
    {
        $asked = $this->asking(Command::Outline, ReportAsked::outline($report));

        if ($asked instanceof NotWritten) {
            return PendingOutline::read($asked);
        }

        return PendingOutline::taking(function () use ($asked, $report): MapOutline|NotWritten {
            $said = $this->said($asked);

            return $said instanceof Node ? ReportAnswer::outline($said, $report) : $said;
        });
    }

    public function convert(RanReport $report, Conversion $conversion, Path $into): Written|NotWritten
    {
        $map = $this->absolute($into);
        $asked = $this->asking(Command::Convert, ReportAsked::conversion($report, $conversion, $map));
        $said = $asked instanceof NotWritten ? $asked : $this->said($asked);

        return $said instanceof Node ? ReportAnswer::written($said, $map) : $said;
    }

    public function split(Path $whole, MapSlices $slices): Written|NotWritten
    {
        if ($this->binary instanceof NotGiven) {
            return NotWritten::because(self::NO_BINARY);
        }

        $threads = $this->settings->threads();
        $on = $threads instanceof NotGiven ? [] : [self::THREADS, sprintf('%d', $threads)];

        return SplitAnswer::of(
            $this->ran([...Command::Split->words(), ...$on], SplitAsked::of($whole, $slices, $this->absolute(...))),
            $whole,
        );
    }

    public function pulled(CacheRequest $request): Written|NotWritten
    {
        if ($this->binary instanceof NotGiven) {
            return NotWritten::because(self::NO_BINARY);
        }

        $said = $this->ran(Command::Pull->words(), CacheAsked::of($request, $this->absolute(...)));

        return CacheAnswer::of($said, 'pull', 'pulled', $this->root);
    }

    public function pushed(CacheRequest $request): Written|NotWritten
    {
        if ($this->binary instanceof NotGiven) {
            return NotWritten::because(self::NO_BINARY);
        }

        $said = $this->ran(Command::Push->words(), CacheAsked::of($request, $this->absolute(...)));
        $registry = $request->registry();

        return CacheAnswer::of($said, 'push', 'pushed', is_string($registry) ? $registry : $this->root);
    }

    /** The command lines that start the binary in use as a process of its own. */
    public function lines(): BinaryLines
    {
        return new BinaryLines($this->binary, $this->root, $this->settings->serve());
    }

    /**
     * The state `outbreak serve` keeps for the project: the changes it watches
     * the files for and the proofs it keeps by key, where it serves the
     * project; none otherwise.
     */
    public function served(): (NoticesChanges&ReachGraph&KeepsProofs)|NotGiven
    {
        return $this->served;
    }

    /**
     * What the static graph answers: the daemon's graph, which reads a file
     * for its names again only where it changed, where `outbreak serve`
     * answers; one run of the binary otherwise.
     */
    public function answered(GraphQuestion $question): GraphAnswer|NotWritten
    {
        if ($this->binary instanceof NotGiven) {
            return NotWritten::because(self::NO_BINARY);
        }

        $served = $this->served instanceof NotGiven ? $this->served : $this->served->answered($question);

        if ($served instanceof GraphAnswer) {
            return $served;
        }

        $said = $this->ran(Command::Graph->words(), GraphAsked::of($question, $this->root));

        return $said instanceof Node
            ? GraphAnswered::of($said)
            : NotWritten::because(sprintf(self::UNGRAPHED, $said->why()));
    }

    /**
     * What one run of the binary answered to these arguments and this input;
     * why it did not, or that there is no binary.
     *
     * @param non-empty-list<string> $arguments
     */
    private function ran(array $arguments, string $input): Node|NotWritten
    {
        return $this->binary instanceof NotGiven
            ? NotWritten::because(self::NO_BINARY)
            : BinaryRun::ran($this->binary, $arguments, $input, $this->settings->timeout());
    }

    /**
     * A command of a report, started with this input; why it could not be
     * started, or that there is no binary.
     */
    private function asking(Command $command, string $input): BinaryRun|NotWritten
    {
        if ($this->binary instanceof NotGiven) {
            return NotWritten::because(self::NO_BINARY);
        }

        $started = BinaryRun::started($this->binary, $command->words(), $input, $this->settings->timeout());

        return $started instanceof BinaryRun ? $started : NotWritten::because(sprintf(self::UNREAD, $started->why()));
    }

    /** What a run of the binary answered, once it ended; why it did not answer, otherwise. */
    private function said(BinaryRun $run): Node|NotWritten
    {
        $said = $run->answer();

        return $said instanceof Node ? $said : NotWritten::because(sprintf(self::UNREAD, $said->why()));
    }

    private function absolute(Path $path): string
    {
        return sprintf('%s/%s', $this->root, $path->value());
    }
}
