<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Delivery;

use function array_diff;
use function array_filter;
use function array_keys;
use function array_map;
use function array_values;
use function count;

use NightWorksIO\MutationGate\Core\Alert\AlertEvent;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\BuiltinReporter;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\Scope;

use function sprintf;

/**
 * A delivery as `delivery.json` holds it, format 1 (ADR-0007 decision 5). It
 * holds payloads, and the scope of the ledger beside it, alone: a key it does
 * not know, such as a store, a bucket, a URL, an endpoint or the name of a
 * variable, is refused, never followed, so the run that wrote it cannot
 * choose where `deliver`'s credentials, or its ledger, go.
 */
final readonly class DeliveryFile
{
    /** Where `deliver` reads a delivery from, in its directory. */
    public const string NAME = 'delivery.json';

    private const int FORMAT = 1;

    private const string UNREAD = 'The delivery cannot be read, so deliver sends nothing: %s';

    private const string NOT_TAKEN = 'a key deliver takes from a delivery';

    private const string TOO_MANY = 'within the alerts a verdict sends to one channel';

    private const string LEDGER_KEY = 'ledger';




    private const string SCOPE = 'scope';

    private const string ALERTS = 'alerts';

    private const string OTLP = 'otlp';

    private const string TRACES = 'traces';

    private const string METRICS = 'metrics';

    public static function encode(Delivery $delivery): string
    {
        $ledger = $delivery->ledger();
        $comment = $delivery->comment();
        $otlp = $delivery->otlp();
        $alerts = array_map(
            static fn(AlertPost $alert): Json => Json::object(
                Member::of('channel', $alert->channel()->value),
                Member::of('body', $alert->body()),
            ),
            $delivery->alerts(),
        );

        return Json::object(
            Member::of('format', self::FORMAT),
            Member::of(self::LEDGER_KEY, $ledger instanceof LedgerPost ? self::ledgerJson($ledger) : Absent::setting()),
            Member::of('comment', $comment instanceof NotGiven ? Absent::setting() : $comment),
            Member::unlessEmpty(self::ALERTS, Json::items(...$alerts)),
            Member::of(self::OTLP, $otlp instanceof OtlpPost ? self::otlpJson($otlp) : Absent::setting()),
        )->pretty();
    }

    public static function decode(string $json): Delivery|CannotJudge
    {
        try {
            return self::deliveryIn(Node::decode($json, 'delivery'));
        } catch (NotInShape $refused) {
            return CannotJudge::because(sprintf(self::UNREAD, $refused->getMessage()));
        }
    }

    private static function ledgerJson(LedgerPost $ledger): Json
    {
        return Json::object(Member::of(self::SCOPE, $ledger->scope()->ref()));
    }

    private static function otlpJson(OtlpPost $otlp): Json
    {
        $traces = $otlp->traces();

        return Json::object(
            Member::of(self::TRACES, $traces instanceof NotGiven ? Absent::setting() : $traces),
            Member::of(self::METRICS, $otlp->metrics()),
        );
    }

    /** @throws NotInShape */
    private static function deliveryIn(Node $top): Delivery
    {
        self::onlyKeys($top, 'format', self::LEDGER_KEY, 'comment', self::ALERTS, self::OTLP);

        if ($top->field('format')->integer() !== self::FORMAT) {
            throw NotInShape::at($top->field('format')->at(), sprintf('format %d', self::FORMAT));
        }

        $delivery = Delivery::none();
        $delivery = $top->field(self::LEDGER_KEY)->isPresent()
            ? $delivery->withLedger(self::ledgerIn($top->field(self::LEDGER_KEY)))
            : $delivery;
        $delivery = $top->field('comment')->isPresent()
            ? $delivery->withComment($top->field('comment')->text())
            : $delivery;

        foreach ($top->field(self::ALERTS)->isPresent() ? $top->field(self::ALERTS)->items() : [] as $alert) {
            $delivery = $delivery->withAlert(self::alertIn($alert, $delivery));
        }

        $otlp = $top->field(self::OTLP);

        return $otlp->isPresent() ? $delivery->withOtlp(self::otlpIn($otlp)) : $delivery;
    }

    /** @throws NotInShape */
    private static function ledgerIn(Node $ledger): LedgerPost
    {
        self::onlyKeys($ledger, self::SCOPE);

        return LedgerPost::to(self::scopeIn($ledger->field(self::SCOPE)));
    }


    /** @throws NotInShape */
    private static function scopeIn(Node $ref): Scope
    {
        $scope = Scope::parse($ref->text());

        return $scope instanceof Scope ? $scope : throw NotInShape::at($ref->at(), 'a scope');
    }

    /**
     * An alert to a channel that sends alerts, beside no more to that channel than a verdict sends, one for each
     * kind of alert, so a delivery cannot have `deliver` post without end.
     *
     * @throws NotInShape
     */
    private static function alertIn(Node $alert, Delivery $before): AlertPost
    {
        self::onlyKeys($alert, 'channel', 'body');
        $channel = BuiltinReporter::tryFrom($alert->field('channel')->text());

        if (! $channel instanceof BuiltinReporter || ! $channel->alerts()) {
            throw NotInShape::at($alert->field('channel')->at(), 'an alert channel');
        }

        return self::sentTo($channel, $before) < count(AlertEvent::cases())
            ? AlertPost::of($channel, $alert->field('body')->text())
            : throw NotInShape::at($alert->at(), self::TOO_MANY);
    }

    /** How many alerts a delivery already sends to a channel. */
    private static function sentTo(BuiltinReporter $channel, Delivery $delivery): int
    {
        return count(array_filter(
            $delivery->alerts(),
            static fn(AlertPost $alert): bool => $alert->channel() === $channel,
        ));
    }

    /** @throws NotInShape */
    private static function otlpIn(Node $otlp): OtlpPost
    {
        self::onlyKeys($otlp, self::TRACES, self::METRICS);

        return OtlpPost::of(
            $otlp->field(self::TRACES)->isPresent() ? $otlp->field(self::TRACES)->text() : NotGiven::value(),
            $otlp->field(self::METRICS)->text(),
        );
    }

    /**
     * That a map holds no key but these.
     *
     * @throws NotInShape
     */
    private static function onlyKeys(Node $map, string ...$taken): void
    {
        $unknown = array_values(array_diff(array_keys($map->entries()), $taken));

        if ($unknown !== []) {
            throw NotInShape::at($map->field(sprintf('%s', $unknown[0]))->at(), self::NOT_TAKEN);
        }
    }
}
