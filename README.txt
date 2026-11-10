Eastern Livs AI Connector v0.1.0 — prototype

WHAT IT DOES:
- Authenticated REST read endpoints for WooCommerce product summaries and page metadata.
- WooCommerce simple-product regular price proposals (not immediate writes).
- Manual approve/reject in Dashboard > Eastern Livs AI.
- Administrator capability checks and nonces; stale-price check before approval.

WHAT IT DOES NOT DO:
- It is NOT an MCP server, ChatGPT plugin or direct ChatGPT Plus connector.
- Does not use the OpenAI API or avoid ChatGPT plan restrictions.
- Does not edit Elementor, shipping zones, images, or orders.
- Does not run without a separately configured authenticated REST client.

INSTALL (test/staging recommended):
1. Back up your site's files and database.
2. WordPress Dashboard > Plugins > Add New > Upload Plugin.
3. Select eastern-livs-ai-connector.zip; Install Now; Activate.
4. Visit Dashboard > Eastern Livs AI for API paths and pending approvals.
5. Keep WPVibe enabled until another integration is verified.

SECURITY:
- WordPress REST API authentication and WordPress capabilities apply.
- Use HTTPS and WordPress Application Passwords with an appropriately scoped user; never share credentials in chat.
- Do not expose an application password in a browser URL.
- Test on staging prior to your live site. v0.1.0 is a prototype, not production audited.

Version 0.3.0 additions:
- Authenticated read-only /catalog-search?q=... (SKU exact match and product-name search).
- Authenticated read-only /catalog-audit?page=1&per_page=50 (missing image, price, barcode).
- Existing price/image requests require manual admin approval. No automatic publishing or deletion.
- This is a WordPress REST plugin, not a ChatGPT/MCP connector.

Version 0.4.0: optional GitHub release-based WordPress native update checks. Requires ELAC_UPDATE_REPOSITORY configured to an official public repository, and a named ZIP asset in a published release. Manual Update now remains the recommended mode.
