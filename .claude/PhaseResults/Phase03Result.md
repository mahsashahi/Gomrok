# Phase 3 — Shared kernel

## Execution Summary

- Phase: 03 — Shared kernel
- Start Datetime: 2026-09-08 11:34 PDT
- End Datetime: 2026-09-08 12:22 PDT
- Estimated Duration: 3–5h
- Actual Duration: 48m
- Tokens Used: N/A (no per-phase metering)
- Final Status: ☑ Done

## Work Completed

Built `src/Shared/**` — the value objects and infrastructure every module will depend on. No
business logic, no database schema. All five Phase 3 decisions were made first (see
`PhaseResults/PhaseDecisions.md` Phase 3 Q1–Q5); Phase 1 Q3 (identifiers) was **changed** to plain integers
mid-phase (documented separately, `Changelog.md` 2026-09-08).

- **Money & currency.** `Gomrok\Shared\Domain\Money` (readonly, wraps `brick/money`) with the
  "richer" API from Q1: `fromMinor`, `zero`, `toMinor`, `plus`, `minus`, `multipliedBy`,
  `percentage`, `ratioOf` (returns a decimal string), `allocate`, `convertTo(Currency, rate)`
  (caller supplies the rate), `format(locale)` (ext-intl), `equals`, `isZero/isPositive/isNegative`,
  `__toString`. Rounding fixed to `HALF_EVEN`. `Gomrok\Shared\Domain\Currency` (ISO 4217, wraps
  brick's currency data) and `Gomrok\Shared\Domain\CountryCode` (ISO 3166-1 alpha-2, format check).
- **Result / error model (Q3 — hybrid).** `Gomrok\Shared\Domain\Result` (non-generic:
  `ok($value)` / `err(DomainError)`, `isOk/isErr`, `value()`, `error()`),
  `Gomrok\Shared\Domain\DomainError` (readonly: `type`, `code`, `message`, `context`; factories
  `validation/notFound/conflict/forbidden/unauthorized/unsupported/ruleViolation`), and
  `Gomrok\Shared\Domain\ErrorType` (enum → HTTP status: validation/unsupported/ruleViolation=422,
  notFound=404, conflict=409, forbidden=403, unauthorized=401).
- **Clock (Q5 — PSR-20).** `Gomrok\Shared\Infrastructure\SystemClock implements
  Psr\Clock\ClockInterface` (UTC). `Gomrok\Tests\Support\FrozenClock` for tests.
- **Logging (Q4 — Monolog).** `Logging\LoggerFactory` builds a `Monolog\Logger` with
  `JsonFormatter` on a stdout `StreamHandler` + `Logging\CorrelationIdProcessor` (merges
  `correlation_id` + static `service`/`env` into every record's `extra`).
  `Gomrok\Shared\Infrastructure\CorrelationId` is the request-scoped holder;
  `CorrelationId::generate()` returns `bin2hex(random_bytes(8))` — a plain hex token, not a ULID.
- **HTTP.** `Http\CorrelationIdMiddleware` (PSR-15 — reuses a valid inbound `X-Correlation-Id`
  header or generates one, sets the request attribute + response header). `Http\JsonResponder`
  (`json()` + `problem()` = RFC-7807-ish body from a `DomainError`). `Http\JsonErrorHandler`
  (replaces Slim's default handler — every unhandled error is JSON; Slim `HttpException`s keep
  their status, everything else is a logged 500). `Http\Action` (thin base for module actions:
  `respond(Response, Result)` turns a use-case `Result` into a JSON value or a problem response).
- **Persistence.** `Infrastructure\Persistence\TransactionRunner::run(callable)` — one DB
  transaction; a nested call joins the outer one; rolls back and re-throws on any `Throwable`.
- **Wiring.** `src/Config/container.php` now binds `Psr\Clock\ClockInterface` → `SystemClock`,
  `Psr\Log\LoggerInterface` → `LoggerFactory::create()`, `ResponseFactoryInterface` →
  `Slim\Psr7\Factory\ResponseFactory`. `src/Bootstrap/AppFactory` adds `CorrelationIdMiddleware`
  and `setDefaultErrorHandler(JsonErrorHandler::class)`.
- **Dependencies.** Added `monolog/monolog:^3`, `psr/clock:^1`, `brick/money:^0.10`, and pinned
  `brick/math:~0.12.0` (see *Problems Encountered*).

## Files Created

- `/Users/mahsa/PhpstormProjects/Gomrok/src/Shared/Domain/Money.php`
- `/Users/mahsa/PhpstormProjects/Gomrok/src/Shared/Domain/Currency.php`
- `/Users/mahsa/PhpstormProjects/Gomrok/src/Shared/Domain/CountryCode.php`
- `/Users/mahsa/PhpstormProjects/Gomrok/src/Shared/Domain/Result.php`
- `/Users/mahsa/PhpstormProjects/Gomrok/src/Shared/Domain/DomainError.php`
- `/Users/mahsa/PhpstormProjects/Gomrok/src/Shared/Domain/ErrorType.php`
- `/Users/mahsa/PhpstormProjects/Gomrok/src/Shared/Infrastructure/SystemClock.php`
- `/Users/mahsa/PhpstormProjects/Gomrok/src/Shared/Infrastructure/CorrelationId.php`
- `/Users/mahsa/PhpstormProjects/Gomrok/src/Shared/Infrastructure/Logging/LoggerFactory.php`
- `/Users/mahsa/PhpstormProjects/Gomrok/src/Shared/Infrastructure/Logging/CorrelationIdProcessor.php`
- `/Users/mahsa/PhpstormProjects/Gomrok/src/Shared/Infrastructure/Persistence/TransactionRunner.php`
- `/Users/mahsa/PhpstormProjects/Gomrok/src/Shared/Http/CorrelationIdMiddleware.php`
- `/Users/mahsa/PhpstormProjects/Gomrok/src/Shared/Http/JsonResponder.php`
- `/Users/mahsa/PhpstormProjects/Gomrok/src/Shared/Http/JsonErrorHandler.php`
- `/Users/mahsa/PhpstormProjects/Gomrok/src/Shared/Http/Action.php`
- `/Users/mahsa/PhpstormProjects/Gomrok/tests/Support/FrozenClock.php`
- `tests/Unit/Shared/Domain/{MoneyTest,CurrencyTest,CountryCodeTest,ResultTest,DomainErrorTest}.php`
- `tests/Unit/Shared/Infrastructure/{SystemClockTest,LoggingTest}.php`
- `tests/Unit/Shared/Http/{JsonResponderTest,CorrelationIdMiddlewareTest,JsonErrorHandlerTest}.php`
- `tests/Integration/TransactionRunnerTest.php`

## Files Modified

- `/Users/mahsa/PhpstormProjects/Gomrok/composer.json` + `composer.lock` — added `monolog/monolog`,
  `psr/clock`, `brick/money`; pinned `brick/math:~0.12.0`.
- `/Users/mahsa/PhpstormProjects/Gomrok/src/Config/container.php` — bindings for
  `ClockInterface`, `LoggerInterface`, `ResponseFactoryInterface`.
- `/Users/mahsa/PhpstormProjects/Gomrok/src/Bootstrap/AppFactory.php` — added
  `CorrelationIdMiddleware` and the custom JSON error handler.
- `.claude/docs/Architecture.md` §4 (Shared classes), `.claude/docs/Phases.md` (Phase 3 row → ☑),
  `.claude/PhaseResults/PhaseDecisions.md` (Phase 3 Q1–Q5), `.claude/Changelog.md`, `.claude/FileIndex.md`,
  `.claude/knowledge/Knowledge.md`.

## Implementation Details

Classes/enums as listed under *Work Completed*. Key type notes:

- Everything is `final`; the value objects and single-responsibility infra classes are
  `final readonly`.
- `Money` currency-mismatch on `plus`/`minus` lets `brick`'s `MoneyMismatchException` propagate —
  that is a programmer error (500 path), consistent with the Q3 error model.
- `Result` is **non-generic** (see *Technical Decisions*).
- `JsonErrorHandler` signature matches Slim's default-handler contract
  `(ServerRequestInterface, Throwable, bool, bool, bool): ResponseInterface`.
- `TransactionRunner` swallows a `PDOException` from `rollBack()` (no active transaction) so the
  original error is what propagates.

## Database Changes

No database changes.

## API Changes

- `GET /health` unchanged in body, but now returns an **`X-Correlation-Id`** response header
  (echoed from a valid inbound header or freshly generated).
- **All error responses are now JSON** (previously Slim could emit HTML). Unknown route →
  `404 application/json` `{"type":"about:blank","title":"404 Not Found","status":404}`.
  Unhandled exceptions → `500` JSON (`"Internal server error"` unless `APP_DEBUG`).

## Tests and Validation

Created 12 test files (11 unit + 1 integration); none modified. Coverage: `Money` (all 14
operations incl. allocation-sums-back, banker's rounding, cross-currency throw, format,
convertTo), `Currency`, `CountryCode` (+ invalid-code data provider), `Result`, `DomainError`
(status mapping), `SystemClock` + `FrozenClock`, `CorrelationIdProcessor` (+ `CorrelationId`),
`JsonResponder` (json + problem), `CorrelationIdMiddleware` (generate / reuse / reject-malformed),
`JsonErrorHandler` (500 / debug / Slim HttpException), `TransactionRunner`
(commit / rollback / nested — integration, self-skips without MySQL).

Commands run, real output:

```
$ composer stan
  38/38 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100%
  [OK] No errors

$ composer cs           (after cs:fix)
  Found 0 of 39 files that can be fixed

$ composer ci           (cs + stan + unit)
  ..........................................                        42 / 42 (100%)
  OK (42 tests, 113 assertions)

$ composer test:all     (unit + integration)
  ..........................................SSSS                    46 / 46 (100%)
  OK, but some tests were skipped!
  Tests: 46, Assertions: 113, Skipped: 4       # 4 integration tests — no MySQL

$ php -S 127.0.0.1:8125 -t src/Public   +   curl -D - /health
  HTTP/1.1 200 OK
  Content-Type: application/json
  X-Correlation-Id: 3f1d33c716b920ea
$ curl -D - -H 'X-Correlation-Id: my-trace-123' /health   ->   X-Correlation-Id: my-trace-123
$ curl -w '%{http_code} %{content_type}' /nope
  {"type":"about:blank","title":"404 Not Found","status":404}
  404 application/json
```

**Not run:** `TransactionRunnerTest` against real MySQL — Docker daemon not running in this
environment. Written; self-skips. Run with `docker compose up -d mysql && composer test:integration`.

## Technical Decisions

1. **`Result` made non-generic.** A `@template T` `Result` with `err(): self<never>` and a
   covariant `map()` fought PHPStan `max` + strict-rules (`generics.notGeneric`, `never`
   variance, `varTag.nativeType`). Given the user's stated preference for straightforward code
   over clever abstractions, `Result` carries `mixed` and each use case documents its own
   success type in its own return phpdoc. `map()` was dropped (callers use `isOk()` + `value()`).
2. **`ratioOf()` returns `string`**, not a `BigDecimal`, to keep brick's math types out of the
   domain (consistent with "the domain depends on our `Money`, never on `brick`").
3. **Custom `JsonErrorHandler`** replaces Slim's default so the API never emits HTML — the
   *exception* half of the hybrid error model; `Result`/`DomainError` is the other half, handled
   in `Action::respond()`.
4. **`HealthAction` left as-is** (Phase 2). It doesn't extend the new `Action` base — it's a
   trivial no-I/O probe. Module actions from Phase 6 use the base.
5. **`brick/math` pinned to `~0.12.0`.** `brick/money 0.10.3` calls `BigDecimal::dividedBy()`
   without a scale internally; `brick/math 0.14` deprecated that. Pinning to 0.12 removes the
   deprecation until `brick/money` updates. `brick/money`'s own constraint allows
   `~0.12|~0.13|~0.14`.

## Problems Encountered

1. PHPStan `max` rejected the generic `Result` (4 errors around `@template` / `never` / variance).
2. A `run()` helper method in two test classes collided with `TestCase::run()` (final) and a
   `count()` helper collided with `TestCase::count()`.
3. `brick/math 0.14` emitted a deprecation via `brick/money`'s internal `allocate()` — surfaced
   by `MoneyTest::allocatesWithoutLosingCents`.
4. Several phpstan-phpunit "assertion always true" findings where an earlier `assertSame`
   narrowed a value that a later assertion then re-checked.
5. Docker daemon not running → integration test unverifiable against MySQL.

## Resolutions

1. Made `Result` non-generic (decision 1).
2. Renamed helpers to `dispatch()` / `probeCount()`; rewrote the middleware test to capture via
   a typed anonymous-class property instead of a by-reference variable.
3. Pinned `brick/math:~0.12.0` (decision 5).
4. Restructured the affected tests to assert once against a non-folded value.
5. Documented as not-verified with the exact command; unit + static + live HTTP checks all pass.

## Deferred Work

- **Run `TransactionRunnerTest`** against real MySQL — needs Docker Desktop.
- **`src/Shared/Application/`** (Command/Query bus + DomainEvent dispatcher contracts +
  Pagination) — created when a module first needs them; the domain-event dispatcher itself is
  needed by the first module that raises/handles an event.
- **`countries` / `currencies` reference tables** validate `CountryCode` / `Currency` membership
  — Phase 4/5. `CountryCode` currently only format-checks.
- **`brick/math` unpin** once `brick/money` releases a version that passes a scale to
  `dividedBy()`.
- A logger `TestHandler`-based assertion of the full JSON line shape — the processor is unit
  tested; end-to-end JSON-to-stdout is exercised implicitly.

## Final Result

`src/Shared/` now contains 15 classes: the money/currency/country value objects, the
`Result`/`DomainError`/`ErrorType` outcome model, a PSR-20 `SystemClock`, the Monolog-backed
JSON logger with correlation-id processor, the request-scoped `CorrelationId` + PSR-15
middleware, the `TransactionRunner`, and the HTTP `Action` base + `JsonResponder` +
`JsonErrorHandler`. Wired into the container and `AppFactory`. `composer ci` is green
(42 unit tests, 113 assertions), PHPStan `max` clean, php-cs-fixer clean. The app boots; every
response — success or error — is JSON and carries an `X-Correlation-Id`. No business modules, no
schema. Ready for **Phase 4 — Database foundations**.
