# Development

## Prerequisites

- Docker and Docker Compose
- Obtain a token by following the instructions at: https://expose.beyondco.de/docs/introduction to get a token

## Setup

1. Clone the repository:
    ```console
    git clone https://github.com/ICEPAY/ICEPAY-for-WooCommerce
    ```
2. Create a copy of the example env file and modify the necessary settings in the .env file:
    ```console
    cp .env.example .env
    ```
    - **EXPOSE_HOST** should be set to the expose server you want to connect to
    - **APP_SUBDOMAIN** should be modified by replacing `-xx` in `woocommerce-dev-xx` with a number for
      example `woocommerce-dev-05`
    - **EXPOSE_TOKEN** must be entered
3. Start the Docker containers
    ```console
    docker-compose up -d
    ```
4. Install and activate WordPress with WooCommerce and the ICEPAY plugin
    ```console
    make install
    ```

## Payment reliability regression checks

Run against the disposable Docker development store:

```console
composer test
```

The script intercepts ICEPAY HTTP requests and outgoing email, creates temporary
orders/products, and removes them afterward. It verifies payment completion,
stock and hook idempotency, late notifications, status-only completion, safe retries, creation
timeouts, explicit payment refreshes, and database-lock contention. Merchant credentials are
temporarily replaced and restored; do not run it against a production store.

Run a single independent suite:

```console
docker compose exec -T app wp eval-file wp-content/plugins/icepay-for-woocommerce/tests/run.php PaymentCreation
```

Available suites: `PaymentStatus`, `ProviderConflict`, `PaymentCreation`,
`OrderLock`, `PaymentReturn`, `Refund`, `Restrictions`, and `ApiValidation`. Each suite resets its mocks, credentials, counters,
and fixtures. Shared setup lives in `tests/bootstrap.php` and `tests/Support/`.

Local verification commands:

```console
composer install
composer check
composer test
pnpm install --frozen-lockfile
pnpm run lint:js
pnpm run build
pnpm run test:attribution
pnpm run test:restrictions
```

The browser test requires the Docker store and Chrome. Set `CHROME_BIN` if needed,
and `WORDPRESS_URL` if the local HTTP entry point is not `http://localhost`. It
creates/removes a paid test order, checks the real plugin return header, then
exercises the installed Sourcebuster script across simulated payment navigation.
It covers expired sessions, Direct, campaigns, referrals, and absent tracking cookies.

PHP checks use PHPStan level 6 targeting PHP 8.2, with no baseline or blanket ignores.
API success/error tuples, payment responses, attempts, and refund records have explicit
array contracts. `Api/ResponseValidator.php` validates external JSON before it can be
used as a typed payment/refund; malformed POST successes retain an uncertain outcome.
Documented null paymentMethod/meta fields are omitted, null refunds become an empty
list, and null links.direct falls back to checkout. Completed payments still require
a payment method. Validation errors report field names without response values.
`ApiValidation` covers malformed transport responses. A small analysis-only stub in
`tests/Support/woocommerce.stub` corrects upstream cart/customer nullability and query
result types; it is not loaded at runtime.

PHPCS enables output escaping, input validation/sanitisation, nonce verification,
prepared SQL, and translation checks for production code. Provider callbacks have
scoped explanations for their HMAC/order-key authentication. CLI fixtures retain
syntax checks but are excluded from browser input/output security rules.
JavaScript lint covers Blocks, build configuration, and browser runners; CI fails
on warnings. Formatting is kept separate from this correctness gate. WooCommerce's
externally supplied Blocks registry import is the only resolver exception.
No PHPUnit dependency is needed for the WP-CLI integration runner. CI executes
the checks, independent suites, browser test, build, and archive validation on a
disposable HPOS-enabled store. `pnpm run check:archive` validates release contents.

## Code structure

- `Integration.php`: hook registration and plugin integration.
- `Gateway.php`: WooCommerce gateway settings, checkout notices, and refunds.
- `Payment/PaymentService.php`: locked payment creation and previous-attempt checks.
- `Payment/PaymentRequestBuilder.php`: ICEPAY request payload and return URL.
- `Payment/PaymentStatusHandler.php`: validated, idempotent status transitions.
- `Payment/PaymentAttempts.php`: attempt metadata and legacy payment-key lookup.
- `Payment/OrderLock.php`: database connection-scoped concurrency protection.
- `Payment/RefundService.php`: locked refund creation, durable operation identity, and recovery.
- `Checkout/`: return, retry, and receipt controllers.
- `Api/IcepayClient.php`: ICEPAY HTTP transport, injectable into payment handlers.

The refactor keeps existing order metadata keys and payment policies compatible.
Frontend dependencies and cache versions come from `build/index.asset.php`.

### Refund reliability and recovery

Create API refunds through WooCommerce's order refund workflow (`wc_create_refund`
with `refund_payment: true`). Each operation receives a UUID reference before save;
the gateway requires one unambiguous WooCommerce refund rather than guessing from
amount/reason alone. Multiple partial refunds with the same amount/reason are supported.
Direct `process_refund` calls without a prepared WooCommerce refund are rejected.

The order lock serialises ICEPAY refund requests. Before submission the plugin checks
the original payment and both local and provider refund balances, including Portal
refunds. `_icepay_refunds` on the parent order stores the operation before the POST,
so WooCommerce's deletion of a failed local refund cannot erase an unknown outcome.
ICEPAY references are not server-side idempotency keys; the plugin never blindly
resubmits an uncertain attempt. Definitive rejections allow a fresh operation.

Verified payment updates match the reference, payment, amount, currency, and unique
refund key. They store pending/completed status on the local refund. If WooCommerce
deleted the local record after a timeout, reconciliation recreates its accounting and
requested line items/restocking without another API refund. Repeated updates do not
repeat refund creation, emails, or restocking. Portal-only refunds are not automatically
imported as WooCommerce refunds.

Use the order action **Reconcile ICEPAY refunds** to retrieve current payment state
when a webhook has not arrived. Absence from a response alone does not prove that
an uncertain refund failed. If it remains unmatched, check the ICEPAY Portal/support.
Only after confirming no refund was created, mark that operation's `state` as
`rejected` in `_icepay_refunds` using WooCommerce metadata APIs. If a refund exists,
reconcile it instead. Deleting an accepted local refund also blocks subsequent API
refunds until its accounting is restored. No scheduled refund polling is performed.

### Payment method restrictions

Each payment method has **Allowed billing countries**, **Minimum order amount**, and
**Maximum order amount** settings. Empty values impose no restriction; amount boundaries
are inclusive and use the final order total including tax/shipping in the order currency.
Settings reject invalid countries, negative/non-numeric amounts, excess decimal places,
and inverted ranges. A restricted country list requires a known matching billing country.

Classic checkout and order-pay use gateway availability; Blocks uses the same configured
limits with its live billing address and cart total. Payment creation rechecks the actual
order server-side. Historical payment confirmation/refunds are not blocked by later
changes to availability settings.

`pnpm run test:restrictions` runs the built Blocks registration in Chrome with
checkout context fixtures, including country changes and currency minor units.
The PHP suite exercises classic/order-pay availability and direct-submit enforcement.

For release verification, also exercise classic and Blocks checkout in a browser:
successful payment, pending confirmation, cancellation followed by retry, and
switching payment methods. Confirm the return page, emails, and order state.

### Recovering an unknown payment-creation outcome

Each eligible checkout submission creates a fresh payment for the selected method.
New payments include an `icepay-attempt` creation token in the return URL, stored
with that payment attempt. After validating the order key, the return controller
matches the token against the order and refreshes that specific attempt. Unknown,
malformed, and cross-order tokens cannot trigger a payment lookup. Legacy return
URLs without a token continue to use the newest attempt.

Checkout checks continue past pending or unverifiable attempts to find an older
verified success. Confirmed payment takes priority; otherwise pending payments
block creation, and verification errors block creation if no pending result exists.
Previously started payments are neither reused nor cancelled, and their keys remain
in the order's attempt history for late notifications. A previous pending payment
still blocks another creation while its result is being confirmed. Late success
from an older attempt can complete the order; additional successes are flagged as
possible duplicate charges.

ICEPAY's public API documentation does not specify an idempotency header or a
payment lookup by merchant reference. A timeout or interrupted creation therefore
blocks another creation for that order. A subsequent verified webhook can recover
the payment using the persisted creation token and the authenticated API response.

If no webhook arrives, review the order in the ICEPAY Portal before retrying. If
the Portal confirms no payment was created, clear `_icepay_creation_uncertain`
using WooCommerce order metadata APIs (including for HPOS). If a payment exists,
reconcile it rather than clearing the marker and creating a second payment.

Webhooks are primary. Customer returns and checkout retries can explicitly retrieve
payment state from ICEPAY. No background reconciliation checks or fallback retries
are scheduled.

Payment completion uses only ICEPAY's `status`: `completed` confirms payment for
every payment method. `financialStatus` is ignored. Pending payments stay on hold.

### Multiple payment providers

ICEPAY uses a **complete and flag** policy for a verified successful payment on
an unpaid order that has switched to another provider. It completes the order
through WooCommerce, changes the gateway to the actual ICEPAY method, and adds a
merchant-review note. The previous gateway, title, and transaction ID are retained
in that attempt's `provider_conflict` metadata for reconciliation/refund review.
Repeated notifications do not repeat the conflict note or payment completion.

If the order is already paid, ICEPAY preserves its gateway, transaction ID, and
order status, and flags the additional payment once as a possible duplicate.
Pending, cancellation, and expiry updates only update ICEPAY attempt history
when another provider owns the order. ICEPAY receipt messages and old return/retry
links leave payment state and messages on orders assigned to other gateways alone.
Historical ICEPAY returns still redirect to a clean receipt URL to suppress the
payment provider's referrer. Webhooks continue to reconcile
historical ICEPAY attempts.

The regression script covers generic external payment gateway IDs, simulating
external completion through WooCommerce. It does not run external providers'
actual callbacks. For release verification, install the relevant
provider and test switching in both directions, notification ordering, refunds,
and classic/Blocks checkout styling. ICEPAY's order lock only serialises ICEPAY
requests; another plugin's simultaneous writes do not participate in that lock.
Check both provider dashboards for duplicate charges after a conflict note.

### Order attribution on payment returns

Validated ICEPAY returns send `Referrer-Policy: no-referrer` on the redirect to the
clean order-received URL. This prevents WooCommerce's browser attribution tracker
from treating ICEPAY checkout as a new referral when its tracking session expires.
The policy also applies to historical ICEPAY returns after switching providers,
without refreshing that order's payment state.

Existing attribution cookies and saved order metadata are preserved. Direct
visitors remain direct; existing campaign and genuine referral sources are retained.
If attribution cookies are gone, the return is a direct visit rather than an ICEPAY
referral. This does not repair cookies or orders already attributed to ICEPAY.

Browser verification: enter the store directly or with campaign/referral data,
pay through ICEPAY, expire only the `sbjs_session` cookie, then return. Confirm
`document.referrer` is empty on the clean receipt page and Sourcebuster retains the
original source. Repeat for classic and Blocks checkout and a subsequent order.
