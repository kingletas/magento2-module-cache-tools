# Commerce_CacheTools

Two related jobs in one module:

- **Cache warming** — walk the catalogue over the message queue, populating image-URL and swatch caches so the first shopper of the day doesn't pay for a cold cache. Every run is tracked and visible in an admin grid.
- **Edge purging** — clear Fastly/Varnish on product save, from Cache Management, or from the CLI, with a guard that stops a stage deployment flushing production.

It installs on its own, and that is checked rather than asserted — `setup:di:compile` runs with none of the
optional packages present. Redis and the swatch renderer are behind an interface with a working or no-op
default; **Fastly is behind a guard rather than a default**, because there is no useful way to fake a cache
purge. Without `fastly/fastly` the module installs and every other feature works, and a purge fails with a
`LocalizedException` naming the package to install.

---

## Installation

```bash
composer require commerce/module-cache-tools
bin/magento module:enable Commerce_CacheTools
bin/magento setup:upgrade
```

Purging additionally needs `composer require fastly/fastly`.

---

## The environment guard

A stage environment restored from a production database backup carries production's Fastly credentials. Nothing in the Fastly API stops it flushing production's cache, and a mistaken purge-all on a busy site is a genuine outage.

This module treats a deployment as production only when **both** hold:

1. `app/etc/env.php` sets `commerce/cache_tools/environment` to `production`. env.php is environment-specific and is *not* carried along when a database is refreshed downward — a database-held flag would be.
2. The store base-URL host matches the configured **Production Host**.

```php
// app/etc/env.php
'commerce' => [
    'cache_tools' => ['environment' => 'production'],
],
```

Anything missing, mismatched or unreadable means non-production, and the guard **fails closed** — it refuses the purge rather than allowing it. An unconfigured install cannot flush anything service-wide.

---

## Warming

```bash
bin/magento commerce:cache-tools:warm:queue --type=both
bin/magento queue:consumers:start commerce.cachetools.warm
```

Watch it at **System → Cache Tools → Cache Warm Runs**.

```text
warm:queue ──▶ BatchQueuer ──(per-type lock)──▶ opens a run, chunks product ids
                                                          │
                                              commerce.cachetools.warm
                                                          ▼
                                                   WarmConsumer
                                          (frontend area, per-message lock)
                                                          │
                                              ProductCacheWarmer
                                       image URLs + swatch cache per product
                                                          │
                                        tracker.incrementProgress / completeIfDone
```

One run per product type may be open at a time. If a message is lost the run would stay open forever and block every future run, so a nightly reaper closes runs that have made no progress within the configured window — measured from the **last progress update**, not the start, so a legitimately long warm survives.

```bash
bin/magento commerce:cache-tools:warm:reap    # same thing, on demand
```

---

## Purging

On product save, via the configured strategy:

| Strategy | What it does | When to use it |
| --- | --- | --- |
| `url` | Purges the product's frontend URL in every store | Always works |
| `surrogate_key` | Purges the product's cache tag | Needs the CDN emitting Magento's tags; also clears listings |

Soft purges are the default: objects are marked stale rather than evicted, so shoppers keep getting a fast response while origin revalidates. A hard purge on a busy catalogue sends every concurrent request to origin at once.

Purged URLs are automatically queued for re-warming, so the edge refills on a worker instead of making the next shopper pay for the miss.

---

## Health checks

```bash
bin/magento commerce:cache-tools:varnish:health https://www.example.com/ https://www.example.com/tops.html
```

```text
 URL                                   HTTP  Cache  Age   Served by
 https://www.example.com/              200   HIT    412s  cache-lhr-egll
 https://www.example.com/tops.html   200   MISS   -     cache-lhr-egll
```

Also available as a button on **System → Cache Management**.

---

## Optional integrations

| Interface | Default | Replace it when |
| --- | --- | --- |
| `KeyPatternPurgerInterface` | Redis `SCAN` purger | Not on Redis → bind the null purger |
| `SwatchCacheWarmerInterface` | Magento swatch renderer | Custom swatch cache |
| `PurgeStrategyInterface` | url, surrogate_key | You need a third |
| `ProductImageUrlResolverInterface` | Foundation default | On a CDN like Cloudinary |

---

## Behaviour

- **Each Fastly client gets its own `Configuration`.** The SDK's
  `Configuration::getDefaultConfiguration()` returns a process-wide singleton, so
  setting a token on it mutates state shared with every other consumer of the SDK
  in the same process — in a long-running queue consumer serving several stores,
  one store's token becomes every store's token.
- **Purging is a POST, behind its own ACL resource.** Magento enforces admin
  form-key validation on POST actions only, so a purge on a GET route can be
  triggered by anything that makes an admin browser issue a request — an
  `<img src>` on an unrelated page will do. The separate ACL resource exists
  because reading cache state is harmless and plenty of roles want it without the
  ability to flush production.
- **The purge-strategy dropdown is built from the strategy pool**, not from a
  hardcoded list, so the options offered are exactly the ones that resolve.
- **Admin notices are fixed sentences.** Purge failures put no API response text
  on screen; the detail goes to the log.
- **`HealthResult` cannot be constructed unreachable without a reason.** A probe
  that failed silently is indistinguishable from a probe nobody ran.
- **The Redis purge logs once per pattern, with a count** — not once per delete
  chunk, which on a large sync is thousands of lines.

---

## Gotchas

- **The environment guard fails closed, and a blank production host means non-production.** An unconfigured install cannot purge a service at all. That is the safe default, not a bug — but it does mean purging appears broken until both the config value and the `app/etc/env.php` entry are set.
- **Production needs the `env.php` entry or purging refuses.** A database-held flag would travel with a production database restored down to stage, which is the whole failure the two-signal check exists to prevent.
- **A warm run cannot close itself if a batch is skipped as a duplicate.** The run then sits at `running` forever and blocks every future run of that type, because only one per type may be open. `StaleRunReaper` on the hourly cron is what closes it; the consumer logs a warning naming the run when it happens.
- **MQ topics must declare `is_synchronous="false"`.** Magento defaults an omitted value to true. A `setup:upgrade` error reading "Error while checking if topic X is synchronous" is usually a stale `config` cache — run `cache:clean config` first.
- **Both consumers must be started.** Warming and re-warming are separate topics, and a purge with no re-warm consumer running leaves the next shopper paying for the miss.
- **Key-pattern purging needs Redis.** On any other cache backend it degrades to the null purger, which warns once and then does nothing — so SKU-keyed swatch entries are not invalidated.

---

## Tests

```bash
M2_VENDOR=/path/to/magento/vendor php ../dev/run-tests.php -c ../dev/phpunit.xml
```

The suite runs against a real Magento installation without being installed into it. `dev/bootstrap.php` builds a PSR-4-only autoloader from that installation's composer map, which is also why it works where the host's own `vendor/autoload.php` is broken.

---

## Rebranding

```bash
php ../bin/rebrand Acme
```

Then update `app/etc/env.php` (`acme/cache_tools/environment`), rename the table and re-point config rows:

```sql
RENAME TABLE commerce_cachetools_warm_run TO acme_cachetools_warm_run;
UPDATE core_config_data SET path = REPLACE(path, 'commerce_cachetools/', 'acme_cachetools/')
 WHERE path LIKE 'commerce_cachetools/%';
```
