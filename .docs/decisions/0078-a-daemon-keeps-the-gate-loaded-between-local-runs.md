# ADR-0078: A daemon keeps the gate loaded between local runs

**Status:** Accepted
**Date:** 2026-11-05
**Amends:** [0010](0010-the-gate-runs-while-you-work-and-before-you-push.md): where `watch`, pre-commit and pre-push run

## Context

`watch`, pre-commit and pre-push run while a person works (ADR-0010). Each
starts a PHP process that loads the gate, resolves the config and composes
the adapters before it runs anything. PHP's command line usually runs
without opcache, so every start compiles the gate's classes again. On the
gate's own repository, `pre-commit` takes about 20 s from start to answer,
and a fresh `serve` takes about 8 s to compose before it listens.

A hook runs at every commit and push, and `watch` is restarted whenever a
person opens a terminal. Paying the start every time is the cost a person
feels most.

## Decision

1. **`mutation-gate serve` keeps the gate loaded for one project.** It
   listens on a Unix socket at `.mutation-gate/serve.sock`, named relative to
   the project's root. The socket is made readable and writable by its owner
   alone. A socket nobody answers on is removed before a daemon binds it, and
   a second daemon of the same project refuses to start.
2. **The daemon runs the whole command; its clients are thin.**
   - `watch`, pre-commit and pre-push send it one line of JSON: their command
     line, the few variables of their environment a child takes, git's lines
     on standard input for pre-push, and whether their terminal is decorated.
   - A child takes only the variables the pre-commit framework names a push
     with and the terminal's size, unsetting those the client lacks. Otherwise
     it runs in the daemon's own environment, so a client never sends a token
     it holds.
   - The daemon forks a child for each request. The child runs the command
     line in the gate, in the project's root, and sends back what it writes
     to its output and its error output as lines of JSON as they are written,
     then the exit code.
   - The client writes that output unchanged and exits with the code.
   - Each child leads a process group of its own. Where its client goes, as
     a Ctrl-C ends it, the daemon stops the child with every process under
     it. A long `watch` never holds up a hook, as each runs in its own child.
   - The child runs with `MUTATION_GATE_SERVED` set, so the command it runs is
     never handed to a daemon again.
3. **A daemon serves one root, and stops when what it loaded changes.**
   - Every request carries the client's identity: the root's real path, the
     gate's version, and the digests of `composer.lock` and of the config file.
   - A request from another root is refused.
   - A request whose version, lock or config differ from those the daemon
     started from is refused, and the daemon closes its socket. The children
     still running finish, and then it exits.
   - A refused client runs the command in its own process, and says why.
4. **A request goes only to the user's own daemon.** Another user who could
   answer on the socket would read the request, git's lines among it, and
   forge the verdict a hook acts on. So, failing closed:
   - A client sends nothing unless `.mutation-gate` is no symbolic link, is
     the user's own and no other may read, write or enter it, and the socket
     is a socket, no symbolic link, and the user's own.
   - A client sends nothing unless the platform tells the process at the
     other end of the connection, `SO_PEERCRED` on Linux and `LOCAL_PEERPID`
     on macOS through PHP's sockets extension, and the user, who is not root,
     may signal it, which the system allows only for the user's own
     processes. A daemon refuses a client the same way. A peer that cannot be
     told is refused, so where PHP cannot tell one, no client uses a daemon
     and no daemon starts.
   - A daemon makes `.mutation-gate` where it is not there, keeps it to its
     owner alone (`0700`) where it is the owner's, binds the socket with no
     access for others, and refuses to start where either is not its owner's
     own.
   - A client that refuses a daemon runs the command in its own process, and
     says why.
5. **The daemon stops on its own.** It stops after `local.serveIdle` (30
   minutes by default) with no request and no child running, and on
   `serve --stop`, which stops its children too.
6. **Only `watch` starts a daemon, as `local.serveStart` says.**
   - With `watch`, the default, `watch` starts a daemon where none answers,
     or where the one running refused it, and then runs in it.
   - With `never`, no command starts one.
   - pre-commit and pre-push use a daemon only where one already runs, so a
     hook never leaves a process behind.
   - A daemon `watch` starts runs with its streams on the null device and
     leaves `watch`'s session, so it outlives `watch`.
7. **Forking is the gate's own script's.** `bin/mutation-gate` hands the
   console a `Forking`, which forks, waits for, stops and detaches processes.
   Without one, `serve` refuses to start.

## Consequences

- A command a daemon runs starts from the daemon's loaded classes and
  composes in the child, so it skips PHP's start and the compilation of the
  gate's classes.
- A daemon serves the config it started with until a client finds it
  changed. Editing the config, or running `composer update`, restarts it at
  the next `watch` and makes the hooks run on their own until then.
- The keys, the plan and the verdict are those the client's own process
  would have reached for the same config, as the child runs in the same
  root with the client's standard input and push variables. A variable a
  person changed in their shell after the daemon started reaches the child
  only where it is one a child takes.
- The daemon is local: CI runs no `serve`, and nothing in CI uses one.

## Alternatives

| Alternative | Why not |
|---|---|
| **The daemon only lends booted workers and the coverage map, and each client runs the flow itself** | Each client still pays PHP's start and the gate's composition, which is the cost the daemon exists to remove. |
| **A request and response directory, as the warm Pest client uses** | A socket tells the daemon at once when a client goes, so a Ctrl-C stops its child; a directory would need polling for both. |
| **One request at a time in the daemon's own process** | A long `watch` would hold up every hook, and a broken run would leave the daemon in its state. |
| **Forwarding the client's whole environment** | It sends every token the client holds to whatever answers on the socket, and the daemon's own environment already holds what the project's tests read. |
| **The hooks start a daemon too** | A hook would leave a long-lived process behind on a machine that never asked for one. |

## References

- [ADR-0010](0010-the-gate-runs-while-you-work-and-before-you-push.md): watch, pre-commit and pre-push
- [ADR-0023](0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md): warm workers, whose clients a child's run uses as any run's do
