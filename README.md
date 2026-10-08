# PosTooChat Chat App Wallet

## Purpose

`chat-app-wallet` is PosTooChat's payment and accounting control plane. It
collects money, records the commercial transaction, manages credit purchases
and adjustments, and gives a customer or Workspace clear wallet controls.

It does not implement provider mechanics. It authorizes billable sends locally
for chat-app plugins, while Supabase SB Coms verifies and clips the one-use
authorization before routing the logical communication. On a Wallet refusal,
Wallet may submit a non-billable factual notification through its restricted
registered SB Coms application identity; the notification is defined by
Message Center in the `postoochat_wallet` group, never as an SB Coms seed.

## Initial payment providers

The first supported provider routes are:

| Market | Provider | Role |
| --- | --- | --- |
| Global | PayPal | Default international checkout route |
| South Africa | Yoco | South African checkout route |

The plugin selects a provider from the customer's country, currency, and an
explicit customer choice where more than one provider is available. Providers
are adapters behind one wallet checkout contract, so a country-specific
provider can be added later without changing Chatti, SB Coms, or existing
wallets.

The plugin SHALL verify each payment-provider webhook before granting credit.
It SHALL use the provider's payment/order ID as an idempotency key, so retries
cannot create credit twice.

## Runtime secret names

Wallet runtime configuration uses the `PTC_WALLET_` prefix and is supplied by
the server's secret-management configuration. The initial names are
`PTC_WALLET_AUTHORIZATION_SIGNING_SECRET`, `PTC_WALLET_PAYPAL_CLIENT_ID`,
`PTC_WALLET_PAYPAL_CLIENT_SECRET`, `PTC_WALLET_PAYPAL_WEBHOOK_ID`,
`PTC_WALLET_YOCO_SECRET_KEY`, and `PTC_WALLET_YOCO_WEBHOOK_SECRET`. Only the
signing secret is required for the current authorization service; provider
credentials are required only when their checkout adapters are enabled.

The support UI additionally needs `PTC_WALLET_OPERATIONS_URL` (the deployed
Supabase `wallet-operations` function URL) and the matching
`PTC_WALLET_OPERATIONS_TOKEN`. The token is an independent high-entropy secret:
place the same value in Supabase as `PT_WALLET_OPERATIONS_TOKEN` and in the
server's `wp-config.php` as `PTC_WALLET_OPERATIONS_TOKEN`. It is never entered
in the WordPress dashboard. The ignored `.chatappenv` file is a local reference
only; WordPress does not load it. The production constants must be defined in
`wp-config.php`.

For the PayPal Sandbox adapter, the public listener URL is:

```
https://api.postoochat.com/wp-json/chat-app-wallet/v1/paypal/webhook
```

Create the webhook in the PayPal developer dashboard with that URL, then place
the returned webhook ID in `PTC_WALLET_PAYPAL_WEBHOOK_ID` through the server's
runtime secret configuration. The listener verifies PayPal's signature using
the configured client credentials before storing an idempotent receipt. It
does not grant credits until a checkout order/capture has been bound to a
Wallet purchase.

## Credit model

The initial commercial model is prepaid credit burn-down:

- a customer buys credits before use;
- a credit is a configured unit of billable outbound business messaging;
- the initial illustrative rate is USD 0.005 per billable message;
- rates SHALL be configurable by channel, country, currency, customer plan,
  and effective date; they SHALL NOT be hard-coded in an app;
- inbound messages, internal callbacks, and Suite System replies (`Start`,
  `Menu`, `Home`, `Close`, and `Exit`) are not billable by default;
- failed provider dispatches do not consume a final credit.

## Owned and shared Wallets

Suite onboarding never creates, selects, links or funds a Wallet. When a
channel identity completes Suite verification, Wallet provisions a distinct,
zero-balance **owned Wallet profile** for that canonical channel identity.
This profile is not a credit grant and does not merge WhatsApp and Telegram.

Wallet may grant an identity access to a **shared Wallet** owned by an approved
customer account, household, team or other commercial entity. Only Wallet
administration creates shared Wallets or grants/revokes access, and it owns
their scope, app/channel eligibility, sponsorship priority and spending limits.
Chat apps never select a payer or administer either Wallet type.

Owned and shared Wallets may have separate channel balances:

```text
Owned or shared Wallet
├─ WhatsApp balance
│  └─ balance, rate card, low-balance and auto-recharge settings
├─ Telegram balance
│  └─ balance, rate card, low-balance and auto-recharge settings
└─ Wallet-owned payer policy
   └─ owned/shared eligibility, sponsorship and credit-limit decisions
```

This makes differing channel costs visible and allows a customer to fund one
channel without unintentionally funding another.

## Authority boundaries

| Owner | Responsibilities |
| --- | --- |
| Chat App Wallet (WordPress) | Customer accounts, checkout, PayPal/Yoco adapters, payment webhooks, invoices, tax/accounting records, credit purchases, grants, refunds, wallet configuration, statements and reconciliation. |
| Supabase SB Coms | Verify and consume one-use billing authorizations, prevent replay, retain a minimal authorization/communication/outcome receipt, and report delivery outcomes. It does not hold wallets, entities, prices, sponsorship rules, or accounting ledgers. |
| Chat application (for example Chatti) | Business workflow only. It supplies communication facts to Wallet and never processes card payments, selects a Wallet/payer, sets a price, reads a balance, or edits Wallet policy. |
| Message Center | Message definitions and presentation only. |

The Wallet plugin SHALL NOT edit SB Coms tables directly. Conversely, SB Coms
SHALL NOT initiate a payment, issue an invoice, or calculate a customer price.

## Usage lifecycle

The canonical credit ledger is append-only. A cached balance may be maintained
for speed, but every balance must be explainable from ledger entries.

1. A verified PayPal or Yoco payment creates a `purchase` ledger entry.
2. An authorised promotion or support correction creates a `grant`, `refund`,
   or `adjustment` entry with an actor and reason.
3. Before requesting SB Coms dispatch, the chat app calls the Wallet plugin's
   registered PHP service with only its app ID, channel, subject and recipient
   channel identities, authoritative business reference, billing meter and
   idempotency key. Wallet resolves the owned/shared payer and creates a
   `reserve` entry. It returns a short-lived, one-use signed authorization
   bound to the app, channel, meter and idempotency key.
4. SB Coms verifies and clips that authorization once before provider dispatch.
5. Provider acceptance converts the reservation to `consume`; a rejection,
   cancellation, or unrecoverable failure creates `release`.

Every entry SHALL include an immutable ID, wallet scope, channel, currency or
credit unit, amount, reason, linked payment or communication ID, actor/source,
and timestamp.

## Support and identity operations UI

**Tools → Chat App Wallet** is the operations view for channel identities. It
lists Suite identities, their selected app and activity state, their owned
Wallet profile, shared-Wallet access and available funding state. Search accepts
a mobile/address, channel, app, onboarding status, or visible activity date.

An owned Wallet profile uses the canonical identity value
`whatsapp:+27811234567` or `telegram:701258963` as its display/index key. A
shared Wallet is attached by a Wallet-owned access grant. Neither relationship
merges WhatsApp and Telegram people or alters the Suite identity model.

The **Revoke consent & access** action requires an operator reason and a
WordPress administrator session. It calls the protected Supabase Wallet
Operations boundary, which records the revocation, invalidates the identity's
Suite onboarding profile, confirmed mobile number, TOTP seed, active session
and remembered app, and requires fresh onboarding before a future entry. This
is intentionally the same personal-data reset as the Suite `WITHDRAW` keyword;
the additional retained record is the admin actor, timestamp and required
reason. It deliberately retains the wallet ledger and minimal revocation audit
record. For Telegram, the same protected operation also removes an outstanding
native number-confirmation keyboard, so a stale control cannot be mistaken for
current consent. Refunds must be separate immutable `refund` ledger entries,
never edits to an original charge.

## Chat-app communication contract

All chat apps on the same WordPress server SHALL call the registered PHP
service, not local HTTP, before a potentially billable SB Coms command:

```php
$decision = postoochat_wallet()->authorize_message( array(
    'app_id'             => 'booki',
    'channel'            => 'telegram',
    'subject_identity'   => 'telegram:701258963',
    'recipient_identity' => 'telegram:123456789',
    'business_reference' => 'booking:abc-123',
    'billing_meter'      => 'outbound_standard',
    'idempotency_key'    => 'booking:abc-123:reminder-01',
) );
```

The chat app MUST NOT pass or choose a wallet ID, payer, price, balance, credit
limit or shared-Wallet preference. Wallet returns either a signed one-use
authorization for the original SB Coms command or a deterministic refusal. The
authorization is passed unchanged as the command's billing authorization; SB
Coms verifies and consumes it before provider dispatch.

### Signed authorization ticket

For a billable Message Center definition, the chat app passes Wallet's response
unchanged in the SB Coms command as `billingAuthorization`. The ticket is not a
wallet-selection or pricing input; `payerReference` is an opaque reference and
`reservedAmount` is Wallet's already-resolved reservation.

```json
{
  "billingAuthorization": {
    "claims": {
      "authorizationId": "uuid",
      "appId": "booki",
      "channel": "telegram",
      "billingMeter": "outbound_standard",
      "idempotencyKey": "…",
      "businessReference": "…",
      "payerReference": "opaque-sha256-reference",
      "reservedAmount": "1.000000",
      "unit": "CRD",
      "expiresAt": "ISO-8601",
      "signature": "HMAC-SHA256"
    }
  }
}
```

SB Coms verifies the signature and every binding before atomically consuming
the authorization. A non-billable definition has no `billing_meter` and MUST
NOT include `billingAuthorization`.

## Insufficient credit contract

When Wallet cannot fund a billable send, it returns a deterministic refusal such
as `credit_balance_exhausted`. No authorization is issued, so SB Coms rejects
the original billable command and no provider send is attempted. Wallet may
then submit the applicable non-billable Message Center definition from the
`postoochat_wallet` group—normally `WALLET_CREDIT_REQUIRED`—using Wallet's
restricted registered SB Coms application identity. That definition contains
the factual funding/top-up presentation and approved action; it is not an SB
Coms seed message and the requesting chat app does not choose it.

## Registered PHP authorization service

All chat apps run on the same WordPress server and SHALL request billing
authorization through the Wallet plugin's registered PHP service, not local
HTTP. The eventual interface is conceptually:

```php
$authorization = postoochat_wallet()->authorize_message( $request );
```

The request identifies the calling app, channel, channel identities,
authoritative business reference, billing meter and outbound idempotency key.
The response is either a deterministic refusal or a signed, one-use
authorization. The signature is necessary because Supabase is outside WordPress
and must verify the ticket without accessing Wallet records.

## Future capabilities

- country-specific payment-provider adapters;
- subscriptions with included monthly credits and overages;
- bundle discounts and promotional credits;
- configurable credit expiry, if legally and commercially appropriate;
- low-balance notices and capped auto-recharge;
- invoices, tax treatment, refunds, chargebacks and accounting exports;
- multi-currency display while keeping each wallet's financial ledger
  separately reconcilable.

## Security requirements

- Provider credentials and webhook secrets stay in ignored deployment
  configuration, never the repository or WordPress database plaintext.
- Checkout return URLs are not proof of payment; only verified provider
  webhooks can grant credit.
- Administrative grants, refunds, rate-card changes, and provider changes
  require an auditable privileged action.
- Payment and wallet webhooks must be idempotent, signed, replay-protected,
  and recorded before side effects are applied.
