# SharePoint Setup

Everything the SharePoint integration needs is already built and tested
against a mocked Microsoft Graph API — see [`sharepoint.md`](sharepoint.md)
for how it works. This document is the runbook for turning it on for real:
six steps, all configuration, **no code changes**.

## STEP 1 — Get the SharePoint Site URL

Open the SharePoint site that holds the Excel files in a browser and copy
its URL, e.g.:

```
https://contoso.sharepoint.com/sites/TeamSite
```

This is the value for `SHAREPOINT_SITE_URL`. The Site ID and Drive ID are
resolved automatically from this at runtime — you never need to look
those up yourself.

## STEP 2 — Create Azure App Registration

In the [Azure Portal](https://portal.azure.com) → **Azure Active
Directory** → **App registrations** → **New registration**:

1. Give it a name (e.g. "CompanyAIChatbot SharePoint Sync").
2. Leave "Supported account types" as the default (single tenant), unless
   your organization needs otherwise.
3. No redirect URI is needed — this app never signs in a user.
4. After creation, note down from the app's **Overview** page:
   - **Application (client) ID** → `SHAREPOINT_CLIENT_ID`
   - **Directory (tenant) ID** → `SHAREPOINT_TENANT_ID`
5. Go to **Certificates & secrets** → **New client secret**. Copy the
   secret's **value** immediately (it's only shown once) →
   `SHAREPOINT_CLIENT_SECRET`.

## STEP 3 — Grant Microsoft Graph permissions

Still on the app registration, go to **API permissions** → **Add a
permission** → **Microsoft Graph** → **Application permissions** (not
Delegated — this app authenticates as itself, not as a signed-in user):

- Add **`Files.Read.All`** (or **`Sites.Read.All`** for broader access
  across sites).

Then click **Grant admin consent for [your organization]**. This step
requires a tenant administrator — without it, every request will fail
with `403 Forbidden` even though authentication itself succeeds.

## STEP 4 — Copy values into .env

Add these to your `.env` (see `.env.example` for the full block with
comments):

```
SHAREPOINT_TENANT_ID=<Directory (tenant) ID from Step 2>
SHAREPOINT_CLIENT_ID=<Application (client) ID from Step 2>
SHAREPOINT_CLIENT_SECRET=<client secret value from Step 2>
SHAREPOINT_SITE_URL=<Site URL from Step 1>
SHAREPOINT_DOCUMENT_LIBRARY="Shared Documents"
SHAREPOINT_EXCEL_FOLDER="Daily Reports"
SHAREPOINT_SYNC_SCHEDULE="0 2 * * *"
```

Only change `SHAREPOINT_DOCUMENT_LIBRARY` and `SHAREPOINT_EXCEL_FOLDER`
from their defaults if the actual library/folder names in SharePoint are
different. `SHAREPOINT_SYNC_SCHEDULE` is a cron expression — the default
runs daily at 2am.

## STEP 5 — Run:

```bash
php artisan sharepoint:test
```

This authenticates, resolves the Site ID from your URL, lists every
document library on the site, and lists the Excel files found in the
configured folder — without syncing anything. Use it to confirm the setup
above is correct before relying on the scheduled sync.

If it prints `SharePoint is not configured.`, `SHAREPOINT_SITE_URL` is
still empty. If it reports a connection failure, double-check the
tenant/client ID, the client secret, and that admin consent was granted
in Step 3.

If `SHAREPOINT_DOCUMENT_LIBRARY` doesn't match any library Graph returns,
the command will list the actual library names it found — copy the
correct one into `.env` and re-run.

## STEP 6 — Run:

```bash
php artisan sharepoint:sync-excel
```

This is the real sync: it downloads every new or changed Excel file into
`storage/app/private/sharepoint-excel/` and records each one in the
`synced_documents` MySQL table. Once Step 5 succeeds, this can also just
be left to run on its own schedule (`SHAREPOINT_SYNC_SCHEDULE`) via
Laravel's scheduler — no need to run it manually every time.
