# Acceptance Coverage — Tawfeer Online

Maps each required end-to-end workflow to the automated test(s) that exercise it.
The full suite (`php artisan test`) runs on an in-memory SQLite DB seeded by
`DatabaseSeeder`, uses `sync` queue (queued jobs run inline) and `array` cache, and
**never sends real external messages** (`FakeMessagingProvider`) or makes real AI/HTTP
calls (`NullAiContentProvider`).

| # | Workflow | Covering test(s) |
|---|----------|------------------|
| 1 | Customer registration | `tests/Feature/Auth/RegistrationTest.php`, `tests/Feature/Account/AuthIdentityTest.php` |
| 2 | Google login | `tests/Feature/Account/AuthIdentityTest.php` (Socialite fake provider — ADR-036) |
| 3 | Facebook login | `tests/Feature/Account/AuthIdentityTest.php` (provider-parametrised) |
| 4 | Guest cart | `tests/Feature/Store/CartApiTest.php` (X-Cart-Token identity) |
| 5 | Authenticated cart | `tests/Feature/Store/CartApiTest.php`, `tests/Feature/Account/AuthIdentityTest.php` (guest→auth merge) |
| 6 | Checkout | `tests/Feature/Store/CheckoutApiTest.php` |
| 7 | Assisted sales order | `tests/Feature/Sales/AssistedOrderTest.php` |
| 8 | Affiliate order | `tests/Feature/Sales/OrderApiTest.php`, `tests/Feature/Commissions/CommissionLedgerTest.php` |
| 9 | Inventory reservation | `tests/Feature/Inventory/StockReservationApiTest.php`, `tests/Feature/Sales/OrderLifecycleApiTest.php` |
| 10 | Shipment creation | `tests/Feature/Shipping/ShipmentLifecycleApiTest.php` |
| 11 | Delivery-status synchronization | `tests/Feature/Shipping/DeliveryStatusEngineTest.php`, `DeliveryOperationsTest.php` |
| 12 | Pending delivery exception | `tests/Feature/Shipping/DeliveryOperationsTest.php` (categorised pending reasons) |
| 13 | Delivery close | `tests/Feature/Shipping/DeliveryStatusEngineTest.php` (CLOSE = financial completion) |
| 14 | Commission eligibility | `tests/Feature/Commissions/CommissionLedgerTest.php`, `Settlements/SettlementTest.php` |
| 15 | Finance approval & payout | `tests/Feature/Settlements/SettlementTest.php` (approve/reconcile/post/close) |
| 16 | Return | `tests/Feature/Returns/ReturnRmaTest.php` |
| 17 | Exchange | `tests/Feature/Returns/ReturnRmaTest.php` (exchange/replacement paths) |
| 18 | Settlement & reconciliation | `tests/Feature/Settlements/SettlementTest.php` |
| 19 | Operational reports | `tests/Feature/Reporting/*` (executive/sales/delivery/finance + KPIs) |
| 20 | Product AI-description suggestion | `tests/Feature/Ai/AiContentTest.php` (suggestion-only, logs, permission, rate-limit, fallback) |
| 21 | Recommendations | `tests/Feature/Recommendations/RecommendationTest.php` (rules, availability, manual pins/blocks, upsell, tracking endpoint) |
| 22 | Marketing automation | `tests/Feature/Marketing/CampaignTest.php` (consent/suppression/quiet-hours/frequency/idempotency, event trigger, **queued** send, transition guard) |

## Phase 6 / production-readiness additions verified here
- **Recommendation event tracking end-to-end:** storefront `POST /recommendations/track`
  records `recommendation_events` (impression/click/conversion) →
  `ReportingService::kpis()` aggregates impressions/clicks/CTR/conversions
  (`RecommendationTest::test_tracking_endpoint_records_event`,
  `KpiDashboardTest::test_kpis_aggregate_growth_signals`).
- **Queued external messaging:** `CampaignTest::test_external_send_is_queued_not_synchronous`
  asserts `SendCampaignMessageJob` is pushed and the message is persisted `queued`
  (no synchronous external call), while the sync-queue test env still exercises the
  full send path.
- **Authorization:** every admin surface is gated by `can:` middleware/policies; KPI,
  AI, marketing, and recommendation routes assert 403 without the relevant permission
  (`KpiDashboardTest`, `AiContentTest::test_requires_permission`).

## Manual smoke checklist (staging, before go-live)
Run against a seeded staging deploy with real browser (Arabic RTL + mobile):
1. Register → verify → login; Google/Facebook login round-trip.
2. Guest add-to-cart → login → cart merges.
3. Checkout places an order; stock reserved; admin sees the order.
4. Admin: assisted order + affiliate order; commissions show as **eligible** only.
5. Create shipment; move through delivery statuses to CLOSE; open + resolve a pending
   exception.
6. Create a return; approve → receive → complete; stock returns.
7. Finance: create settlement, reconcile, post; approve + payout a commission.
8. Reports + KPI dashboard render with data across day/week/month.
9. Product edit → AI panel suggests AR + EN content; nothing auto-publishes; **Apply**
   copies into the field; `ai_generation_logs` row written (no secrets).
10. Product/cart/checkout show recommendation sections; scrolling logs impressions,
    clicking logs clicks (`recommendation_events`); KPI CTR updates.
11. Create a marketing campaign → approve → activate; test-send (fake/null provider in
    staging — **no real messages**); execution log shows status.
