<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Alert\AlertEvent;
use NightWorksIO\MutationGate\Core\Alert\PostLimits;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Comment\Mode;
use NightWorksIO\MutationGate\Core\Config\BuiltinReporter;
use NightWorksIO\MutationGate\Core\Delivery\AlertPost;
use NightWorksIO\MutationGate\Core\Delivery\CommentWrite;
use NightWorksIO\MutationGate\Core\Delivery\Delivery;
use NightWorksIO\MutationGate\Core\Delivery\DeliveryFile;
use NightWorksIO\MutationGate\Core\Delivery\KeptPost;
use NightWorksIO\MutationGate\Core\Delivery\LedgerPost;
use NightWorksIO\MutationGate\Core\Delivery\OtlpPost;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\Companion;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/** A delivery that holds one of everything. */
function deliveryWhole(): Delivery
{
    return Delivery::none()
        ->withLedger(LedgerPost::to(Scope::branch('main')))
        ->withKept(KeptPost::of(Companion::Coverage, Scope::branch('main')))
        ->withComment("## mutation-gate\n\nPassed.", CommentWrite::sticky())
        ->withAlert(AlertPost::of(BuiltinReporter::Slack, '{"text": "main fell below its floor"}'))
        ->withAlert(AlertPost::of(BuiltinReporter::Webhook, '{"event": "floor.broken"}'))
        ->withOtlp(OtlpPost::of('{"resourceSpans": []}', '{"resourceMetrics": []}'));
}

it('reads back every payload it writes, and the ledger\'s scope', function (): void {
    $read = DeliveryFile::decode(DeliveryFile::encode(deliveryWhole()));

    expect($read)->toEqual(deliveryWhole());
});

it('writes nothing it does not hold, and reads back an empty delivery', function (): void {
    $metricsOnly = Delivery::none()->withOtlp(OtlpPost::of(NotGiven::value(), '{}'));

    expect(DeliveryFile::encode(Delivery::none()))->toBe("{\n    \"format\": 1\n}")
        ->and(DeliveryFile::decode(DeliveryFile::encode(Delivery::none())))->toEqual(Delivery::none())
        ->and(DeliveryFile::decode(DeliveryFile::encode($metricsOnly)))->toEqual($metricsOnly);
});

it('reads back a comment written only over its planned state, which a later comment or none leaves written in place', function (): void {
    $overPlanned = deliveryWhole()->withComment('## Re-checked', CommentWrite::sticky()->overPlanned());
    $kept = $overPlanned->withLedger(LedgerPost::to(Scope::branch('main')))
        ->withAlert(AlertPost::of(BuiltinReporter::Slack, '{}'))
        ->withOtlp(OtlpPost::of(NotGiven::value(), '{}'))
        ->withKept(KeptPost::of(Companion::Coverage, Scope::branch('main')));

    expect(DeliveryFile::decode(DeliveryFile::encode($overPlanned)))->toEqual($overPlanned)
        ->and(DeliveryFile::encode(Delivery::none()->withComment('x', CommentWrite::sticky()->overPlanned())))
        ->toBe("{\n    \"format\": 1,\n    \"comment\": \"x\",\n    \"overPlanned\": true\n}")
        ->and($overPlanned->commentWrite()->isOverPlanned())->toBeTrue()
        ->and($kept->commentWrite()->isOverPlanned())->toBeTrue()
        ->and($overPlanned->withComment('## Passed', CommentWrite::sticky())->commentWrite()->isOverPlanned())->toBeFalse()
        ->and(deliveryWhole()->commentWrite()->isOverPlanned())->toBeFalse()
        ->and(DeliveryFile::decode('{"format": 1, "comment": "x", "overPlanned": false}'))
        ->toEqual(Delivery::none()->withComment('x', CommentWrite::sticky()));
});

it('reads back how a comment is written: by each run\'s own marker, hiding the old, or only over one already there', function (): void {
    $new = CommentWrite::of(Mode::New, 'https://ci.example/7');
    $hiding = CommentWrite::of(Mode::NewHidingOld, 'https://ci.example/7');
    $existing = CommentWrite::sticky()->existingOnly();
    $held = static fn(CommentWrite $write): Delivery => Delivery::none()->withComment('x', $write);
    $read = static fn(CommentWrite $write): Delivery|CannotJudge => DeliveryFile::decode(DeliveryFile::encode($held($write)));

    expect(DeliveryFile::encode(Delivery::none()->withComment('x', $new)))
        ->toBe("{\n    \"format\": 1,\n    \"comment\": \"x\",\n    \"commentMode\": \"new\",\n    \"commentRun\": \"https://ci.example/7\"\n}")
        ->and($read($new))->toEqual($held($new))
        ->and($read($hiding))->toEqual($held($hiding))
        ->and($read($existing))->toEqual($held($existing))
        ->and($read(CommentWrite::of(Mode::Sticky, 'https://ci.example/7')))->toEqual($held(CommentWrite::sticky()))
        ->and(DeliveryFile::decode('{"format": 1, "comment": "x", "commentMode": "loud", "commentRun": ""}'))
        ->toBeInstanceOf(CannotJudge::class);
});

it('reads as many alerts to one channel as a verdict sends, one of each kind', function (): void {
    $alerts = Delivery::none();

    foreach (AlertEvent::cases() as $event) {
        $alerts = $alerts->withAlert(AlertPost::of(BuiltinReporter::Slack, sprintf('{"event": "%s"}', $event->value)))
            ->withAlert(AlertPost::of(BuiltinReporter::Webhook, '{}'));
    }

    $limited = Delivery::none()->withAlert(AlertPost::of(BuiltinReporter::Slack, '{}')->within(PostLimits::of(Seconds::of(3.0), Seconds::of(9.0))));

    expect(DeliveryFile::decode(DeliveryFile::encode($alerts)))->toEqual($alerts)
        ->and(DeliveryFile::decode(DeliveryFile::encode($limited)))->toEqual($limited)
        ->and(DeliveryFile::decode('{"format": 1, "alerts": [{"channel": "slack", "body": "{}"}]}'))
        ->toEqual(Delivery::none()->withAlert(AlertPost::of(BuiltinReporter::Slack, '{}')));
});

it('refuses a delivery that names where to send, where the store is, or which variable to read, never following it', function (string $json, string $where): void {
    expect(DeliveryFile::decode($json))->toEqual(CannotJudge::because(sprintf(
        'The delivery cannot be read, so deliver sends nothing: %s is not a key deliver takes from a delivery.',
        $where,
    )));
})->with([
    'a store' => ['{"format": 1, "ledger": {"scope": "refs/heads/main", "store": {"use": "s3", "with": {"bucket": "attacker"}}}}', 'delivery.ledger.store'],
    'a bucket' => ['{"format": 1, "ledger": {"scope": "refs/heads/main", "bucket": "attacker"}}', 'delivery.ledger.bucket'],
    'an account' => ['{"format": 1, "ledger": {"scope": "refs/heads/main", "account": "attacker"}}', 'delivery.ledger.account'],
    'a container' => ['{"format": 1, "ledger": {"scope": "refs/heads/main", "container": "attacker"}}', 'delivery.ledger.container'],
    'a prefix' => ['{"format": 1, "ledger": {"scope": "refs/heads/main", "prefix": "elsewhere"}}', 'delivery.ledger.prefix'],
    'an endpoint' => ['{"format": 1, "ledger": {"scope": "refs/heads/main", "endpoint": "https://evil.example"}}', 'delivery.ledger.endpoint'],
    'a repository' => ['{"format": 1, "repository": "attacker/repo"}', 'delivery.repository'],
    'a pull request' => ['{"format": 1, "comment": "x", "pullRequest": 7}', 'delivery.pullRequest'],
    'an alert\'s URL' => ['{"format": 1, "alerts": [{"channel": "slack", "body": "{}", "url": "https://evil.example"}]}', 'delivery.alerts[0].url'],
    'an alert\'s variable' => ['{"format": 1, "alerts": [{"channel": "webhook", "body": "{}", "secretEnv": "AWS_SECRET_ACCESS_KEY"}]}', 'delivery.alerts[0].secretEnv'],
    'an OTLP endpoint' => ['{"format": 1, "otlp": {"metrics": "{}", "endpoint": "https://evil.example"}}', 'delivery.otlp.endpoint'],
    'OTLP headers' => ['{"format": 1, "otlp": {"metrics": "{}", "headers": "OTEL_EXPORTER_OTLP_HEADERS"}}', 'delivery.otlp.headers'],
]);

it('refuses a scope that is none, and a channel that sends no alert', function (string $json, string $why): void {
    expect(DeliveryFile::decode($json))
        ->toEqual(CannotJudge::because(sprintf('The delivery cannot be read, so deliver sends nothing: %s', $why)));
})->with([
    'a scope that is none' => ['{"format": 1, "ledger": {"scope": "main"}}', 'delivery.ledger.scope is not a scope.'],
    'a scope that climbs' => ['{"format": 1, "ledger": {"scope": "refs/heads/../x"}}', 'delivery.ledger.scope is not a scope.'],
    'a scope that climbs out of the default branch\'s' => ['{"format": 1, "ledger": {"scope": "refs/heads/main/../../x"}}', 'delivery.ledger.scope is not a scope.'],
    'an absolute scope' => ['{"format": 1, "ledger": {"scope": "/refs/heads/main"}}', 'delivery.ledger.scope is not a scope.'],
    'a scope with encoded separators' => ['{"format": 1, "ledger": {"scope": "refs%2Fheads%2Fmain"}}', 'delivery.ledger.scope is not a scope.'],
    'a scope with a backslash' => ['{"format": 1, "ledger": {"scope": "refs/heads/main\\\\..\\\\x"}}', 'delivery.ledger.scope is not a scope.'],
    'a scope that is no text' => ['{"format": 1, "ledger": {"scope": 7}}', 'delivery.ledger.scope is not text.'],
    'more alerts to one channel than a verdict sends' => [
        fn(): string => sprintf('{"format": 1, "alerts": [%s]}', implode(', ', array_fill(0, 5, '{"channel": "slack", "body": "{}"}'))),
        'delivery.alerts[4] is not within the alerts a verdict sends to one channel.',
    ],
    'a reporter that sends no alert' => ['{"format": 1, "alerts": [{"channel": "otlp", "body": "{}"}]}', 'delivery.alerts[0].channel is not an alert channel.'],
    'an alert that waits less than no time' => ['{"format": 1, "alerts": [{"channel": "slack", "body": "{}", "timeout": -1}]}', 'delivery.alerts[0].timeout is not a number of seconds, none negative.'],
    'another format' => ['{"format": 2}', 'delivery.format is not format 1.'],
    'no format' => ['{}', 'delivery.format is missing.'],
]);

it('keeps one object of each kind beside the ledger, the last a run kept', function (): void {
    $twice = Delivery::none()
        ->withKept(KeptPost::of(Companion::Coverage, Scope::pullRequest(7)))
        ->withKept(KeptPost::of(Companion::Coverage, Scope::branch('main')));

    expect($twice->kept())->toEqual([KeptPost::of(Companion::Coverage, Scope::branch('main'))])
        ->and(DeliveryFile::encode($twice))->toContain("\"kept\": {\n        \"coverage.json.gz\": \"refs/heads/main\"\n    }")
        ->and(DeliveryFile::decode('{"format": 1, "kept": {}}'))->toEqual(Delivery::none());
});

it('refuses an object the store keeps no such object as, or a scope that is none, for one', function (string $json, string $why): void {
    expect(DeliveryFile::decode($json))
        ->toEqual(CannotJudge::because(sprintf('The delivery cannot be read, so deliver sends nothing: %s', $why)));
})->with([
    'another file' => ['{"format": 1, "kept": {"../ledger.json.gz": "refs/heads/main"}}', 'delivery.kept.../ledger.json.gz is not a key deliver takes from a delivery.'],
    'a scope that is none' => ['{"format": 1, "kept": {"coverage.json.gz": "../main"}}', 'delivery.kept.coverage.json.gz is not a scope.'],
]);
