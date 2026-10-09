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
`OrderLock`, and `PaymentReturn`. Each suite resets its mocks, credentials, counters,
and fixtures. Shared setup lives in `tests/bootstrap.php` and `tests/Support/`.

Local verification commands:

```console
composer install
composer check
composer test
pnpm install --frozen-lockfile
pnpm run build
pnpm run test:attribution
```

The browser test requires the Docker store and Chrome. Set `CHROME_BIN` if needed,
and `WORDPRESS_URL` if the local HTTP entry point is not `http://localhost`. It
creates/removes a paid test order, checks the real plugin return header, then
exercises the installed Sourcebuster script across simulated payment navigation.
It covers expired sessions, Direct, campaigns, referrals, and absent tracking cookies.

PHP checks use a small correctness ruleset (`phpcs.xml`) and PHPStan level 1.
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
- `Checkout/`: return, retry, and receipt controllers.
- `Api/IcepayClient.php`: ICEPAY HTTP transport, injectable into payment handlers.

The refactor keeps existing order metadata keys and payment policies compatible.
Frontend dependencies and cache versions come from `build/index.asset.php`.

For release verification, also exercise classic and Blocks checkout in a browser:
successful payment, pending confirmation, cancellation followed by retry, and
switching payment methods. Confirm the return page, emails, and order state.

### Recovering an unknown payment-creation outcome

Each eligible checkout submission creates a fresh payment for the selected method.
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
