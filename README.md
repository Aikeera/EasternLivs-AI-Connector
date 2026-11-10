# Eastern Livs AI Connector

WordPress/WooCommerce connector for Eastern Livs.

## Deployment status

The live WordPress site currently has plugin version 0.4.0 installed. This repository is being prepared as the public update source. **No release ZIP has been published yet; WordPress automatic updates are not enabled yet.**

## Update requirements

The v0.4.0 updater requires the WordPress `wp-config.php` constant:

```php
define('ELAC_UPDATE_REPOSITORY', 'Aikeera/EasternLivs-AI-Connector');
```

It checks the latest non-prerelease GitHub Release for an asset named `eastern-livs-ai-connector.zip` whose archive contains the `eastern-livs-ai-connector/` plugin folder. Only publish verified releases with a version newer than the installed version.

## Security

Never commit application passwords, WooCommerce API secrets, personal data, or WordPress configuration. Preserve administrator approval for price and image changes. Do not change Coming Soon mode without explicit authorization.
