<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Alert;

use function count;
use function getenv;
use function is_int;
use function is_string;

use NightWorksIO\MutationGate\Core\Alert\Alerts;
use NightWorksIO\MutationGate\Core\Alert\AlertShape;
use NightWorksIO\MutationGate\Core\Alert\PostLimits;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\CiRun;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\AlertOption;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Key;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Delivery\AlertPost;
use NightWorksIO\MutationGate\Core\Delivery\Deferring;
use NightWorksIO\MutationGate\Core\Delivery\Delivery as Deferred;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Report\NoTrend;
use NightWorksIO\MutationGate\Core\Runner\GateSecret;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Port\Reporter;
use Psr\Clock\ClockInterface;

use function sprintf;

/**
 * The reporters `slack`, `discord` and `webhook`: when the default branch
 * changes state in CI, each alert goes to the URL in the environment
 * variable that `urlEnv` names; the config's validator refuses a URL written
 * in the config. The webhook's request is signed where a secret is set. A
 * post that fails says so and fails nothing (ADR-0016).
 *
 * @internal
 */
final readonly class AlertReporter implements Deferring, Reporter
{
    private const string NO_URL = '%s is not set, so no alert goes to %s.';

    private const string NOT_CI = 'Alerts are sent from CI only.';

    private const string OFF_DEFAULT = 'This run is not on the default branch, so it alerts nothing.';

    private const string CUT_SHORT = 'The run\'s budget cut it short, so it alerts nothing.';

    private const string SAMPLED = 'The run was sampled, so it alerts nothing.';

    private const string STEADY = 'The default branch did not change state, so there is nothing to alert.';

    private function __construct(
        private Channel $channel,
        private Delivery $delivery,
        private Variables $environment,
        private string $urlEnv,
        private string $secretEnv,
        private ClockInterface $clock,
        private AlertShape $shape,
        private PostLimits $limits,
    ) {
    }

    /** The reporter for this channel, reading this environment, posting through this delivery, signing by the clock. */
    public static function inEnvironment(
        Channel $channel,
        Options $options,
        Variables $environment,
        Delivery $delivery,
        ClockInterface $clock,
    ): self|Invalid {
        $urlEnv = self::named($options, AlertOption::UrlEnv, $channel->urlEnv());
        $secretEnv = self::named($options, AlertOption::SecretEnv, GateSecret::WebhookSecret->value);

        return match (true) {
            $urlEnv instanceof Problem => Invalid::because($urlEnv),
            $secretEnv instanceof Problem => Invalid::because($secretEnv),
            default => self::to($channel, $environment, $delivery, $clock, $urlEnv, $secretEnv)
                ->shaped(self::shapeOf($options), self::limitsOf($options)),
        };
    }

    /** The reporter for this channel, reading its URL and secret from these variables of this environment. */
    public static function to(
        Channel $channel,
        Variables $environment,
        Delivery $delivery,
        ClockInterface $clock,
        string $urlEnv,
        string $secretEnv,
    ): self {
        return new self(
            $channel,
            $delivery,
            $environment,
            $urlEnv,
            $secretEnv,
            $clock,
            AlertShape::standard(),
            PostLimits::standard(),
        );
    }

    /** This reporter, saying as much as this shape lets it, and posting within these limits. */
    public function shaped(AlertShape $shape, PostLimits $limits): self
    {
        return new self(
            $this->channel,
            $this->delivery,
            $this->environment,
            $this->urlEnv,
            $this->secretEnv,
            $this->clock,
            $shape,
            $limits,
        );
    }

    /** The reporter for this channel in this process's environment, posting over the network. */
    public static function configured(Channel $channel, Options $options, ClockInterface $clock): self|Invalid
    {
        return self::inEnvironment($channel, $options, Variables::of(getenv()), Delivery::online($clock), $clock);
    }

    public function report(Verdict $verdict): Written|NotWritten
    {
        $url = $this->environment->valueOf($this->urlEnv);
        $run = $url === ''
            ? NotWritten::because(sprintf(self::NO_URL, $this->urlEnv, $this->channel->said()))
            : $this->alerting($verdict);

        return $run instanceof CiRun ? $this->send(Alerts::of($verdict), $url, $run) : $run;
    }

    /**
     * A delivery, with each alert this reporter would send of the verdict now, for `deliver` to post to the URL its
     * own environment holds (ADR-0007); unchanged where it would send none.
     */
    public function deferred(Verdict $verdict, Deferred $delivery): Deferred
    {
        $run = $this->alerting($verdict);

        if (! $run instanceof CiRun) {
            return $delivery;
        }

        $deferred = $delivery;

        foreach (Alerts::of($verdict) as $alert) {
            $body = $this->channel->message($alert, $run, $this->shape);
            $deferred = $deferred->withAlert(AlertPost::of($this->channel->reporter(), $body)->within($this->limits));
        }

        return $deferred;
    }

    /**
     * One body, ready to send, posted to the URL in this reporter's variable, signed where it signs: what
     * `deliver` sends of an alert a run left for it (ADR-0007).
     */
    public function posted(string $body): Written|NotWritten
    {
        $url = $this->environment->valueOf($this->urlEnv);

        return $url === ''
            ? NotWritten::because(sprintf(self::NO_URL, $this->urlEnv, $this->channel->said()))
            : $this->delivery->within($this->limits)->post($url, $body, $this->headers($body), $this->channel->said());
    }

    /**
     * The CI run the verdict alerts of: in CI, on the default branch, not cut short nor sampled, and where the
     * branch changed state; or why it alerts nothing.
     */
    private function alerting(Verdict $verdict): CiRun|NotWritten
    {
        $run = CiRun::read($this->environment);

        return match (true) {
            ! $this->environment->has('CI') => NotWritten::because(self::NOT_CI),
            $run instanceof CannotTell => NotWritten::because($run->why()),
            $verdict->account()->previous() instanceof NoTrend => NotWritten::because(self::OFF_DEFAULT),
            $verdict->wasCutShort() => NotWritten::because(self::CUT_SHORT),
            $verdict->trees()->sample()->isDrawn() => NotWritten::because(self::SAMPLED),
            count(Alerts::of($verdict)) === 0 => NotWritten::because(self::STEADY),
            default => $run,
        };
    }

    /** Each alert, in turn; the first that is not written says why. */
    private function send(Alerts $alerts, string $url, CiRun $run): Written|NotWritten
    {
        $sent = Written::to($this->channel->said());

        $delivery = $this->delivery->within($this->limits);

        foreach ($alerts as $alert) {
            $body = $this->channel->message($alert, $run, $this->shape);
            $answer = $delivery->post($url, $body, $this->headers($body), $this->channel->said());
            $sent = $sent instanceof NotWritten ? $sent : $answer;
        }

        return $sent;
    }

    /** @return array<string, string> */
    private function headers(string $body): array
    {
        $secret = $this->channel === Channel::Webhook ? $this->environment->valueOf($this->secretEnv) : '';

        return [
            'Content-Type' => 'application/json',
            ...$secret === '' ? [] : [Signature::HEADER => Signature::of($body, $secret, $this->clock->now())],
        ];
    }

    /** The variable an option names, this one where it names none, or why what it holds names none. */
    private static function named(Options $options, AlertOption $option, string $otherwise): string|Problem
    {
        $variable = $options->text(Key::of($option->value));

        return $variable instanceof NotGiven ? $otherwise : $variable;
    }

    private static function shapeOf(Options $options): AlertShape
    {
        $standard = AlertShape::standard();
        $survivors = $options->integer(Key::of(AlertShape::SURVIVORS));
        $length = $options->integer(Key::of(AlertShape::LINE_LENGTH));

        return AlertShape::of(
            is_int($survivors) ? $survivors : $standard->survivors(),
            is_int($length) ? $length : $standard->lineLength(),
        );
    }

    private static function limitsOf(Options $options): PostLimits
    {
        $standard = PostLimits::standard();
        $timeout = $options->text(Key::of(PostLimits::TIMEOUT));
        $wait = $options->text(Key::of(PostLimits::LONGEST_WAIT));
        $timed = is_string($timeout) ? Seconds::parse($timeout) : $standard->timeout();
        $waited = is_string($wait) ? Seconds::parse($wait) : $standard->longestWait();

        return PostLimits::of(
            $timed instanceof Seconds ? $timed : $standard->timeout(),
            $waited instanceof Seconds ? $waited : $standard->longestWait(),
        );
    }
}
