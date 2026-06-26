# IPMdb / DAD Deployment

GitHub is the master source.

Webnames is deployment only.

## Source branch

Use `v1-rebuild` until the rebuild is reviewed and merged.

## Deploy path

Copy the contents of:

`webnames-export/ipmdb/`

into:

`httpdocs/ipmdb/`

Do not edit production files directly in Webnames.

## Required files

- `index.html`
- `css/styles.css`
- `css/desktop.css`
- `css/mobile.css`
- `js/app.js`
- `js/ipmdb.js`
- `js/dad.js`
- `js/asset.js`
- `database/schema.sql`

## Current status

This build is a front-end working foundation with local asset ID generation and email-client handoff.

The PHP backend and persistent database write layer are still pending.

## Verification

Open locally first.

Test desktop side-by-side layout.

Test mobile stacked layout.

Submit IPMdb form.

Test DAD contribution buttons.

Check browser console.

Then copy to Webnames.
