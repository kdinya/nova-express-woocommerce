# Agent Guidelines & Technical Instructions for Nova Express Woo

> **Note for AI Agents:** The repository owner will frequently refer to this document as **"інструкція"** (Ukrainian for "instruction" / "manual"). Whenever the user mentions **"інструкція"**, **"згідно інструкції"**, or **"дивись в інструкцію"**, they are strictly referring to this file (`AGENTS.md`). Always consult and follow this file. See point 0 for the rules on updating it.

---

## 1. Core Operating Principles for AI Agents

0. **Never Edit This Instruction Without Asking First:**
   - AI agents must never modify, expand, restructure, or delete anything in this file on their own initiative — not even a fix to an obvious typo or contradiction.
   - If an agent identifies something that should be added, removed, or changed here (a new pattern, a discovered gotcha, a fixed contradiction), it must first explicitly describe the proposed change to the repository owner and wait for approval. Only after explicit approval may it edit this file.
   - This applies even when the agent is confident the change is correct, small, or non-controversial.

1. **Two Execution Modes — Direct Instructions vs. Critique/Feedback:**
   - **Direct instruction** ("зроби X", "онови Y", "постав Z", "прибери W"): execute immediately, without pausing to ask "should I do this?" or "are you sure?". Do the work, then report what was done. Silence about a step is not permission to skip it — if something in the instruction is genuinely ambiguous (not just risky), pick the most reasonable interpretation, state the assumption in your reply, and proceed.
   - **Critique or open-ended feedback** (the user points out something looks off, asks what you think, or raises a concern without a concrete task attached): analyze first — explain implications, edge cases, and alternatives — and wait for the user's explicit go-ahead before touching code.
   - A direct instruction that turns out to be risky (schema change, altered order-meta keys, checkout hooks, API signatures) is still executed — flag the risk clearly in your reply as you make the change, rather than stopping to ask, unless the instruction itself doesn't make clear what to do.

2. **No Silent Breaking Changes:**
   - If a change could break existing functionality, schema compatibility, or live store operations, always say so plainly in your reply (per point 1) — never leave it unflagged.

3. **Release Lifecycle — Always Overwrite the Latest Version, No Questions Asked:**
   - By default, any code change updates and overwrites the CURRENT latest release: keep the existing version number and tag, force-update the tag, delete and re-publish the GitHub Release, let the workflow rebuild the ZIP/SHA-256 assets. Do not ask whether to overwrite or create a new version first — just overwrite.
   - Create a NEW version (increment the sequential counter) ONLY when the user explicitly asks for a new version.
   - If a release should NOT be updated at all (work left mid-task, explicitly on hold), the user will say so directly — absent that instruction, always update the latest release.
   - **Version Format — STRICT, never deviate:** `vYYYY.MM.NN` — year (4 digits), month (2 digits, zero-padded), sequential release counter zero-padded to at least 2 digits (`v2026.09.09`, next is `v2026.09.10` — NOT date-based, NOT `v2026.9.9`). Must match exactly across: Git tag, plugin header, `NVX_VERSION` constant, `readme.txt` Stable tag (all without the leading `v` except the Git tag itself).
   - `bin/verify.sh` / CI must reject any tag or version string that doesn't match `^v?[0-9]{4}\.[0-9]{2}\.[0-9]{2}$` — this format has been violated in past releases (e.g. `v2026.9.7`), so it must be enforced by the script, not just by this text.
   - Before force-pushing a tag or deleting/republishing a release: run `git fetch` and check whether `origin/main` moved since you last read it. If there are commits from an author or a timeframe you didn't expect, stop and tell the user instead of silently overwriting — another agent may be mid-task on the same repository.
   - A task is not complete until `GitHubUpdater` is guaranteed to recognize and cleanly download the new/updated version: the release is published (not draft), and `wc-nova-express.zip` is attached and correctly rooted.

4. **Mandatory Pre-Flight Verification Gate Before ANY Commit, Push, Tag, or Release:**
   - Run `bash bin/verify.sh` (version consistency, `php -l` across all PHP files, `node --check` across all JS files, full PHPUnit suite). Fix any failure before pushing — never push, tag, or release with a failing gate.
   - If your environment genuinely cannot run part of the gate (no network access, no `nix`, PHPUnit unavailable) — say so explicitly in your reply to the user. Do not silently skip the check, and never claim it passed if you couldn't actually run it.

5. **Source of Truth: Always Read the Actual Latest Repository State:**
   - Before performing any task, read the current files / latest commit on `main` directly. Never rely on chat history, memory, or a previously discussed code state — other AI agents or the repository owner may have changed the code between sessions.

6. **Audience & Language Policy:**
   - The plugin targets a Ukrainian-only audience. No i18n requirements: hardcoded Ukrainian strings in admin views and AJAX responses are fine and must be preserved. Don't spend effort wrapping strings in `__()`/`_e()` or generating `.pot` files unless explicitly asked.

7. **Strict Adherence to Plugin Design System (No Default Browser Widgets):**
   - The plugin has its own visual identity: signature green palette (`--nvx-primary: #7CB342`, `--nvx-primary-dark: #689f38`, `--nvx-border: #dde5d6`), rounded corners, custom SVG icons.
   - Never render unstyled default browser controls (browser-blue checkboxes, default radio buttons, mismatched inputs). All admin settings, checkboxes, cards, badges, and checkout inputs use the custom `.nvx-*` design classes with brand colors.

8. **Strict Minimal Diffs & Zero Accidental Side-Effects (Surgical Changes Only):**
   - Modify only the explicit target elements and lines required for the task. Never remove, rename, refactor, or reformat adjacent UI elements, labels, timestamps, helper texts, CSS classes, or attributes unless specifically asked.
   - Inspect `git diff` before every commit to confirm nothing unrelated was accidentally changed.

9. **Mandatory Consultation with Official Nova Poshta Documentation:**
   - When fixing, modifying, or adding functionality related to the Nova Poshta API, endpoints, printing, webhooks, or parameters, consult the official documentation (https://api-portal.novapost.com/ and https://developers.novaposhta.ua) before assuming or inventing endpoints/formats. Verify exact paths, query parameters, formats, and error codes against the official specs.
   - If the official documentation is not reachable from your current environment, say so explicitly in your reply rather than guessing or relying on memory.

---

## 2. Plugin Architecture — Quick Map

This section is a map of where things live and the non-obvious business rules that must survive any refactor. It is deliberately not a full architecture spec — for exact current implementation details, always read the actual code (point 5). If you add a genuinely new subsystem, hook, or gotcha, propose an addition here per point 0.

### 2.1. Core
- Entry point: `wc-nova-express.php`. Container: `includes/Plugin.php` (`Plugin::instance()->boot()`). Lightweight `spl_autoload_register` mapping `NovaExpress\` to `includes/` — no bloated runtime Composer packages.
- **HPOS:** compatibility declared under `before_woocommerce_init`. Never use `get_post_meta()`/`update_post_meta()`/raw SQL against `wp_posts` for order data — always `$order->get_meta()`, `update_meta_data()`, `save()`.

### 2.2. Nova Poshta API Client (`includes/Api/`)
- `NovaPoshtaClient.php` wraps `https://api.novaposhta.ua/v2.0/json/` via `wp_remote_post`. Throws `NovaPoshtaApiException` on API errors or connection failures.
- Contract tests & fixtures: `tests/Fixtures/NovaPoshta/`, `NovaPoshtaContractTest.php`.
- Never suppress or hide Nova Poshta API error details — always surface provider `codes`/`messages` to the operator (admin UI / order notes / logs).

### 2.3. Waybill (ТТН) Management (`includes/Ttn/`)
- `TtnManager.php` / `TtnRepository.php`: creation, editing, barcode, status sync.
- **Concurrency lock (`lock_owner_token`):** prevents double waybill creation from double-clicks/concurrent requests — acquired before API calls, released on all paths including errors.
- Once a waybill is dispatched (in transit / completed), the delete button stays hidden in admin views.
- Never auto-delete a local TTN record on a temporary `not_found` tracking response — flag as unknown and keep the record.
- Status/carrier timestamps always use site timezone (`wp_date()`), never raw UTC.
- **Postomat limits (20 kg, 40×60×30 cm, 29 000 UAH declared value, 1 place):** warn in the admin waybill page only — never block the buyer at checkout.
- Phone numbers are normalized server-side to `+380XXXXXXXXX` before any waybill API call — never rely on frontend validation alone.
- **Price calculation (`InternetDocument.getDocumentPrice`) must use the same volumetric-weight inputs (width/height/length per place) as actual waybill creation.** A price estimate that only sends the declared weight and omits dimensions will under-price bulky-but-light parcels compared to Nova Poshta's own calculator — this has been a real, reported discrepancy.

### 2.4. Local Warehouse & City Sync (`includes/Warehouse/`)
- Cities in `wp_nvx_cities`, warehouses/postomats in `wp_nvx_warehouses`. Resumable batch sync (survives timeouts), closed warehouses pruned on full sync completion.
- Autocomplete checks local cache first, falls back to live API if local sync isn't finished yet. Results cached via Transients.
- Handles all Ukrainian apostrophe variants (`'`, `’`, `ʼ`, `` ` ``) in place names.

### 2.5. Automation Engine (`includes/Automation/`)
- Triggers: `nvx/ttn_status_changed`, `woocommerce_order_status_changed`, and `nvx/ttn_created` — the last one carries a `$source` of `created` or `attached`, which `RuleEngine` maps to two separate rule triggers, `ttn_created` (ТТН створено) and `ttn_added` (ТТН додано). Keep them separate; neither fires on a rule's "any status" match.
- Internal actions (order status, order notes) run synchronously; external actions (webhooks, emails) are deferred via WP-Cron (`nvx/run_deferred_action`) so they never block the UI request.
- Webhooks use GET by user design — do not switch to POST unless explicitly requested by the repository owner.
- Automation log (`wp_nvx_automation_log`) is pruned by daily cron `nvx/prune_automation_log` (default 90 days, filterable via `nvx/automation_log_retention_days`).

### 2.6. Scheduled Tracking (`includes/Tracking/`)
- `TrackingScheduler.php` / `TrackingRunner.php`: cron-based polling of active, non-delivered waybills in batches (`TrackingDocument.getStatusDocuments`), with exponential backoff (5m/15m/30m) on consecutive API failures.

### 2.7. Admin Experience (`includes/Admin/`)
- `Settings.php`: API key, sender defaults, label template preferences (widths/margins/fonts/toggles) — see the file for the current field list rather than hardcoding it here.
- `OrderMetaBox.php` / `CreateWaybillPage.php`: waybill creation UI. `OrderListColumn.php`: TTN badge + short carrier status in the orders list. `LabelPrint.php`: label/PDF printing, including official NP print redirects — must never expose the API key in client-side JS.
- **The plugin must never affect the checkout shipping cost.** The shipping method rate is always zero; the customer pays delivery to Nova Poshta at receipt. Do not reintroduce a checkout price-calculation mode without an explicit user request.

### 2.8. Self-Updater (`includes/Updater/GitHubUpdater.php`)
- Fetches the latest published release from the GitHub API, compares `tag_name` with `NVX_VERSION`.
- Must strictly download the named asset `wc-nova-express.zip` (verify presence and size) — never fall back to `zipball_url`.
- Manual ZIP installs must land in the `wc-nova-express` folder (not a versioned folder name) via `upgrader_source_selection`, so WordPress replaces the existing plugin instead of duplicating it.

### 2.9. Frontend Checkout Compatibility (`includes/Frontend/`)
- Must work with both classic WooCommerce checkout and Cart & Checkout Blocks.
- When Nova Poshta is selected: standard address fields (city, postcode, address line, state) are hidden and non-required. When another shipping method is selected: those fields are visible and required again.
- Any checkout change must preserve existing hooks/CSS classes/JS selectors that themes or other plugins may depend on.

### 2.10. Database Schema Changes (`includes/Install/Installer.php`)
- Any table change (`wp_nvx_cities`, `wp_nvx_warehouses`, `wp_nvx_ttn`, `wp_nvx_automation_log`, rules table) must go through versioned migrations in `Installer` (bump the DB version option, apply `dbDelta`/targeted `ALTER TABLE`) — never a one-off manual query that existing installs won't receive.

## 3. Mandatory Development Checklist for Any Change

Before submitting or releasing code:
1. [ ] If this was critique/open-ended feedback rather than a direct instruction: did you explain your analysis and get explicit go-ahead before changing code? (Direct instructions skip this — see point 1.)
2. [ ] **Local Verification Gate (`bin/verify.sh`):** did it pass 100% (version format + consistency, PHP lint, JS lint, PHPUnit)? If it couldn't be run in this environment, did you say so explicitly?
3. [ ] **Version consistency:** are the Git tag, `wc-nova-express.php` (header & constant), and `readme.txt` (Stable tag) synchronized and in the strict `vYYYY.MM.NN` format?
4. [ ] **Repository freshness before push:** did you `git fetch` and confirm `origin/main` hasn't moved unexpectedly since you last read it?
5. [ ] **Security check:** nonces checked, capabilities verified (`manage_woocommerce`), inputs sanitized?
6. [ ] **HPOS:** all order-data calls use WC Order methods, not `*_post_meta()` or raw SQL on `wp_posts`?
7. [ ] **UI check:** responsive styles intact on mobile/narrow screens, and everything uses the `.nvx-*` design system (no default browser widgets)?
8. [ ] **Checkout universality:** if checkout code changed, does it work in both classic checkout and Checkout Blocks, and does it still never affect the shipping cost shown to the customer?
9. [ ] **Schema migration:** if tables changed, did `Installer` handle existing installations via a versioned migration?
10. [ ] **Zero accidental side-effects (`git diff` review):** did you inspect the diff line-by-line to confirm nothing unrelated was touched?
11. [ ] **Nova Poshta documentation compliance:** did you verify any NP API methods/URLs/parameters against the official docs, or explicitly flag that you couldn't reach them?
12. [ ] **Instruction changes:** if you think this file (`AGENTS.md`) should be updated as a result of this work, did you ask the repository owner first, per point 0 — rather than editing it yourself?
