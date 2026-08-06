<p align="center">
  <a href="https://github.com/getmilpa">
    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/getmilpa/core/main/art/lockup/milpa-lockup-v-color-dark.svg">
      <img src="https://raw.githubusercontent.com/getmilpa/core/main/art/lockup/milpa-lockup-v-color-light.svg" alt="Milpa" width="300">
    </picture>
  </a>
</p>

<p align="center">
  <strong>Milpa Agent</strong><br>
  <em>Long-running coding sessions for the Milpa PHP framework.</em>
</p>

<p align="center">
  <a href="https://packagist.org/packages/milpa/agent"><img alt="Packagist" src="https://img.shields.io/packagist/v/milpa/agent.svg"></a>
  <a href="https://www.php.net/"><img alt="PHP" src="https://img.shields.io/badge/php-%E2%89%A5%208.3-777bb4.svg"></a>
  <a href="LICENSE"><img alt="License" src="https://img.shields.io/badge/license-Apache--2.0-blue.svg"></a>
</p>

# Milpa Agent

An agent that answers one question needs nothing. An agent that works for an hour needs the session
to be **a thing that exists** — something you can pause, resume, audit and stop.

That is all this package is: a session is an **event-sourced stream** whose events are the
conversation, whose human gates are the permissions, and whose state carries the plan. It brings no
LLM client (`milpa/ai-gateway` has one), no tools (`milpa/tool-runtime` has those), and no storage
(`milpa/event-store` has that). It brings the vocabulary that turns those into a working day.

## Install

```bash
composer require milpa/agent
```

## The shape

```php
use Milpa\Agent\SessionStore;
use Milpa\EventStore\FileEventStore;

$sessions = new SessionStore(new FileEventStore(__DIR__ . '/var/agent-sessions.jsonl'));

$sessions->start('migrate-inventory', 'move the Inventario plugin to sqlite');
$sessions->recordTurn('migrate-inventory', 'user', 'start with the repository');
$sessions->recordToolCall('migrate-inventory', 'make', ['what' => 'entity'], 'ok: created');

// A different process, tomorrow:
$session = $sessions->load('migrate-inventory');
$session->goal;        // 'move the Inventario plugin to sqlite'
$session->window();    // what to send the model: summary + recent turns
$session->isRunnable();
```

## Why event-sourced

Because what matters about a long session is both **where it ended up** and **how it got there**. A
row with `current_state` answers the first and erases the second on every `UPDATE` — and the second
is exactly what someone wants the next day: which permission was granted and when, what the agent
asked, what it was told, at which step it went wrong. With a stream, *"the agent ran forty steps on
its own"* is a verifiable claim instead of a hope.

It also makes the rest possible. Resuming is replaying. Compacting is appending a summary **without
losing the turns it summarises** — `window()` shortens what the model sees while the stream keeps
everything, so the evidence behind a decision survives the context that produced it. A pending
question is an event without its pair, not a flag someone has to remember to clear.

Nothing is ever rewritten. Revoking a permission does not delete the grant; it appends on top of it.
A log you can edit stops being useful for the one thing a log is for.

## What a session carries

| | |
|---|---|
| `goal` / `mode` | why it was opened, and how much autonomy it runs with |
| `turns` | the conversation, including tool calls — resuming without them means repeating work already done |
| `plan` / `todos` | the plan lives in the stream, not in the prompt: one that only exists inside the context is lost at the first compaction, which is exactly when it matters most. `stateBriefing()` renders it back into the window, so the agent rereads what it wrote — a plan you can only audit is half a feature |
| `permissions` | consented **per operation and per session** — *"yes to `make`, in this session"* is a sentence someone can evaluate; *"yes to whatever the agent decides"* is not |
| `question` | while one is open the session is not runnable. An agent that "asks" and proceeds on its assumption did not ask, it narrated |
| `decisions` | what a human resolved when the session stopped to ask — with **who** resolved it, and whether that identity was verified |
| `runFirst` / `obligationDeclared` | a standing obligation (`--first`) outlives the turn that typed it. Passing an empty one **lifts it** — the same authority that set it, unsetting it — and the lift ends the *discipline*, not just this turn's list: declared or lifted, the last one wins |
| `summary` / `compactedThrough` | what the model is spared, never what the log forgets |

## The policy

`SessionPolicy` is the piece that decides how far an automatic process may go over someone's code, so
it is written to be argued with by reading it: it calls nobody, appends nothing, and takes three facts
about an operation.

```php
$policy->decide($session, 'make', mutating: true, requiresSignature: false);
// Allow | AskPermission | RequireSignature
```

**The order of the rules is the rule.** The signature is evaluated *before* the granted permission and
before the mode. Evaluated later, a `grant` on that operation — or an `auto` mode — would let it
through, and there goes the only gate that names the concrete call instead of the category.

Reading is never asked. An agent you must authorise for every query is not a supervised agent, it is a
useless one — and the attention you spend on what does not matter is the attention you stop spending
on what does.

## Deadlines: a question does not wait forever

A question can carry an `expiresAt`, and passing it **ends the session** with a reason:

```php
$store->ask($id, new PendingQuestion('perm:make', '…?', ['yes','no'], expiresAt: $deadline));
$store->expireIfDue($id, new DateTimeImmutable());   // true when it closed the window
```

**Expiry is declared, not derived.** It could be computed by comparing the deadline with the clock,
and that is exactly why it is appended: a derived expiry leaves no trace of *when* it was noticed, and
a session that died of silence is precisely the case where somebody will want to know. The clock did
not close the session — `expireIfDue()` did, at a concrete instant, and left a fact.

The event is called `session.answer_window_closed` and not "question expired" on purpose. **The
question did not expire**: it is still the same question and still valid. What ran out is the
authority to answer it *within this session*.

It ends the whole session rather than just the question, because the question exists so the agent can
continue and without an answer it cannot: closing it and leaving the session alive would send the
agent to ask the same thing again or — worse — to proceed without the permission it was waiting for.

`expiresAt: null` still means "waits indefinitely", and that remains a valid choice. **There is no
default window**: how long a human has to answer depends on who operates the agent, and a number
invented in this package would kill sessions belonging to people who never chose it.

## Who answered

`answer()` takes an optional `Principal`, and the principal carries whether its identity was
**verified**:

```php
$store->answer($id, 'perm:make', 'yes', new Principal('actor:member:42', verified: true));
$store->answer($id, 'perm:make', 'yes', Principal::fromTerminal($user, $host));  // verified: false
```

The two sources are not worth the same, and merging them would be worse than storing nothing. An
authenticated context has a credential behind it; a terminal reports the OS user, **which anyone
holding that terminal can be**. Recording the second as if it were the first would manufacture a chain
of custody that does not exist — *"rod authorised it"* when what is known is *"whoever had rod's
machine authorised it"*.

Replay never raises trust: anything that does not explicitly say `verified: true` reads as unverified.
And `null` — nobody said who — stays `null`; sessions recorded before this existed are not given an
invented principal.

## Why a question was asked — as a code

Since 0.4 a `PendingQuestion` carries `reason`: a stable code (`permission`, `signature`,
`target_not_named`) next to the human-readable text. The text gets rewritten and translated; the
code does not — a projection counting how many pauses each authority produced must never parse
prose. And when a question is answered, the resulting decision **inherits** `reason` and `why` from
the question that produced it, so a confirmation can be consumed as data: "this operation, over this
target, was confirmed by the human" is readable from the fact alone. That is what closes the loop —
a *yes* to "did you mean HelloPlugin?" names the target, the retry passes, and a yes to one target
names no other.

## Compaction

When a session outgrows its window, `Compactor` appends a summary of the old turns and keeps the
recent ones intact. **The window shrinks; the history does not.**

```php
$compactor = new Compactor(maxTurns: 40, keepRecent: 12);
$compactor->compactIfNeeded($sessions, $session);   // returns the summary it appended, or null
```

Two details that are the whole design:

The threshold counts turns **not yet summarised**, not all of them. Counting the total would make a
long session compact on every turn — the total never goes down — appending one summary per turn, each
hiding the last.

Recent turns survive intact because a summary answers *"what has happened"* and not *"what were we
doing a minute ago"*, and the second is what the model needs to take the next step. Summarising
everything leaves a session that knows its history and not its place — visible as an agent that,
right after compacting, repeats work or asks something it just got answered.

The default `FactualSummarizer` **does not call the model.** For a coding session what compaction
loses is not nuance, it is facts: the goal, which tools ran, what was authorised, what the human
decided, what is still pending. All of it is already in the stream, exact, and deriving it costs no
call and cannot hallucinate. And a made-up summary is worse than expensive — it gets *appended as
what happened*, and from then on the model works from a version of the session nobody wrote. Swap in
your own `Summarizer` if you want prose.

## Autonomy modes

`ask` pauses before anything that mutates. `acknowledge` announces and continues. `auto` runs to the
end.

What **no** mode can skip is a signature. An operation declaring `requiresConfirmation` demands
consent that names *that* call with *those* arguments, and pre-approving "whatever the agent decides"
is signing a blank cheque. `auto` means *don't ask me about the reversible* — never *don't ask me*.
That line lives in the type and not in a config file, because a line you can move with an environment
variable is not a line.

## License

Apache-2.0 © Rodrigo Vicente - TeamX Agency

---

Milpa is designed, built, and maintained by **[Rodrigo Vicente - TeamX Agency](https://teamx.agency/?utm_source=github&utm_medium=readme&utm_campaign=milpa&utm_content=agent)**.
