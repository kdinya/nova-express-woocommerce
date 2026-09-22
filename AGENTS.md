# Agent Guidelines & Technical Instructions for Nova Express Woo

> **Note for AI Agents:** The repository owner will frequently refer to this document as **"інструкція"** (Ukrainian for "instruction" / "manual"). Whenever the user mentions **"інструкція"**, **"згідно інструкції"**, or **"дивись в інструкцію"**, they are strictly referring to this file (`AGENTS.md`). Always consult, follow, and update this file accordingly.

---

## 1. Core Operating Principles for AI Agents
0. **Ask Before Modifying This Instruction:** If an AI assistant discovers, invents, or identifies any new pattern, requirement, architecture standard, or improvement that should be documented in this file (`AGENTS.md` / **інструкція**), it MUST explicitly ask the repository owner for permission first before modifying this file. Never edit or expand this instruction without explicit user approval.

1. **Critical Thinking on Feedback & Strict Approval Flow:**
   - When the user provides critique, comments, or suggests changes, **never implement them blindly or impulsively**.
   - First, conduct a thorough technical analysis of the request and its implications.
   - Give a concise, clear assessment highlighting potential side effects, edge cases, or better alternatives.
   - **Only execute modifications after the user explicitly reviews and approves the proposed plan.**

2. **No Breaking Changes Without Prior Warning:**
   - Never push or commit changes that could break existing functionality, schema compatibility, or live store operations.
   - If a proposed change carries risk (e.g., database schema changes, altering order meta keys, refactoring checkout hooks, modifying API signatures), warn the user explicitly and explain how to mitigate the risk before touching code.

3. **Always Overwrite & Complete Releases (Full Release Lifecycle):**
   - Unless explicitly instructed otherwise by the user, **always produce a fully functional, verifiable release** rather than leaving work in a draft, unfinished branch, or unreleased state.
   - A task is NOT complete until the WordPress built-in updater (`GitHubUpdater`) is guaranteed to recognize and cleanly download the new version.
   - **Version Format — STRICT (user-mandated, never deviate):**
     - Format: `vYYYY.MM.NN` where `v2026` = year (4 digits), `.09` = month (2 digits), and the last number (`.NN`) is a **sequential release counter, NOT the day of the month**.
     - Examples: `v2026.09.09` → next sequential version is `v2026.09.10` (NOT `v2026.09.22`, NOT date-based). Counter simply increments: 09 → 10 → 11...
     - This exact format must be used in the Git tag (`v2026.09.09`), plugin header, `NVX_VERSION` constant, and `readme.txt` Stable tag (header/constant/readme without the leading `v`).
   - **Overwrite-Until-Told-Otherwise Rule:**
     - By default, new code changes OVERWRITE the current latest release: keep the existing version number and tag (e.g. stay on `v2026.09.09`), force-update the tag, delete and re-publish the GitHub Release, and let the workflow rebuild the ZIP/SHA-256 assets.
     - Increment the sequential counter (e.g. `v2026.09.09` → `v2026.09.10`) ONLY when the user explicitly says to create a new version.
   - **Version Consistency Rule:** Every release requires updating the version in all 3 mandatory locations simultaneously:
     1. `wc-nova-express.php`: Plugin header (`* Version: YYYY.MM.NN`).
     2. `wc-nova-express.php`: Constant definition (`define( 'NVX_VERSION', 'YYYY.MM.NN' );`).
     3. `readme.txt`: Header tag (`Stable tag: YYYY.MM.NN`).
     - Releases on GitHub must be published (not draft). Publishing triggers `.github/workflows/release-zip.yml`.
     - The workflow packages `wc-nova-express.zip` (verifying that tests and developer files are excluded) and attaches it along with `wc-nova-express.zip.sha256` to the release assets.

4. **100% Test & Linter Verification Before Any Push:**
   - PHP linting: `find . -type f -name "*.php" -not -path "./vendor/*" -exec php -l {} +` must pass with zero errors.
   - JavaScript validation: `node --check assets/js/*.js` must pass.
   - PHPUnit suite: `vendor/bin/phpunit --configuration phpunit.xml.dist` must pass 100%.
   - Specifically verify:
     - `tests/Unit/VersionConsistencyTest.php` (header vs constant vs readme).
     - `tests/Unit/ReleasePackagingTest.php` (ensures developer/test files are not packaged).
     - `tests/Unit/NovaPoshtaContractTest.php` (ensures payload schemas conform to Nova Poshta API specs).
     - `tests/Unit/TtnLockTest.php` (atomic lock token integrity).

5. **Concise, High-Signal Communication:**
   - Keep answers clear, direct, and focused on outcomes.
   - Avoid filler text, repeated apologies, or boilerplate introductions.

6. **Self-Updating Instruction Requirement:**
   - Whenever a new feature, database table, hook, API integration, or architectural pattern is added to the plugin, the AI responsible **must append or update the relevant section in this document (`AGENTS.md`)**.
7. **Source of Truth: Always Analyze the Latest Repository Version:**
   - Before performing ANY task, **always clone or read the current repository files directly** (or verify the latest commit on `main`).
   - **Never rely on chat history, memory, or previously discussed code states.** Other AI assistants may have edited the repository between conversations; the code you remember may be outdated.
   - Re-verify file contents, architecture, and version numbers in the actual working copy before proposing or making changes.

8. **Audience & Language Policy:**
   - The plugin targets a **Ukrainian-only audience**. There are **no internationalization (i18n) requirements**: hardcoded Ukrainian strings in admin views and AJAX responses are acceptable and must be preserved.
   - Do **not** spend effort wrapping strings in `__()`, `_e()`, or generating `.pot` translation files unless the user explicitly requests it later.


---

## 2. Complete Plugin Architecture & Technical Specifications

The plugin is structured around a central singleton IoC container (`includes/Plugin.php`) using a custom PSR-4-like autoloader mapped to the `NovaExpress\` namespace.

### 2.1. Core Initialization & Environment
- **Main Entry Point:** `wc-nova-express.php`
- **Class Container:** `includes/Plugin.php` (`Plugin::instance()->boot()`)
- **Autoloader:** Lightweight `spl_autoload_register` mapping `NovaExpress\` directly to `includes/`. Do not introduce bloated runtime Composer packages.
- **HPOS Compatibility:** Declared via `\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', ...)` under `before_woocommerce_init`.
  - **Rule:** Never use `get_post_meta()`, `update_post_meta()`, or direct SQL queries against `wp_posts` for order data. Always use `$order->get_meta()`, `$order->update_meta_data()`, and `$order->save()`.

### 2.2. Nova Poshta API Client (`includes/Api/`)
- **`NovaPoshtaClient.php`:**
  - Base URL: `https://api.novaposhta.ua/v2.0/json/`
  - Transport: WordPress HTTP API (`wp_remote_post`) with default 15s timeout.
  - Error Handling: Checks both HTTP response codes and Nova Poshta payload flags (`success === true`). Throws `NovaPoshtaApiException` on API errors or connection failures.
  - Fixtures & Contract Tests: Spec contracts are defined in `tests/Fixtures/NovaPoshta/` and tested via `NovaPoshtaContractTest.php`.
  - **Never suppress or hide Nova Poshta API error details.** Always persist and surface the provider's error `codes` and `messages` to the operator (admin UI / order notes / logs) so failures are diagnosable.

### 2.3. Waybill (ТТН) Management & Security (`includes/Ttn/`)
- **`TtnManager.php` & `TtnRepository.php`:**
  - Manages creation, editing, barcode generation, and status syncing for waybills.
  - **Atomic Concurrency Lock (`lock_owner_token`):** Prevents double waybill creation caused by rapid double-clicking or concurrent requests. A transient lock with an owner token is acquired before API calls and released upon completion.
  - **Dispatch State Protection:** Once a waybill has been dispatched (carrier status indicates parcel is in transit or completed), the delete button is permanently hidden in admin views to prevent accidental deletion of live shipments.
  - **Site Timezone Formatting:** Timestamps for status updates and carrier changes must always account for WordPress timezone offsets (`wp_date()` / site timezone), never raw UTC.
  - **Postomat (Поштомат) Live Validation Rules:**
    - Maximum weight: **20 kg**.
    - Maximum dimensions: **40 × 60 × 30 cm** (sum / individual limits).
    - Maximum declared value: **29,000 UAH**.
    - Maximum parcels/places: **1 place only**.
  - **Phone Normalization:** Before any waybill creation API call, recipient/sender phone numbers MUST be normalized server-side to the Nova Poshta required format `+380XXXXXXXXX`. Never rely on frontend validation alone for phone format.

### 2.4. Local Warehouse & City Synchronization (`includes/Warehouse/`)
- **`WarehouseRepository.php` & `WarehouseSync.php`:**
  - Stores cities in `wp_nvx_cities` and warehouses/postomats in `wp_nvx_warehouses`.
  - **Resumable Sync:** Large sync jobs track batch progress in options/transients so timeouts can safely resume from the previous page. Closed warehouses are pruned upon full sync completion.
  - **Live API Fallback & Transient Caching:**
    - Autocomplete endpoints check local cache first.
    - If the local DB has not finished syncing, fallback to live Nova Poshta API search is automatically engaged.
    - City and warehouse search results are cached using WordPress Transients to avoid hammering the API.
  - **Apostrophe Normalization:** Supports all variations of Ukrainian apostrophes (`'`, `’`, `ʼ`, `` ` ``) in place names (e.g., Кам'янець-Подільський).

### 2.5. Automation Engine (`includes/Automation/`)
- **`RuleEngine.php` & `RuleRepository.php`:**
  - Listens to triggers:
    - `nvx/ttn_created` (when a new waybill is registered)
    - `nvx/ttn_status_changed` (when tracking cron detects a status transition)
    - `woocommerce_order_status_changed` (when an admin or gateway updates order status)
  - **Action Execution Strategy:**
    - **Synchronous Actions:** Internal changes like updating order status (`ChangeStatusAction`) or appending order notes (`AddNoteAction`) execute immediately.
    - **Deferred Asynchronous Actions:** External network operations such as webhooks (`SendWebhookAction`) or emails (`SendEmailAction`) are scheduled via WP-Cron (`nvx/run_deferred_action`) to prevent blocking UI requests.
  - **Log Retention & Cleanup:** Automation execution logs in `wp_nvx_automation_log` are pruned via daily cron `nvx/prune_automation_log` (default 90-day retention, filterable via `nvx/automation_log_retention_days`).

### 2.6. Scheduled Tracking (`includes/Tracking/`)
- **`TrackingScheduler.php` & `TrackingRunner.php`:**
  - Registers scheduled cron checks at configurable intervals (e.g., 30m, 1h).
  - Queries active, non-delivered waybills in batches (`TrackingDocument.getStatusDocuments`).
  - Updates order meta and triggers automation events when status changes occur.

### 2.7. Admin Experience & Customization (`includes/Admin/`)
- **`Settings.php`:** General settings, sender defaults, API key, and dimensions fallback.
- **`OrderMetaBox.php` & `CreateWaybillPage.php`:** Full-featured creation UI with responsive grid layout for narrow screens and mobile devices.
- **`OrderListColumn.php`:** Displays TTN badge, carrier status code, and quick print links directly in WooCommerce Orders list.
- **`LabelPrint.php`:** Supports direct printing of 100×100mm thermo-stickers and standard A4 layouts.
- **`Assets.php` & `appearance-page.php`:** Visual theme presets and custom color pickers for admin headers and badges.

### 2.8. Self-Updater Mechanism (`includes/Updater/GitHubUpdater.php`)
- Hooks into `pre_set_site_transient_update_plugins` and `plugins_api`.
- Fetches the latest published release from `https://api.github.com/repos/kdinya/nova-express-woocommerce/releases/latest`.
- Compares `tag_name` with `NVX_VERSION`.
- Downloads the asset `wc-nova-express.zip` and verifies the folder structure via `upgrader_source_selection`.
- Ensures the plugin remains active after update execution without session loss.

---

### 2.9. Frontend Checkout Compatibility (`includes/Frontend/`)
- The checkout integration MUST be **universal**: it has to work reliably with **both** the classic WooCommerce checkout (`woocommerce_checkout_fields` hooks + jQuery selectors) and the modern **Cart & Checkout Blocks** used by current themes.
- Any change to checkout behavior must preserve existing hooks, CSS classes, and JS selectors that custom themes and one-page checkout plugins may depend on.
- When touching checkout code, always verify both paths: classic template rendering and block-based rendering. If a feature can only support one path, warn the user before implementing.

### 2.10. Database Schema Changes (`includes/Install/Installer.php`)
- Any change to plugin tables (`wp_nvx_cities`, `wp_nvx_warehouses`, `wp_nvx_ttn`, `wp_nvx_automation_log`, rules table) MUST go through the versioned migration checks in `Installer` (increment the DB version option, apply `dbDelta` or targeted `ALTER TABLE`), never through one-off manual queries that existing installs will not receive.

## 3. Mandatory Development Checklist for Any Change

Before submitting or releasing code:
1. [ ] **Analyze feedback first:** Did you explain your plan to the user and receive confirmation?
2. [ ] **Version consistency:** Are `wc-nova-express.php` (header & constant) and `readme.txt` (Stable tag) synchronized?
3. [ ] **Syntax & Linting:** Did `php -l` and `node --check` pass on all modified files?
4. [ ] **Test execution:** Did all PHPUnit tests pass cleanly (`vendor/bin/phpunit`)?
5. [ ] **Security check:** Are nonces checked, capabilities verified (`manage_woocommerce`), and inputs sanitized?
6. [ ] **HPOS verification:** Are all order data calls using WC Order methods (`get_meta`/`update_meta_data`)?
7. [ ] **UI check:** Are responsive styles intact on mobile/narrow screens?
8. [ ] **Instruction update:** Did you document any new hooks, features, or behaviors in this `AGENTS.md` file?
9. [ ] **Repository freshness:** Did you analyze the actual latest commit on `main` (not chat history) before making changes?
10. [ ] **Checkout universality:** If checkout code changed, does it work in both classic checkout and Checkout Blocks without breaking themes/plugins?
11. [ ] **Schema migration:** If tables changed, did `Installer` handle existing installations via versioned migration?
