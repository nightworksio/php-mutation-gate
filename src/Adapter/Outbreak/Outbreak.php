<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Outbreak;

use function array_map;
use function fwrite;
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
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Fit;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Runner\ConvertsPhpReports;
use NightWorksIO\MutationGate\Core\Runner\RanReport;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Port\MapEngine;

use function rewind;
use function sprintf;

use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

use function tmpfile;
use function trim;

/**
 * Outbreak's core, the `outbreak` binary, as the gate's map engine (ADR-0118),
 * which converts a run's PHP report too (ADR-0119): each answer is one run of
 * the binary, its input handed on its standard input and its answer read as
 * one line of JSON in contract 1. Where there is no binary, or a run fails,
 * ends with another code, runs past its time or answers otherwise, the engine
 * vouches for nothing, splits nothing and converts nothing, and the gate does
 * the work itself.
 *
 * @internal
 */
final readonly class Outbreak implements ConvertsPhpReports, MapEngine
{
    /** The contract every answer is written in, as `outbreak contract` says it. */
    private const int CONTRACT = 1;

    private const string NO_BINARY = 'No Outbreak binary is in use.';

    private const string FAILED = 'Outbreak did not split the map: %s';

    private const string DECLINED = 'Outbreak declined to split the map: %s';

    private const string ANSWERED = 'it answered %s';

    private const string WROTE = 'Outbreak wrote %d coverage maps from %s.';

    private const string UNREAD = 'Outbreak did not read the report: %s';

    /** What a message calls the binary's answer. */
    private const string ANSWER = 'Outbreak\'s answer';

    private const string NO_INPUT = 'No file could be made to hand Outbreak its input.';

    /** The option the binary takes how many threads it writes on with. */
    private const string THREADS = '--threads';

    private function __construct(
        private string|NotGiven $binary,
        private string $root,
        private OutbreakSettings $settings,
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
        $said = self::ran($binary, Command::Contract->words(), '', $settled->timeout());

        return new self($said instanceof Node ? $binary : NotGiven::value(), $root, $settled);
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
            : self::ran($this->binary, Command::Vouch->words(), $text->text(), $this->settings->timeout());

        return $said instanceof Node && $this->flag($said, 'vouched') ? MapVouch::Vouched : MapVouch::Unvouched;
    }

    public function outline(RanReport $report): MapOutline|NotWritten
    {
        $said = $this->asking(Command::Outline, ReportAsked::outline($report));

        return $said instanceof Node ? ReportAnswer::outline($said, $report) : $said;
    }

    public function convert(RanReport $report, Conversion $conversion, Path $into): Written|NotWritten
    {
        $map = $this->absolute($into);
        $said = $this->asking(Command::Convert, ReportAsked::conversion($report, $conversion, $map));

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
        $said = self::ran($this->binary, [...Command::Split->words(), ...$on], $request, $this->settings->timeout());

        return $said instanceof Node
            ? $this->splitBy($said, $whole)
            : NotWritten::because(sprintf(self::FAILED, $said->why()));
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

    /** What the binary answered a command of a report, handed this input; why it did not, or that there is none. */
    private function asking(Command $command, string $input): Node|NotWritten
    {
        if ($this->binary instanceof NotGiven) {
            return NotWritten::because(self::NO_BINARY);
        }

        $said = self::ran($this->binary, $command->words(), $input, $this->settings->timeout());

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

    /**
     * What one run of the binary answered, where it ended with nought in its
     * time and answered in this contract; why not, otherwise. Its input is
     * handed as a file, which it reads as fast as it can, rather than written
     * to it a chunk at a time.
     *
     * @param non-empty-list<string> $arguments
     */
    private static function ran(string $binary, array $arguments, string $input, Seconds $timeout): Node|NotWritten
    {
        $process = new Process([$binary, ...$arguments], timeout: $timeout->seconds());
        $given = tmpfile();

        if ($given !== false) {
            fwrite($given, $input);
            rewind($given);
            $process->setInput($given);
        }

        return $given === false ? NotWritten::because(self::NO_INPUT) : self::answered($process);
    }

    /** What a run of the binary answered, its input handed to it. */
    private static function answered(Process $process): Node|NotWritten
    {
        try {
            $process->run();
        } catch (ExceptionInterface $failure) {
            return NotWritten::because($failure->getMessage());
        }

        $answer = Node::decode($process->getOutput(), self::ANSWER);

        return $process->isSuccessful() && self::isContract($answer)
            ? $answer
            : NotWritten::because(trim(sprintf(Fit::JOINED, $process->getOutput(), $process->getErrorOutput())));
    }

    private static function isContract(Node $answer): bool
    {
        try {
            return $answer->field('outbreak')->integer() === self::CONTRACT;
        } catch (NotInShape) {
            return false;
        }
    }
}
