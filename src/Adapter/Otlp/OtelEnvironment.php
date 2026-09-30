<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Otlp;

use function array_diff_key;
use function array_key_exists;
use function explode;

use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\ThisPackage;

use function rawurldecode;
use function str_contains;
use function trim;

/**
 * What OpenTelemetry's own variables say: where to export, with which
 * headers, and the resource's name and attributes. Each list is
 * `key=value,key=value`, its values percent-encoded (ADR-0016, decision 17).
 */
final readonly class OtelEnvironment
{
    /** Where an OTLP/HTTP collector listens where nothing says otherwise. */
    public const string LOCAL = 'http://localhost:4318';

    private const string SERVICE = 'service.name';

    private function __construct(private Variables $variables)
    {
    }

    public static function of(Variables $variables): self
    {
        return new self($variables);
    }

    /** `OTEL_EXPORTER_OTLP_ENDPOINT`, or a collector on this machine. */
    public function endpoint(): string
    {
        $endpoint = $this->variables->valueOf('OTEL_EXPORTER_OTLP_ENDPOINT');

        return $endpoint === '' ? self::LOCAL : $endpoint;
    }

    /**
     * `OTEL_EXPORTER_OTLP_HEADERS`, each by its name.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->pairs($this->variables->valueOf('OTEL_EXPORTER_OTLP_HEADERS'));
    }

    /**
     * The resource: `OTEL_RESOURCE_ATTRIBUTES`, with `service.name` from
     * `OTEL_SERVICE_NAME`, from those attributes, or `mutation-gate`, in that
     * order.
     *
     * @return array<string, string>
     */
    public function resource(): array
    {
        $attributes = $this->pairs($this->variables->valueOf('OTEL_RESOURCE_ATTRIBUTES'));
        $named = $this->variables->valueOf('OTEL_SERVICE_NAME');
        $service = match (true) {
            $named !== '' => $named,
            array_key_exists(self::SERVICE, $attributes) => $attributes[self::SERVICE],
            default => ThisPackage::NAME,
        };

        return [self::SERVICE => $service, ...array_diff_key($attributes, [self::SERVICE => $service])];
    }

    /**
     * A list of `key=value` pairs; a pair with no `=` or no key is left out.
     *
     * @return array<string, string>
     */
    private function pairs(string $list): array
    {
        $pairs = [];

        foreach (explode(',', $list) as $pair) {
            [$key, $value] = str_contains($pair, '=') ? explode('=', $pair, 2) : ['', ''];
            $key = trim($key);
            if ($key !== '') {
                $pairs[$key] = rawurldecode(trim($value));
            }
        }

        return $pairs;
    }
}
