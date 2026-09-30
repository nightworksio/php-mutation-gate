<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Alert;

use function count;
use function getenv;

use NightWorksIO\MutationGate\Core\Alert\Alerts;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\CiRun;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Report\NoTrend;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Extension\Options;
use NightWorksIO\MutationGate\Port\Reporter;
use Psr\Clock\ClockInterface;

use function sprintf;

/**
 * The reporters `slack`, `discord` and `webhook`: when the default branch
 * changes state in CI, each alert goes to the URL in the environment
 * variable that `urlEnv` names; the config's validator refuses a URL written
 * in the config. The webhook's request is signed where a secret is set. A
 * post that fails says so and fails nothing (ADR-0016, decisions 9 to 13).
 */
final readonly class AlertReporter implements Reporter
{
    /** The variable that holds the webhook's secret unless `with.secretEnv` names another. */
    public const string SECRET_ENV = 'MUTATION_GATE_WEBHOOK_SECRET';

    private const string NAMED = 'This names the environment variable to read, as text.';

    private const string NO_URL = '%s is not set, so no alert goes to %s.';

    private const string NOT_CI = 'Alerts are sent from CI only.';

    private const string OFF_DEFAULT = 'This run is not on the default branch, so it alerts nothing.';

    private const string CUT_SHORT = 'The run\'s budget cut it short, so it alerts nothing.';

    private const string STEADY = 'The default branch did not change state, so there is nothing to alert.';

    private function __construct(
        private Channel $channel,
        private Delivery $delivery,
        private Variables $environment,
        private string $urlEnv,
        private string $secretEnv,
        private ClockInterface $clock,
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
        $with = Node::decode($options->json());

        $urlEnv = self::named($with, 'urlEnv', $channel->urlEnv());
        $secretEnv = self::named($with, 'secretEnv', self::SECRET_ENV);

        return match (true) {
            $urlEnv instanceof Problem => Invalid::because($urlEnv),
            $secretEnv instanceof Problem => Invalid::because($secretEnv),
            default => self::to($channel, $environment, $delivery, $clock, $urlEnv, $secretEnv),
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
        return new self($channel, $delivery, $environment, $urlEnv, $secretEnv, $clock);
    }

    /** The reporter for this channel in this process's environment, posting over the network. */
    public static function configured(Channel $channel, Options $options, ClockInterface $clock): self|Invalid
    {
        return self::inEnvironment($channel, $options, Variables::of(getenv()), Delivery::online($clock), $clock);
    }

    public function report(Verdict $verdict): Written|NotWritten
    {
        $url = $this->environment->valueOf($this->urlEnv);
        $run = CiRun::read($this->environment);
        $alerts = Alerts::of($verdict);
        $withheld = match (true) {
            $url === '' => NotWritten::because(sprintf(self::NO_URL, $this->urlEnv, $this->channel->said())),
            ! $this->environment->has('CI') => NotWritten::because(self::NOT_CI),
            $run instanceof CannotTell => NotWritten::because($run->why()),
            $verdict->account()->previous() instanceof NoTrend => NotWritten::because(self::OFF_DEFAULT),
            $verdict->wasCutShort() => NotWritten::because(self::CUT_SHORT),
            count($alerts) === 0 => NotWritten::because(self::STEADY),
            default => $run,
        };

        return $withheld instanceof CiRun ? $this->send($alerts, $url, $withheld) : $withheld;
    }

    /** Each alert, in turn; the first that is not written says why. */
    private function send(Alerts $alerts, string $url, CiRun $run): Written|NotWritten
    {
        $sent = Written::to($this->channel->said());

        foreach ($alerts as $alert) {
            $body = $this->channel->message($alert, $run);
            $answer = $this->delivery->post($url, $body, $this->headers($body), $this->channel->said());
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
    private static function named(Node $with, string $option, string $otherwise): string|Problem
    {
        $variable = $with->field($option);

        try {
            return $variable->isPresent() ? $variable->text() : $otherwise;
        } catch (NotInShape) {
            return Problem::at($option, self::NAMED);
        }
    }
}
