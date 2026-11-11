<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Outbreak;

use function array_map;
use function is_float;
use function is_int;
use function is_string;
use function json_encode;

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Key;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Outbreak as OutbreakSettings;
use NightWorksIO\MutationGate\Core\Coverage\Conversion;
use NightWorksIO\MutationGate\Core\Coverage\MapOutline;
use NightWorksIO\MutationGate\Core\Coverage\MapSlice;
use NightWorksIO\MutationGate\Core\Coverage\MapSlices;
use NightWorksIO\MutationGate\Core\Coverage\MapVouch;
use NightWorksIO\MutationGate\Core\Coverage\PendingOutline;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\JsonText;
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
use NightWorksIO\MutationGate\Core\Time\Seconds;
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

    private const string FAILED = 'Outbreak did not split the map: %s';

    private const string DECLINED = 'Outbreak declined to split the map: %s';

    private const string ANSWERED = 'it answered %s';

    private const string WROTE = 'Outbreak wrote %d coverage maps from %s.';

    private const string UNREAD = 'Outbreak did not read the report: %s';

    private const string UNCACHED = 'Outbreak did not %s the proof cache\'s packs: %s';

    private const string CACHED = 'Outbreak %s %d of the proof cache\'s packs.';

    private const string UNGRAPHED = 'Outbreak\'s graph answered nothing: %s';

    /** The option the binary takes how many threads it writes on with. */
    private const string THREADS = '--threads';

    /** The option that starts `outbreak serve` in a process of its own. */
    private const string DETACH = '--detach';

    /** The option `outbreak serve` takes the seconds between snapshots with. */
    private const string SNAPSHOT_EVERY = '--snapshot-every';

    /** The option `outbreak serve` takes the seconds between syncs of its journal with. */
    private const string SYNC_EVERY = '--sync-every';

    /** The option `outbreak serve` takes the seconds the changes it watches settle for with. */
    private const string DEBOUNCE = '--debounce';

    /** What separates `outbreak serve`'s options from the child's command line, and `outbreak lsp`'s from `watch`'s. */
    private const string CHILD = '--';

    /** The option `outbreak lsp` takes the gate's script with. */
    private const string GATE = '--gate';

    /** The option `outbreak lsp` takes the PHP that runs the gate with. */
    private const string PHP = '--php';

    /** The option `outbreak lsp` takes how the editor shows a survivor with. */
    private const string SEVERITY = '--severity';

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
        $beneath = $options->object(Key::of(OutbreakSettings::OPTION));
        $read = $beneath instanceof Options ? $beneath : Options::none();
        $enabled = $read->flag(Key::of(OutbreakSettings::ENABLED));
        $binary = $read->text(Key::of(OutbreakSettings::BINARY));
        $timeout = $read->number(Key::of(OutbreakSettings::TIMEOUT));
        $threads = $read->integer(Key::of(OutbreakSettings::THREADS));

        $settings = OutbreakSettings::of(
            $enabled === false ? $enabled : Absent::setting(),
            is_string($binary) ? $binary : Absent::setting(),
            is_float($timeout) ? Seconds::of($timeout) : Absent::setting(),
            is_int($threads) ? $threads : Absent::setting(),
        );

        return self::configured($settings, $root, $shipped);
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
        $said = $this->binary instanceof NotGiven
            ? NotWritten::because(self::NO_BINARY)
            : BinaryRun::ran($this->binary, Command::Vouch->words(), $text->text(), $this->settings->timeout());

        return $said instanceof Node && $this->flag($said, 'vouched') ? MapVouch::Vouched : MapVouch::Unvouched;
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

        $request = json_encode([
            'whole' => $this->absolute($whole),
            'slices' => array_map($this->asked(...), [...$slices]),
        ], JsonText::FLAGS);
        $threads = $this->settings->threads();
        $on = $threads instanceof NotGiven ? [] : [self::THREADS, sprintf('%d', $threads)];
        $timeout = $this->settings->timeout();
        $said = BinaryRun::ran($this->binary, [...Command::Split->words(), ...$on], $request, $timeout);

        return $said instanceof Node
            ? $this->splitBy($said, $whole)
            : NotWritten::because(sprintf(self::FAILED, $said->why()));
    }

    public function pulled(CacheRequest $request): Written|NotWritten
    {
        return $this->cached(Command::Pull, 'pull', 'pulled', $request);
    }

    public function pushed(CacheRequest $request): Written|NotWritten
    {
        return $this->cached(Command::Push, 'push', 'pushed', $request);
    }

    /**
     * What goes before the PHP daemon's command line of `serve` (ADR-0078)
     * so `outbreak serve` starts it as its child in a process group of its
     * own (ADR-0133), launched as the PHP daemon would be: where a binary is
     * in use and `outbreak.serve.enabled` is on; the launch alone otherwise.
     *
     * @param list<string> $launch what the daemon is launched under, such as its priority
     *
     * @return list<string>
     */
    public function serving(array $launch): array
    {
        $serve = $this->settings->serve();

        return $this->binary instanceof NotGiven || ! $serve->enabled() ? $launch : [
            ...$launch,
            $this->binary,
            ...Command::Serve->words(),
            $this->root,
            self::DETACH,
            self::SNAPSHOT_EVERY,
            sprintf('%F', $serve->snapshotEvery()->seconds()),
            self::SYNC_EVERY,
            sprintf('%F', $serve->syncEvery()->seconds()),
            self::DEBOUNCE,
            sprintf('%F', $serve->debounce()->seconds()),
            self::CHILD,
        ];
    }

    /**
     * The command line of `outbreak lsp` (ADR-0133 decision 10), serving an
     * editor the survivors this gate's script, run by this PHP, tells of in
     * `watch` with these options, shown at this severity; none where no
     * binary is in use.
     *
     * @param list<string> $watching
     *
     * @return list<string>|NotGiven
     */
    public function editing(string $script, string $php, string $severity, array $watching): array|NotGiven
    {
        return $this->binary instanceof NotGiven ? NotGiven::value() : [
            $this->binary,
            ...Command::Lsp->words(),
            self::GATE,
            $script,
            self::PHP,
            $php,
            self::SEVERITY,
            $severity,
            self::CHILD,
            ...$watching,
        ];
    }

    /** The changes `outbreak serve` watches the files for, where it serves the project; none otherwise. */
    public function notices(): NoticesChanges|NotGiven
    {
        return $this->served;
    }

    /** The proofs `outbreak serve` keeps by key, where it serves the project; none otherwise. */
    public function state(): KeepsProofs|NotGiven
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

        $asked = GraphAsked::of($question, $this->root);
        $said = BinaryRun::ran($this->binary, Command::Graph->words(), $asked, $this->settings->timeout());

        return $said instanceof Node
            ? GraphAnswered::of($said)
            : NotWritten::because(sprintf(self::UNGRAPHED, $said->why()));
    }

    /** What the binary said of a request to the proof cache: how many packs it moved, or why none. */
    private function cached(Command $command, string $verb, string $done, CacheRequest $request): Written|NotWritten
    {
        if ($this->binary instanceof NotGiven) {
            return NotWritten::because(self::NO_BINARY);
        }

        $asked = $this->cacheAsked($request);
        $said = BinaryRun::ran($this->binary, $command->words(), $asked, $this->settings->timeout());

        try {
            return $said instanceof Node
                ? Written::noting($done, sprintf(self::CACHED, $done, $said->field($done)->integer()))
                : NotWritten::because(sprintf(self::UNCACHED, $verb, $said->why()));
        } catch (NotInShape) {
            return NotWritten::because(sprintf(self::UNCACHED, $verb, sprintf(self::ANSWERED, $said->json())));
        }
    }

    /** A request to the proof cache as the binary reads it on its input. */
    private function cacheAsked(CacheRequest $request): string
    {
        $registry = $request->registry();

        return (string) json_encode([
            'store' => $request->store()->value,
            ...$registry instanceof NotGiven ? [] : ['registry' => $registry],
            'base' => $request->base()->value(),
            ...$request->isNewest() ? ['newest' => true] : [],
            'limits' => ['packed' => $request->limits()->packed(), 'unpacked' => $request->limits()->unpacked()],
            'packs' => (object) array_map($this->absolute(...), $request->packs()),
            'restored' => (object) array_map($this->absolute(...), $request->restored()),
        ], JsonText::FLAGS);
    }

    /** What the binary said of a split: the slices written, or why they were not. */
    private function splitBy(Node $said, Path $whole): Written|NotWritten
    {
        $written = $said->field('written');

        try {
            return $written->isPresent()
                ? Written::noting($whole->value(), sprintf(self::WROTE, $written->integer(), $whole->value()))
                : NotWritten::because(sprintf(self::DECLINED, $said->field('declined')->text()));
        } catch (NotInShape) {
            return NotWritten::because(sprintf(self::FAILED, sprintf(self::ANSWERED, $said->json())));
        }
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

    /** @return array{into: string, files: list<string>} */
    private function asked(MapSlice $slice): array
    {
        return [
            'into' => $this->absolute($slice->into()),
            'files' => array_map(static fn(Path $file): string => $file->value(), [...$slice->files()]),
        ];
    }

    private function absolute(Path $path): string
    {
        return sprintf('%s/%s', $this->root, $path->value());
    }

    /** Whether an answer is in this contract and says yes to a question. */
    private function flag(Node $said, string $question): bool
    {
        try {
            return $said->field($question)->boolean();
        } catch (NotInShape) {
            return false;
        }
    }
}
