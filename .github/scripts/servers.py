#!/usr/bin/env python3
"""The database servers the suite's reconnecting streams are tested against, from test-services.json.

Usage: servers.py services     print `services=<the services as one line of JSON>`, a job's output
       servers.py addresses    print each server's `MUTATION_GATE_TEST_<ID>=127.0.0.1:<port>`, for $GITHUB_ENV

test-services.json holds each server as `jobs.<job_id>.services` takes it, by
its id, its image pinned by digest. A job starts them as its services, and
each is reached on the host at the port its first mapping publishes, in the
variable tests/Support/Servers.php reads.

Two halves: `services` and `addresses` decide from the document they are
given, and `main` reads the file and prints.
"""
import json
import sys
from pathlib import Path

SERVICES = Path(__file__).resolve().parent.parent / "test-services.json"
HOST = "127.0.0.1"
VARIABLE = "MUTATION_GATE_TEST_{}"


def services(document: dict) -> str:
    """The services as one line of JSON, for a job's `services: ${{ fromJSON(...) }}`."""
    return json.dumps(document, separators=(",", ":"), sort_keys=True)


def addresses(document: dict) -> list[str]:
    """Each server's variable and the address it is reached on, the host port its first mapping publishes."""
    return [
        f"{VARIABLE.format(name.upper())}={HOST}:{service['ports'][0].split(':', 1)[0]}"
        for name, service in sorted(document.items())
    ]


def main(argv: list[str]) -> int:
    document = json.loads(SERVICES.read_text(encoding="utf-8"))
    match argv:
        case ["services"]:
            print(f"services={services(document)}")
        case ["addresses"]:
            print("\n".join(addresses(document)))
        case _:
            print(__doc__, file=sys.stderr)
            return 2
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
