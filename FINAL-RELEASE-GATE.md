# Final release acceptance gate (Eastern Livs AI Connector)

**Status: NOT RELEASE READY.** This checklist is intentionally strict. Do not publish a release or install this draft on production until all mandatory gates pass.

## Mandatory functionality
- [x] Authenticated product list, name/barcode lookup and paginated catalog audit implemented
- [x] Price change requests with explicit administrator approval implemented
- [x] Image requests with approval, SKU stale-change guard and replacement opt-in implemented
- [x] Read-only Yabi row validation and duplicate SKU detection implemented
- [x] Google Programmable Search API image candidate retrieval code implemented (requires credentials)
- [x] Wikimedia Commons image candidate retrieval code implemented
- [ ] Import actual Yabi CSV, including EANs (JSON) and Price List (JSON), with preview, validation, rollback and no partial destructive replacement
- [ ] Product creation, category and stock requests with explicit administrator approval
- [ ] End-to-end Google image search tested with a valid authorized key and configured search engine
- [ ] Bing image provider tested, if required
- [ ] Image relevance/package-weight verification and licensing review workflow tested
- [ ] Urdu/English voice command handling through an explicitly connected speech/AI provider
- [ ] Secure external AI integration or MCP interface implemented and authenticated
- [ ] Test all 99 pending product image cases without altering production
- [ ] Tests for duplicate SKU, missing/zero price, malicious URLs, oversized files and permission denials
- [ ] WordPress + WooCommerce integration tests in a disposable staging environment
- [ ] In-place update from installed 0.4.0 with backup and preserved WooCommerce data
- [ ] Release ZIP checksum, correct root folder and GitHub release asset verified

## Safety constraints
Production easternlivs.se must stay on the existing installed version. Do not alter Coming Soon mode, WooCommerce prices, products, stock, images or other plugins without explicit approval. Do not publish a release based only on PHP lint or presence-of-string checks.

## Configuration dependencies
Google image retrieval needs a permitted Google Programmable Search API key and search engine identifier stored server-side as ELAC_GOOGLE_CSE_KEY and ELAC_GOOGLE_CSE_CX. Without these, Google API search cannot be live-tested. Avoid pasting secrets into GitHub or chat. Voice/AI providers require separate authorization and credentials.

## Current automated CI coverage
PHP lint, basic safeguard presence, and plugin archive layout only. These do **not** constitute functional integration tests.
