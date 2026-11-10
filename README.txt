Eastern Livs AI Connector — Version 0.4.0

WordPress/WooCommerce connector for Eastern Livs.

FEATURES
- Authenticated product catalog and page reads.
- Search products by name or barcode.
- Catalog audit for missing price, barcode, or featured image.
- Submit price-change and image-change requests.
- Administrator approval or rejection of pending requests.
- Stale-value checks before applying approved changes.
- Optional GitHub Release update checker.

LIMITATIONS
- This is not a ChatGPT MCP connector and does not provide ChatGPT direct authenticated access.
- Image requests require an approved HTTPS image URL; they do not automatically find or license authentic product photography.
- No product is modified until an administrator approves the request.
- GitHub updates require ELAC_UPDATE_REPOSITORY configured in wp-config.php and a newer public GitHub Release with asset eastern-livs-ai-connector.zip.
- The live WordPress installation and update flow must be tested separately.

SECURITY
- Never publish WordPress Application Passwords, WooCommerce keys, or personal data in GitHub.
- Back up the site before plugin updates.
- Keep Coming Soon mode unchanged unless explicitly approved.
