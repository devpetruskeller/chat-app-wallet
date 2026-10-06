# PosTooChat Chat App Wallet

## Purpose

`chat-app-wallet` is PosTooChat's payment and accounting control plane. It
collects money, records the commercial transaction, manages credit purchases
and adjustments, and gives a customer or Workspace clear wallet controls.

It does not send WhatsApp or Telegram messages. It authorizes billable sends
locally for chat-app plugins, while Supabase SB Coms verifies and clips the
one-use authorization before routing the logical communication.

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
in the WordPress dashboard.

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

## Wallet scopes

Wallets are scoped to the billable customer account or Workspace, not to a
single chat application. A Workspace may have separate channel wallets:

```text
Account / Workspace
├─ WhatsApp wallet
│  └─ balance, rate card, low-balance and auto-recharge settings
├─ Telegram wallet
│  └─ balance, rate card, low-balance and auto-recharge settings
└─ Optional shared fallback wallet
   └─ used only when its configured fallback rule permits it
```

This makes differing channel costs visible and allows a customer to fund one
channel without unintentionally funding another.

## Authority boundaries

| Owner | Responsibilities |
| --- | --- |
| Chat App Wallet (WordPress) | Customer accounts, checkout, PayPal/Yoco adapters, payment webhooks, invoices, tax/accounting records, credit purchases, grants, refunds, wallet configuration, statements and reconciliation. |
| Supabase SB Coms | Verify and consume one-use billing authorizations, prevent replay, retain a minimal authorization/communication/outcome receipt, and report delivery outcomes. It does not hold wallets, entities, prices, sponsorship rules, or accounting ledgers. |
| Chat application (for example Chatti) | Business workflow only. It never processes card payments or edits a wallet balance. |
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
   registered PHP service. The Wallet plugin creates a `reserve` entry and
   returns a short-lived, one-use signed authorization bound to the app,
   channel, and idempotency key.
4. SB Coms verifies and clips that authorization once before provider dispatch.
5. Provider acceptance converts the reservation to `consume`; a rejection,
   cancellation, or unrecoverable failure creates `release`.

Every entry SHALL include an immutable ID, wallet scope, channel, currency or
credit unit, amount, reason, linked payment or communication ID, actor/source,
and timestamp.

## Support and identity operations UI

**Tools → Chat App Wallet** is the operations view for channel identities. It
lists Suite identities, their selected app and activity state, their linked
wallet balance, and the number of identities without a wallet. Search accepts
a mobile/address, channel, app, onboarding status, or visible activity date.

To associate a wallet with a channel identity, the wallet entity display name
must use its canonical identity value: `whatsapp:+27811234567` or
`telegram:701258963`. This is a display/index key for support lookup; it does
not merge WhatsApp and Telegram people or alter the Suite identity model.

The **Revoke consent & access** action requires an operator reason and a
WordPress administrator session. It calls the protected Supabase Wallet
Operations boundary, which records the revocation, invalidates the identity's
Suite state and active app session, and requires fresh onboarding before a
future entry. It deliberately retains the wallet ledger and minimal revocation
audit record. Refunds must be separate immutable `refund` ledger entries, never
edits to an original charge.

## Insufficient credit contract

When a wallet cannot fund a billable send, the PHP service returns
`credit_balance_exhausted` to the requesting app. No authorization is issued,
so SB Coms never receives a dispatch command and no provider send is attempted.

## Registered PHP authorization service

All chat apps run on the same WordPress server and SHALL request billing
authorization through the Wallet plugin's registered PHP service, not local
HTTP. The eventual interface is conceptually:

```php
$authorization = postoochat_wallet()->authorize_message( $request );
```

The request identifies the calling app, its own entity/workspace reference,
channel, billing meter, and outbound idempotency key. The response is either a
deterministic refusal or a signed, one-use authorization. The signature is
necessary because Supabase is outside WordPress and must verify the ticket
without accessing Wallet records.

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
