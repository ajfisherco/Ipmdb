# Deployment

## Target

Publish the static intro build from `/site` to the live web folder.

Primary target:

`ajfisherco.com/ipmdb/`

Future target:

`ipmdb.ai`

## Files to deploy

Copy these files from `/site`:

- `index.html`
- `styles.css`

## Plesk path

Open Plesk.

Open Files.

Open `httpdocs`.

Create or open `ipmdb`.

Upload `index.html` and `styles.css` into `httpdocs/ipmdb/`.

## Test URLs

- `https://ajfisherco.com/ipmdb/`
- `https://ajfisherco.com/ipmdb/index.html`

## Launch test

- [ ] Page loads without 404.
- [ ] Desktop width fills most of the screen.
- [ ] iPhone view stacks cleanly.
- [ ] GitHub ledger button opens the repository issues.
- [ ] AJF & Co. back button works.
- [ ] Graphics appear on every card.
- [ ] No missing assets.

## Rollback

If the page fails, restore the previous `index.html` and `styles.css` in `httpdocs/ipmdb/`.
