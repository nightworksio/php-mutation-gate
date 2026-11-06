<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cost;

use DateInterval;
use DateTimeImmutable;

use function intdiv;
use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\BuiltinReporter;
use NightWorksIO\MutationGate\Core\Config\Choice;
use NightWorksIO\MutationGate\Core\Config\Key;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

/**
 * How far back the savings a run reports count (ADR-0016): the default
 * branch's runs of the last 30 days, or as long as `costs.savingsWindow`
 * says; the summary and the badge say what was saved in it.
 */
final readonly class SavingsWindow
{
    /** The key under `costs`, and the option of the summary and the badge, that holds the window. */
    public const string KEY = 'savingsWindow';

    private const int STANDARD_DAYS = 30;

    private function __construct(private Seconds $length)
    {
    }

    /** Thirty days. */
    public static function standard(): self
    {
        return new self(Seconds::days(self::STANDARD_DAYS));
    }

    public static function of(Seconds $length): self
    {
        return new self($length);
    }

    /** The window a reporter's options hold under `savingsWindow`, the standard one where they hold none. */
    public static function in(Options $options): self
    {
        $written = $options->text(Key::of(self::KEY));
        $window = is_string($written) ? self::parse($written) : $written;

        return $window instanceof self ? $window : self::standard();
    }

    /** A window as a config or a published file writes it, such as `30d`; or why it is none. */
    public static function parse(string $written): self|CannotJudge
    {
        $length = Seconds::parse($written);

        return $length instanceof Seconds ? new self($length) : $length;
    }

    /**
     * A reporter's choice; one that shows what the gate saved with this window laid beneath its options, so the
     * summary and the badge count over `costs.savingsWindow`.
     */
    public function under(Choice $choice): Choice
    {
        $reporter = BuiltinReporter::tryFrom($choice->use()->value());

        return $reporter instanceof BuiltinReporter && $reporter->countsSavings()
            ? $choice->over(Json::object(Member::of(self::KEY, $this->length->written())))
            : $choice;
    }

    public function length(): Seconds
    {
        return $this->length;
    }

    /** What was saved in this window, or that nothing is known yet. */
    public function saved(Seconds|NoHistory $saved): SavedLately|NoHistory
    {
        return $saved instanceof Seconds ? SavedLately::of($saved, $this) : $saved;
    }

    /** When the window opened, at this moment. */
    public function since(DateTimeImmutable $now): Instant
    {
        return Instant::at($now->sub(new DateInterval(sprintf('PT%dS', (int) $this->length->seconds()))));
    }

    /** The window as a sentence says it: `30 days`, `1 day`, or as a config writes it where it is no whole day. */
    public function said(): string
    {
        $seconds = (int) $this->length->seconds();
        $days = intdiv($seconds, Seconds::PER_DAY);

        return match (true) {
            $seconds % Seconds::PER_DAY !== 0 => $this->length->written(),
            $days === 1 => '1 day',
            default => sprintf('%d days', $days),
        };
    }
}
