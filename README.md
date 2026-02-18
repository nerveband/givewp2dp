# GiveWP to DonorPerfect Sync

A WordPress plugin that syncs [GiveWP](https://givewp.com/) donations to [DonorPerfect](https://www.donorperfect.com/) in real-time. Matches donors by email, handles one-time and recurring donations (via DP-native pledges), and includes a historical backfill tool with comprehensive logging.

## Features

- **Real-time sync** — automatically pushes new GiveWP donations to DonorPerfect (disabled by default for safety)
- **Donor matching** — finds existing DP donors by email before creating duplicates
- **Recurring donation support** — maps GiveWP subscriptions to DP pledges, with renewal payments linked via `@plink`
- **Historical backfill** — batch-sync past donations with preview (dry run) mode
- **Match report** — preview how GiveWP donors will map to DP records before syncing
- **Sync log** — every sync attempt is logged with status, DP IDs, and error details
- **Gateway mapping** — configurable mapping from GiveWP payment gateways to DP gift type codes
- **Admin dashboard** — stats, log viewer, settings, and tools in WP Admin

## Requirements

- WordPress 6.0+
- PHP 8.0+
- [GiveWP](https://givewp.com/) 3.x (the donation plugin)
- A [DonorPerfect](https://www.donorperfect.com/) account with XML API access

## API Documentation

This plugin uses the **DonorPerfect XML API** (stored procedures + dynamic SQL). You will need:

1. **DonorPerfect XML API Manual** (PDF) — contact DonorPerfect support or your account manager to request the "DPO XML API Manual" (current version: v6.9). This PDF documents all stored procedures, parameter tables, and sample calls.

2. **DonorPerfect API Key** — generate one from your DonorPerfect admin panel under Admin > My Settings > API Keys, or contact DonorPerfect support.

3. **GiveWP Developer Docs** — https://developer.givewp.com/ — covers the donation model, hooks, and subscription API that this plugin hooks into.

### Key API endpoints used

| Procedure | Purpose | Params |
|---|---|---|
| `dp_savedonor` | Create/update donors | 28 |
| `dp_savegift` | Create gifts (one-time + pledge payments) | 32 |
| `dp_savepledge` | Create pledges (recurring subscriptions) | 27 |
| `dp_savecode` | Create code values in DPCODES | 34 |
| Dynamic SQL | `SELECT`/`UPDATE` against dp, DPGIFT, DPCODES | — |

> **Critical**: Every stored procedure requires ALL parameters from the PDF sample call, even ones not listed in the parameter table. Missing any parameter causes a misleading "user not authorized" error. See the [API gotchas](#api-gotchas) section below.

## Installation

### Download (recommended)

1. Go to the [Releases](https://github.com/nerveband/givewp-donorperfect-sync/releases) page
2. Download the **Source code (zip)** from the latest release
3. In WordPress, go to **Plugins > Add New > Upload Plugin** and upload the zip
4. Activate the plugin

The plugin includes a **built-in auto-updater** that checks this GitHub repo for new releases. When a new version is published, you'll see the standard "Update Now" button in your WordPress Plugins page — no need to manually download again.

### From source

Alternatively, clone directly into your plugins directory:

```bash
cd /path/to/wordpress/wp-content/plugins/
git clone https://github.com/nerveband/givewp-donorperfect-sync.git
```

> **Note:** If you install from git clone, the auto-updater will still work. When WordPress applies an update from GitHub releases, it will overwrite the cloned directory with the release version.

### After installing

1. Activate the plugin in WP Admin > Plugins
2. Go to **WP Admin > Give2DP > Settings** and configure:
   - Enter your DonorPerfect API key
   - Click **Test API Connection** to verify it works
   - Set your default GL code (e.g. `UN` for Unrestricted)
   - Set your campaign code (if applicable)
   - Click **Validate Codes** to confirm your codes exist in DonorPerfect
   - Configure gateway mappings (e.g. `stripe` → `CC`, `paypal` → `PAYPAL`)
3. Leave **Real-Time Sync OFF** until you've verified everything
4. Check the **Documentation** tab for a full usage walkthrough

### Automatic updates

This plugin checks [GitHub Releases](https://github.com/nerveband/givewp-donorperfect-sync/releases) for new versions every 6 hours. When a new release is available:

- You'll see an update notice on the **Plugins** page in WP Admin
- Click **"Update Now"** to install the latest version (same as any WordPress plugin)
- The update downloads the release zip, installs it, and reactivates the plugin automatically

No external update server or license key required — updates come directly from this public repository.

### Required DonorPerfect setup

Before syncing, ensure these sub-solicit codes exist in DonorPerfect:

- `ONETIME` — for one-time donations
- `RECURRING` — for recurring donations

If they don't exist, create them via the API or DonorPerfect admin. Via API:

```
dp_savecode @field_name='SUB_SOLICIT_CODE', @code='ONETIME', @description='One-Time Donation', ...
dp_savecode @field_name='SUB_SOLICIT_CODE', @code='RECURRING', @description='Recurring Donation', ...
```

See `tests/test-dp-api.sh` for the full 34-parameter dp_savecode call.

## Usage

### 1. Run the Match Report

Go to **Give2DP > Match Report** and click "Generate Match Report". This checks each GiveWP donor's email against DonorPerfect to show which donors will be matched vs. created as new. No data is modified.

### 2. Preview a Backfill

Go to **Give2DP > Backfill** and click "Run Preview (50 donations)". This is a dry run that shows what would happen without sending anything to DonorPerfect.

### 3. Run the Backfill

Once satisfied with the preview, click "Start Backfill" to sync historical donations in batches of 10 with a 200ms delay between each. You can stop at any time.

### 4. Enable Real-Time Sync

Go to **Give2DP > Settings** and check "Enable automatic sync". New donations will be synced to DonorPerfect as they come in.

### 5. Monitor

The **Give2DP > Dashboard** shows sync stats, and the **Sync Log** tab shows every sync attempt with status and error details.

## How it works

### Donation flow

1. **One-time donation**: GiveWP donation → find/create DP donor → create DP gift with `sub_solicit_code=ONETIME`
2. **First recurring payment**: GiveWP subscription → find/create DP donor → create DP pledge via `dp_savepledge` → create DP gift linked to pledge via `@plink` with `sub_solicit_code=RECURRING`
3. **Recurring renewal**: GiveWP renewal → find DP donor → find existing pledge mapping → create DP gift linked to pledge via `@plink`

### Donor matching

Donors are matched by email address using:
```sql
SELECT TOP 1 donor_id FROM dp WHERE email='donor@example.com'
```

If no match is found, a new donor is created via `dp_savedonor`.

### Database tables

The plugin creates two tables on activation:

- `{prefix}_gwdp_sync_log` — logs every sync attempt (donation ID, DP IDs, status, errors)
- `{prefix}_gwdp_pledge_map` — maps GiveWP subscription IDs to DP pledge IDs for renewal linking

## Testing

### Shell-based API tests

Tests all stored procedures directly against the DonorPerfect API. Creates test data, verifies it, then cleans up.

```bash
# Set your API key
export DONORPERFECT_API_KEY="your-api-key"

# Or create a .env file at ../donorperfect/.env:
# DONORPERFECT_API_KEY=your-api-key

# Run tests
./tests/test-dp-api.sh
```

Tests cover: dynamic SQL queries (dp, DPGIFT, DPCODES, JOINs), dp_donorsearch, dp_savedonor (28 params), dp_savegift (32 params), dp_savepledge (27 params), dp_savecode (34 params), pledge-linked gifts, and code verification.

### PHP plugin integration tests

Tests the plugin within the WordPress environment (classes, options, database tables, API connection, sync engine).

```bash
# Run via WP-CLI on the server
wp eval-file wp-content/plugins/givewp-donorperfect-sync/tests/test-plugin.php
```

## API Gotchas

Hard-won lessons from working with the DonorPerfect XML API:

1. **ALL parameters are required** — stored procedures need every parameter from the PDF *sample call*, not just the parameter table. The tables are sometimes incomplete. Missing params cause a misleading "user not authorized" error.

2. **Hidden parameters exist** — `dp_savecode` has 5 params (`@reciprocal`, `@mailed`, `@printing`, `@other`, `@goal`) that appear in the sample call but NOT in the parameter table. `dp_savegift` has 5 extra params (`@gift_aid_date`, `@gift_aid_amt`, `@gift_aid_eligible_g`, `@currency`, `@first_gift`).

3. **URL encoding matters** — API keys may contain `%2f` and `%2b` (already URL-encoded). Don't double-encode them. In curl, pass the API key raw in the URL and use `--data-urlencode` only for the action/SQL parameter.

4. **Null vs empty string** — use `@param=null` (no quotes) for null values, `@param=''` for empty strings. They behave differently.

5. **Pledges are records in DPGIFT** — pledges are stored in the DPGIFT table with `record_type='P'`. The pledge ID returned by `dp_savepledge` is a `gift_id`. Link payments to pledges with `@pledge_payment='Y'` and `@plink=pledge_gift_id`.

6. **`@total=0` means open-ended** — for recurring pledges without a fixed end, set `@total=0`. This means no outstanding balance is tracked (ad infinitum).

## File structure

```
givewp-donorperfect-sync/
  givewp-donorperfect-sync.php    # Main plugin file
  includes/
    class-dp-api.php              # DonorPerfect XML API client
    class-donation-sync.php       # Core sync logic, hooks, backfill
    class-admin-page.php          # WP Admin dashboard, settings, docs
    class-github-updater.php      # Auto-update from GitHub releases
  assets/
    admin.css                     # Admin page styles
    admin.js                      # Admin page AJAX handlers
  tests/
    test-dp-api.sh                # Shell-based API integration tests
    test-plugin.php               # PHP plugin integration tests (WP-CLI)
```

## Author

**Ashraf Ali** — [ashrafali.net](https://ashrafali.net)

## License

MIT
