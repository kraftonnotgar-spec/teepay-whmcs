# TeePay for WHMCS

Accept mobile money payments for WHMCS invoices with [TeePay](https://teepay.me): **M-Pesa, Airtel
Money, Mixx by Yas and HaloPesa** in Tanzania. Clients click **Pay now** on an invoice, pay on
TeePay's hosted checkout page, and the invoice is marked paid only after TeePay has verified and
ledger-posted the payment.

[![CI](https://github.com/kraftonnotgar-spec/teepay-whmcs/actions/workflows/ci.yml/badge.svg)](https://github.com/kraftonnotgar-spec/teepay-whmcs/actions/workflows/ci.yml)

- No Composer or extra PHP packages on your server: the module bundles a small API client.
- Requires WHMCS 8.x, PHP 7.4 or newer with the `curl`, `json` and `openssl` extensions, and an
  HTTPS System URL.
- Full guide: <https://docs.teepay.me/docs/plugins/whmcs>

## How it works

1. The invoice shows a **Pay now** button. Nothing is sent to TeePay until the client clicks it.
2. The module creates a [checkout session](https://docs.teepay.me/docs/payments/checkout-sessions)
   for the invoice balance, in the client's currency, and redirects the client to TeePay.
3. The client approves the payment on their phone.
4. TeePay sends a signed `payment.completed` webhook, and the client returns to WHMCS.
5. In both cases the module reads the checkout session from the TeePay API with your secret key and
   records the payment only if every check passes (see [Payment confirmation](#payment-confirmation)).

## Install

1. Download the latest release (or clone this repository).
2. Copy the `modules` folder into your WHMCS root, merging with the existing folder. You should end
   up with:

   ```text
   modules/gateways/teepay.php
   modules/gateways/teepay/lib/...
   modules/gateways/callback/teepay.php
   ```

3. In WHMCS go to **Configuration (or Setup) → System Settings → Payment Gateways → All Payment
   Gateways** and activate **TeePay (Mobile Money)**.

## Configure

### In the TeePay dashboard

1. **Developers → API keys**: create a key with the `collection:create` and `collection:read`
   scopes. Live keys start with `tp_live_`, test keys with `tp_test_`.
2. **Developers → Webhooks**: create the webhook signing secret (`whsec_…`).
3. **Payment profiles**: on your default profile (or a profile dedicated to WHMCS) set:

   | Field | Value |
   |---|---|
   | Webhook URL | `https://your-whmcs.example/modules/gateways/callback/teepay.php` |
   | Return URL | `https://your-whmcs.example/modules/gateways/callback/teepay.php?action=return` |

   Replace `https://your-whmcs.example/` with your WHMCS System URL. Both URLs are also shown on the
   gateway settings page. TeePay only accepts these URLs from the dashboard, never per request, so a
   leaked API key cannot redirect your clients or your webhooks.

### In WHMCS

| Setting | Notes |
|---|---|
| Live API key | `tp_live_…`. Stored as a password field. |
| Live webhook signing secret | `whsec_…` for live. |
| Test mode | Uses the test key and secret instead. |
| Test API key / Test webhook signing secret | `tp_test_…` / sandbox `whsec_…`. |
| Payment profile ID | Optional UUID. Leave empty to use your default payment profile. |

Invoices must be in a currency your TeePay account supports (currently **TZS**). Amounts are sent in
minor units: an invoice balance of TZS 5,000.00 becomes `"500000"`.

## Testing

Test (`tp_test_`) keys verify your keys, scopes and setup, but **mobile money and checkout sessions run
in the live environment only**. To test end to end, use live mode with a small invoice (for example
TZS 1,000) paid from your own phone, then check:

- the invoice is marked paid with the TeePay payment reference as the transaction ID;
- **Billing → Gateway Log** shows a `Success` entry for `teepay`;
- **Developers → Webhooks** in TeePay shows the delivery with status `200`.

## Going live

1. Make sure your TeePay account is approved for collections and `checkout_sessions` is enabled
   (`GET /v1/capabilities`).
2. Enter the live API key and live webhook secret, turn **Test mode** off and save.
3. Confirm the payment profile URLs point at your production WHMCS.
4. Make one small real payment.

## Payment confirmation

An invoice is paid **only** when an authenticated `GET /v1/checkout-sessions/{id}`, made with your
secret key, returns a session where all of the following hold:

- `object` is `checkout_session`, `id` is the session that was created or named in the signed webhook;
- `environment` matches the active mode (`live`, or `sandbox` in test mode);
- `reference` is `whmcs-<site tag>-<invoice id>`, created by this WHMCS installation for this invoice
  (the site tag is derived from your System URL, so several installations can share one account);
- `status` is `paid` and `payment.reference` is set;
- `currency` equals the client's currency and `amount_minor` equals the invoice balance in minor
  units, exactly;
- the invoice is `Unpaid` (or `Payment Pending`) and the payment reference is not already recorded.

Then the module calls `checkCbInvoiceID`, `checkCbTransID` and `addInvoicePayment` with the exact
session amount and the TeePay payment reference as the transaction ID, inside a database lock so a
webhook and a client return arriving together cannot record it twice.

What never marks an invoice paid: the return URL or anything in its query string, an unsigned or
stale webhook, a webhook whose signature is valid but whose session read says anything other than
`paid`, and a session whose amount or currency differs from the invoice. Mismatches are written to
the gateway log as `Amount Mismatch` or `Rejected` for you to review.

## Troubleshooting

All entries are in **Billing → Gateway Log** (filter by `teepay`). Keys, secrets and phone numbers are
redacted before logging.

| Log status | Meaning and fix |
|---|---|
| `Account Restricted` | TeePay has restricted your account, so it is not accepting new payments. Clients see "Mobile money payments are unavailable right now". Sign in to the TeePay dashboard or contact TeePay support with the request ID from the log. |
| `Configuration Error` | No valid API key or webhook secret for the active mode. |
| `Invalid Signature` | The webhook secret does not match the environment, the secret was rotated, or the server clock is more than five minutes off. |
| `Error` | An API error; the log shows the `error_code` and `request_id`. `invalid_credential` means a wrong key or the wrong mode; `scope_denied` means the key lacks `collection:create`/`collection:read`; `unsupported_currency` means the invoice currency is not enabled. |
| `Amount Mismatch` | The paid amount or currency differs from the invoice balance (for example the invoice was edited after the client started paying). Nothing was recorded; reconcile manually. |
| `Rejected` | The session belongs to another invoice, environment or installation, or the invoice was already paid. |
| `Pending` | TeePay has not confirmed the payment yet. The next webhook or return will record it. |

If webhooks are not arriving, check the payment profile webhook URL, that your server is reachable
over HTTPS from the internet, and that no firewall or bot protection blocks `POST` requests to
`modules/gateways/callback/teepay.php`.

## Refunds

The public TeePay API does not offer refunds yet (`features.refunds` is always `false`), so this module
does not implement WHMCS refunds. Issue refunds from the TeePay dashboard or by your usual process,
then record them in WHMCS manually.

## Security

- API keys and webhook secrets are stored by WHMCS as password fields and are never shown to clients
  or written to logs.
- Webhooks are verified with HMAC-SHA256 over the raw body and timestamp, compared in constant time,
  and rejected when older than five minutes.
- Checkout sessions are created only from a server-generated, per-invoice token, with the amount taken
  from the invoice balance on the server.
- All API calls use TLS with certificate verification.

Report security issues privately to info@teepay.me rather than opening a public issue.

## Development

```bash
composer install
vendor/bin/phpunit
find modules tests -name '*.php' -print0 | xargs -0 -n1 php -l
```

The payment logic lives in `modules/gateways/teepay/lib` and is tested with WHMCS replaced by an
in-memory fake (`tests/Fakes`).

## License

MIT. See [LICENSE](LICENSE).
