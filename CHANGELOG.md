# Changelog

All notable changes to this module are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [1.0.0] - 2026-11-03

### Added

- WHMCS payment gateway module `teepay` for mobile money in Tanzania (M-Pesa, Airtel Money,
  Mixx by Yas, HaloPesa) through TeePay hosted checkout sessions.
- "Pay now" button that creates the checkout session only when the client clicks it, with a
  per-invoice HMAC token and a deterministic idempotency key.
- Signed webhook endpoint (`X-TeePay-Signature`, HMAC-SHA256, five-minute tolerance).
- Payment confirmation by authenticated `GET /v1/checkout-sessions/{id}` only, with exact
  amount, currency, reference and environment checks before `addInvoicePayment`.
- Return handler that uses the client's WHMCS session, never query-string values.
- Friendly gateway-log notice when TeePay reports `account_restricted`; clients see a generic
  "payments unavailable" message.
- Redacted gateway logging (API keys, webhook secrets and phone numbers are masked).
- Bundled dependency-free API client, PHPUnit test suite and GitHub Actions CI (PHP 7.4 to 8.3).
