# Milpa ToolRuntime

> The **AI tool-execution runtime** for the Milpa PHP framework, built on **`milpa/core`**. It runs the loop every Milpa module declares: `plugin → capability → tool → verification → event → result`. `#[Tool]`-attributed methods become a registry pipeline — resolve, validate, authorize, execute, audit — with policy gates, rate limiting, channel-aware rendering, and human/agent verification as first-class seams.

[![CI](https://github.com/getmilpa/tool-runtime/actions/workflows/ci.yml/badge.svg)](https://github.com/getmilpa/tool-runtime/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/milpa/tool-runtime.svg)](https://packagist.org/packages/milpa/tool-runtime)
[![PHP](https://img.shields.io/badge/php-%E2%89%A5%208.3-777bb4.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg)](LICENSE)

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
  `register()` marks it `ToolOptions(mutating: true, requiresConfirmation: true)`, so
  **any** call through `ToolRegistry::call()` — whether it's a request or a resolve — hits
  the registry's own step-4 confirmation gate (see [The pipeline](#the-pipeline)) *before*
  `HumanVerifyTool::handle()` ever runs.

### Through the registry: the generic confirm-token dance

Calling `human_verify` via `$registry->call()` behaves exactly like any other
`requiresConfirmation` tool: the first call does **not** run `handle()`. It mints a
`confirm_token` and returns the registry's generic wrapper instead — no `request_id`
anywhere, because this wrapper is generic and knows nothing about `HumanVerifier`:

```php
use Milpa\ToolRuntime\Verification\HumanVerifier;
use Milpa\ToolRuntime\Verification\HumanVerifyTool;

(new HumanVerifyTool(new HumanVerifier()))->register($registry);

$registry->call('human_verify', [
    'subject' => 'gate:report.publish',
    'decision' => 'grant',
    'principal' => 'agent:claude',
    'request_id' => 'req-123',
], $ctx);
// -> ToolResult success, data: [
//      'requires_confirmation' => true,
//      'confirm_token' => '7e01bf71...',
//      'action_summary' => 'human_verify(subject=gate:report.publish, decision=grant, principal=agent:claude)',
//      'expires_at' => '2026-07-07T02:16:26+00:00',
//    ]
// handle() has NOT run — HumanVerifier::grant() has not been called yet.

$registry->call('human_verify', [
    'subject' => 'gate:report.publish',
    'decision' => 'grant',
    'principal' => 'agent:claude',
    'request_id' => 'req-123',
    'confirm_token' => '7e01bf71...',   // from the previous response
], $ctx);
// -> ToolResult success, data: [
//      'status' => 'passed', 'reason' => null, 'verifier' => 'human_verify',
//      'principal' => 'agent:claude', 'missing' => [], 'metadata' => [],
//    ]
// NOW handle() ran, using the exact args ConfirmationTokenStore stored at create() time
// (not whatever you pass on the second call) — this is what actually invoked HumanVerifier::grant().
```

The second call must replay the same arguments as the first, plus `confirm_token`;
`ConfirmationTokenStore::consume()` hands `ToolRegistry::call()` back the args it stored,
and those — not the ones on the redeeming call — are what `handle()` receives.

⚠️ Calling `human_verify` through the registry **without** a `decision` (to open a request)
hits the same gate: the first call only returns a `confirm_token`, and redeeming it invokes
`handle()` with no `decision`, which — being `HumanVerifyTool`'s own request phase — returns
*another* confirmation, this one carrying `request_id`. Resolving that request then needs a
**second** confirm-token round trip (this time with `decision` in the args) — four registry
calls end-to-end. For the two-phase `request_id` flow `HumanVerifyTool` was built around,
skip the registry and call the tool directly — see below.

### Direct usage: the two-phase `request_id` flow

`HumanVerifyTool`'s request → resolve round trip (open a request, get a `request_id`,
resolve it later with that id) is reached by calling `handle()` directly — this is how the
package's own tests exercise it (`tests/Unit/Verification/HumanVerifyToolTest.php`), and
it's the sanctioned way to drive D8 verification programmatically, independent of the
registry's confirmation gate:

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
| `ToolRegistry` | The pipeline: `register()` to add a tool by hand, `call()` to run resolve→validate→authorize→execute→audit, `getTools()` / `getToolsWithinBudget()` for LLM/MCP exposure. |
| `Rendering\RendererRegistry` | Picks a `ChannelRendererInterface` for a `ToolResult` based on `ToolContext::$channel`, falling back to a default renderer or raw JSON. |
| `Contracts\LlmServiceInterface` | The seam a plugin implements to provide LLM access (`generateResponse()`) and other plugins consume to get one, without depending on a specific provider. |

## Requirements

- PHP **≥ 8.3**
- [`milpa/core`](https://packagist.org/packages/milpa/core) **^0.2**
- [`psr/log`](https://packagist.org/packages/psr/log) **^3**

## Contributing

Contributions are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md). Please report security
issues via [SECURITY.md](SECURITY.md), and note that this project follows a
[Code of Conduct](CODE_OF_CONDUCT.md).

## License

[Apache-2.0](LICENSE) © the Milpa authors.

---

Milpa is designed, built, and maintained by **[TeamX Agency](https://teamx.agency)**.
