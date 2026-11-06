# Reporters

A reporter renders a verdict for one audience: the console, a file, a
comment, a chat message, a badge. It implements
`NightWorksIO\MutationGate\Port\Reporter`
([ADR-0009](../decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md)),
is registered with `Extensions::withReporter()`, and is chosen as an entry of
`reports`.

## What the port asks

| Method | What it answers |
|--------|-----------------|
| `report(Verdict)` | Writes the verdict for its audience, and answers `Written` with where, or `NotWritten` with why not |

A reporter that fails says so, and changes no exit code: the verdict is the
gate's to give, and a report only renders it.

The `Verdict` holds everything every built-in reporter renders: its
`judgement()`, each tree's verdict, the new-code, security and owner sets, the
reach and its reasons, the kill matrix, what the run spent and saved, and its
warnings and failures.

## An example

```php
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Key;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Extension\Configurable;
use NightWorksIO\MutationGate\Port\Reporter;

final readonly class JudgementFile implements Reporter, Configurable
{
    private function __construct(private string $path)
    {
    }

    public static function fromOptions(Options $options): self|Invalid
    {
        $path = $options->text(Key::of('path'));

        return is_string($path) ? new self($path) : Invalid::because(Problem::at('path', 'A path to write to.'));
    }

    public function report(Verdict $verdict): Written|NotWritten
    {
        return file_put_contents($this->path, $verdict->judgement()->value) === false
            ? NotWritten::because(sprintf('%s could not be written.', $this->path))
            : Written::to($this->path);
    }
}
```

A config chooses it by its class, with its options under `with`:

```json
{
    "reports": [
        { "use": "Acme\\Gate\\JudgementFile", "with": { "path": "build/judgement.txt" } }
    ]
}
```

There is no kit for reporters: a reporter's test hands it a verdict and reads
what it wrote.
