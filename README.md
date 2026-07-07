<p align="center">
  <a href="https://github.com/getmilpa">
    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/getmilpa/core/main/art/lockup/milpa-lockup-v-color-dark.svg">
      <img src="https://raw.githubusercontent.com/getmilpa/core/main/art/lockup/milpa-lockup-v-color-light.svg" alt="Milpa" width="300">
    </picture>
  </a>
</p>

# Milpa ToolRuntime

> The **AI tool-execution runtime** for the Milpa PHP framework, built on **`milpa/core`**. It runs the loop every Milpa module declares: `plugin → capability → tool → verification → event → result`. `#[Tool]`-attributed methods become a registry pipeline — resolve, validate, authorize, execute, audit — with policy gates, rate limiting, channel-aware rendering, and human/agent verification as first-class seams.

[![CI](https://github.com/getmilpa/tool-runtime/actions/workflows/ci.yml/badge.svg)](https://github.com/getmilpa/tool-runtime/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/milpa/tool-runtime.svg)](https://packagist.org/packages/milpa/tool-runtime)
[![PHP](https://img.shields.io/badge/php-%E2%89%A5%208.3-777bb4.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg)](LICENSE)
[![Docs](https://img.shields.io/badge/docs-API%20reference-blue.svg)](https://getmilpa.github.io/tool-runtime/)

`milpa/tool-runtime` is where `milpa/core`'s agent-tool-readiness seam becomes a working
engine. `Milpa\Interfaces\Tooling\ToolProviderInterface` and `ToolRegistryInterface` are
contracts defined in core; this package is the concrete `ToolRegistry` that resolves,
validates, authorizes, executes, and audits every call, plus the `#[Tool]` attribute that
lets a plain PHP method declare itself as agent-callable. **No Doctrine, no HTTP kernel, no
concrete policy storage** — those live in your host application.

## Install

```bash
composer require milpa/tool-runtime
```

## Quick example

Attribute a method with `#[Tool]`; parameters describe themselves with `#[Param]`:

```php
use Milpa\ToolRuntime\Attributes\Param;
use Milpa\ToolRuntime\Attributes\Tool;
use Milpa\ToolRuntime\ToolResult;

final class NoteTools
{
    #[Tool('list_notes', 'List saved notes', scopes: ['notes:read'])]
    public function listNotes(
        #[Param('Page number', clamp: [1, 1000])] int $page = 1
    ): ToolResult {
        return ToolResult::success(['notes' => [], 'page' => $page]);
    }
}
```

`ToolScanner` reflects the class for `#[Tool]` methods and registers them; `ToolRegistry`
runs the full pipeline on every call:

```php
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\ToolRegistry;
use Milpa\ToolRuntime\ToolScanner;
use Psr\Log\NullLogger;

$registry = new ToolRegistry(new NullLogger());
(new ToolScanner($registry))->scan(new NoteTools());

$result = $registry->call('list_notes', ['page' => 1], ToolContext::cli());

$result->success;  // true
$result->data;     // ['notes' => [], 'page' => 1]
$result->toJson();  // {"success":true,"data":{...},"message":null,"error":null,"meta":{...}}
```

No `ToolContext` is required — `call()` defaults to `ToolContext::cli()` (full-access,
for scripts and tests). Real hosts build one per channel: `ToolContext::mcp($requestId,
$principal, $scopes)`, `ToolContext::telegram($chatId, $userId)`, or a custom `new
ToolContext(...)` for a web session.

## The pipeline

Every `ToolRegistry::call()` runs the same six steps, in order, regardless of who is
calling — a human over `cli`, an LLM over `mcp`, or a bot over `telegram`:

1. **Resolve** — look up the tool by name; an unknown name is a typed `ToolResult::error()`
   (`ToolResult::TOOL_NOT_FOUND`), never an exception.
2. **Validate** — `SchemaValidator` checks the arguments against the tool's JSON input
   schema (required fields, types), then applies numeric `clamps` before execution.
3. **Authorize** — `PolicyGate` checks the caller's `ToolContext` scopes against the tool's
   required scopes, then falls back to per-channel policy (`cli` allows all, `mcp` and `web`
   require auth by default). A host can plug in `PolicyRuleProviderInterface` for
   database-backed rules, and an optional `RateLimiterInterface` throttles by
   `channel:principal:tool`.
4. **Confirm** *(mutating tools only)* — a tool declared `confirm: true` (or matching a
   channel's `require_confirmation_for_mutating` policy) returns a `confirm_token` on the
   first call instead of executing; the caller replays the same arguments plus that token to
   proceed. `ConfirmationTokenStore` holds the pending action and its expiry.

   **The redemption contract, precisely:** on the first call, `ConfirmationTokenStore::create()`
   snapshots the *exact args of that call* (name + args + a 60s-default expiry) and hands back a
   random token. The caller is expected to replay the same arguments plus `confirm_token` on the
   second call — but the runtime does not diff or validate that replay: `ToolRegistry::call()`
   strips `confirm_token` off the incoming args, calls `ConfirmationTokenStore::consume($token,
   $name)`, and — if the token is valid, unexpired, and minted for this tool name — **discards
   whatever args the second call actually sent** and executes with the args stored at `create()`
   time instead. A token is one-time-use (deleted on consume) and matched only by `$name`, not by
   argument identity. Practically: the second call's args (other than `confirm_token` itself) are
   inert — the tool executes with the *first* call's arguments, not the second's.
5. **Execute** — the tool's callback runs with a soft timeout; a bare return value is
   wrapped in `ToolResult::success()` automatically, and an uncaught `Throwable` becomes
   `ToolResult::error()` (`ToolResult::INTERNAL_ERROR`) instead of propagating.
6. **Audit** — `ToolAuditLogger` records every call (success, failure, or rejection) via
   PSR-3, redacting sensitive argument fields (`password`, `token`, `secret`, …) before they
   ever reach a log line.

A `ToolContext` built with `mode: 'plan'` (or `ToolContext::asPlan()`) short-circuits after
step 3: it validates and authorizes but never executes, returning the would-be plan instead
— a dry-run for any tool, for free.

## Verification: `human_verify`

Some actions can't be authorized by scopes alone — they need a human or another agent to
say yes. `milpa/core` defines the seam: `Milpa\Interfaces\Verification\VerifierInterface`,
whose `verify()` returns a `VerificationResult` that may be `PENDING` and resolve later.
This package ships the reference implementation:

- **`HumanVerifier`** implements `VerifierInterface`. `verify()` cannot decide
  synchronously, so it returns `VerificationResult::pending()` and dispatches
  `verification.requested`; a later `grant()` / `reject()` call resolves it and dispatches
  `verification.granted` / `verification.rejected`.
- **`HumanVerifyTool`** exposes `HumanVerifier` as the `human_verify` tool — the *same*
  registry pipeline every other tool runs through, no special-cased transport. Its
  `register()` marks it `ToolOptions(mutating: true, requiresConfirmation: false)` — as of
  tool-runtime 0.2, the registry's generic step-4 confirmation gate (see
  [The pipeline](#the-pipeline)) is deliberately **bypassed** for `human_verify`, because
  `handle()` already owns its own two-phase `request_id` protocol (open a request, resolve it
  later). Stacking the registry's confirm-token gate on top of that used to produce a
  confusing 3-4 call choreography — see [Cambios en 0.2](#changed-in-02-the-double-gate-bypass)
  below.

  ⚠️ The bypass is not absolute: a channel whose policy sets
  `require_confirmation_for_mutating` (the built-in `telegram` policy does) still gates
  **any** `mutating: true` tool via `PolicyGate::requiresConfirmation()`, regardless of the
  tool's own `requiresConfirmation` flag. On `cli`, `mcp`, and `web` (none of which set that
  policy by default) the bypass is total.

### Through the registry: request → resolve in two calls

Since 0.2, calling `human_verify` via `$registry->call()` runs `handle()` directly — no
generic confirm-token wrapper in between. A full request → resolve round trip is exactly the
two calls `HumanVerifyTool` was designed around:

```php
use Milpa\ToolRuntime\Verification\HumanVerifier;
use Milpa\ToolRuntime\Verification\HumanVerifyTool;

(new HumanVerifyTool(new HumanVerifier()))->register($registry);

$request = $registry->call('human_verify', [
    'subject' => 'gate:report.publish',
], $ctx);
// -> ToolResult success, data: [
//      'subject' => 'gate:report.publish', 'policy' => 'single',
//      'request_id' => '06a1dda5-...',
//    ]
// handle() ran on THIS call — HumanVerifier::verify() ran and dispatched
// `verification.requested`. No confirm_token anywhere: the registry gate never ran.

$registry->call('human_verify', [
    'subject' => 'gate:report.publish',
    'decision' => 'grant',
    'principal' => 'agent:claude',
    'request_id' => $request->data['request_id'],
], $ctx);
// -> ToolResult success, data: [
//      'status' => 'passed', 'reason' => null, 'verifier' => 'human_verify',
//      'principal' => 'agent:claude', 'missing' => [], 'metadata' => [],
//    ]
// HumanVerifier::grant() ran and dispatched `verification.granted`.
```

Echo the `request_id` from the first call's response back on the resolving call — it is
`handle()`'s own correlation id (#7), not the registry's `confirm_token`; `human_verify` never
mints or expects a `confirm_token`.

### Direct usage: the two-phase `request_id` flow

The same request → resolve round trip is also reachable by calling `handle()` directly,
independent of any `ToolRegistry` — useful when you don't have a registry at hand (e.g. a
unit test), and exactly how the package's own tests exercise it
(`tests/Verification/HumanVerifyToolRegistryGateTest.php` exercises it through the registry;
call `handle()` directly for the registry-free version of the same flow):

```php
use Milpa\ToolRuntime\Verification\HumanVerifier;
use Milpa\ToolRuntime\Verification\HumanVerifyTool;

$tool = new HumanVerifyTool(new HumanVerifier($eventDispatcher));

$request = $tool->handle(['subject' => 'gate:report.publish']);
// -> ToolResult::confirmation(), $request->data['request_id'] === '06a1dda5-...'
// HumanVerifier::verify() ran and dispatched `verification.requested`.

$tool->handle([
    'subject' => 'gate:report.publish',
    'decision' => 'grant',
    'principal' => 'agent:claude',
    'request_id' => $request->data['request_id'],
]);
// -> ToolResult::success(), data: ['status' => 'passed', 'principal' => 'agent:claude', ...]
// HumanVerifier::grant() ran and dispatched `verification.granted`.
```

Any other `VerifierInterface` implementation — a deterministic rule, a quorum vote, an
external approval service — plugs into the same seam.

### Changed in 0.2: the double-gate bypass

Before 0.2, `HumanVerifyTool::register()` used `requiresConfirmation: true`, so **any** call
through `ToolRegistry::call()` — request or resolve alike — hit the registry's own step-4
confirmation gate *before* `handle()` ever ran. Opening a request took **two** registry calls
just to reach `handle()`'s own request phase (which itself returned a confirmation, this one
carrying `request_id`) — and resolving it needed a **third**, itself gated the same way. The
registry's generic wrapper carries no `request_id` (it knows nothing about `HumanVerifier`),
so a caller only ever saw the correlation id after redeeming a token they didn't know they'd
need. 0.2 sets `requiresConfirmation: false` instead, since `handle()`'s own `request_id`
round trip already *is* the confirmation protocol — the registry's generic one was pure
overhead for this tool specifically. `tests/Verification/HumanVerifyToolRegistryGateTest.php`
pins both the old (double-gated) and new (direct) behavior for reference.

### Events: what the payload actually carries

`HumanVerifier` dispatches three events — `verification.requested` (from `verify()`),
`verification.granted` and `verification.rejected` (from `grant()` / `reject()`) — through
the optional `MilpaEventDispatcherInterface` passed to its constructor. Every dispatch uses
the **same payload shape**: a single key, `'event'`, holding the event **object**, not a
flattened array of the request's fields:

```php
$dispatcher->dispatch('verification.requested', ['event' => $requestedEvent]);
// $requestedEvent instanceof Milpa\Events\VerificationRequestedEvent

$dispatcher->dispatch('verification.granted', ['event' => $grantedEvent]);
// $grantedEvent instanceof Milpa\Events\VerificationGrantedEvent

$dispatcher->dispatch('verification.rejected', ['event' => $rejectedEvent]);
// $rejectedEvent instanceof Milpa\Events\VerificationRejectedEvent
```

A listener reaches the data through the event object's accessors, **not** array keys —
`$payload['subject']` is always `null`/undefined; the subject lives at
`$payload['event']->getRequest()->subject`:

| Event | Accessors |
|-------|-----------|
| `VerificationRequestedEvent` | `getRequest(): VerificationRequest`, `getRequestId(): ?string` |
| `VerificationGrantedEvent` | `getRequest(): VerificationRequest`, `getResult(): VerificationResult`, `getRequestId(): ?string` |
| `VerificationRejectedEvent` | `getRequest(): VerificationRequest`, `getResult(): VerificationResult`, `getRequestId(): ?string` |

A listener that wants to work with any of the three (or with a future verifier's events)
should branch on the event's class, or narrow via `getRequest()`/`getResult()`, rather than
assume a flat array — this is defined and enforced by the event classes' own docblocks in
`milpa/core` (`Milpa\Events\Verification{Requested,Granted,Rejected}Event`).

## What lives where

| Layer | Package | Owns |
|-------|---------|------|
| Contracts | `milpa/core` | `ToolProviderInterface`, `ToolRegistryInterface`, `VerifierInterface`, capability/verification value objects and events — the seams, not the engine. |
| **Runtime** | **`milpa/tool-runtime`** (this package) | The concrete `ToolRegistry` pipeline, `#[Tool]`/`#[Param]` attributes + `ToolScanner`, `SchemaValidator`, `PolicyGate`, rate limiting, channel rendering, `ToolAuditLogger`, and the `human_verify` reference verifier. |
| Your app | your host / plugins | Concrete `PolicyRuleProviderInterface` (e.g. Doctrine-backed rules), `LoggerInterface`, channel renderers, and where policy decisions and audit logs are actually persisted. |

## API de facto

The types you construct and pass around day to day:

| Type | What it is |
|------|------------|
| `Contracts\ToolContext` | Who/where/what-scopes for one call — `principal`, `channel`, `scopes`, `mode`. Named constructors per channel: `cli()`, `mcp()`, `telegram()`. |
| `ToolResult` | The uniform return shape — `success`, `data`, `message`, `error`, `meta`. Factories for common shapes: `success()`, `error()`, `paginated()`, `detail()`, `confirmation()`, `blocked()`. |
| `ToolRegistry` | The pipeline: `register()` to add a tool by hand, `call()` to run resolve→validate→authorize→execute→audit, `getToolSummaries()` (plain-array LLM/MCP wire shape) / `getToolDefinitions()` (typed `list<ToolDefinition>`) / `getToolsWithinBudget()` for LLM/MCP exposure. |
| `Rendering\RendererRegistry` | Picks a `ChannelRendererInterface` for a `ToolResult` based on `ToolContext::$channel`, falling back to a default renderer or raw JSON. |
| `Contracts\LlmServiceInterface` | The seam a plugin implements to provide LLM access (`generateResponse()`) and other plugins consume to get one, without depending on a specific provider. |

## Requirements

- PHP **≥ 8.3**
- [`milpa/core`](https://packagist.org/packages/milpa/core) **^0.2**
- [`psr/log`](https://packagist.org/packages/psr/log) **^3**

## Documentation

**Full API reference: [getmilpa.github.io/tool-runtime](https://getmilpa.github.io/tool-runtime/)** — generated
straight from the source DocBlocks and dressed with the Milpa design system.

## Contributing

Contributions are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md). Please report security
issues via [SECURITY.md](SECURITY.md), and note that this project follows a
[Code of Conduct](CODE_OF_CONDUCT.md).

## License

[Apache-2.0](LICENSE) © TeamX Agency.

---

Milpa is designed, built, and maintained by **[TeamX Agency](https://teamx.agency/?utm_source=github&utm_medium=readme&utm_campaign=milpa&utm_content=tool-runtime)**.
