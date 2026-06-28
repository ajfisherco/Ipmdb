# IPMdb RC1 Webnames Deployment

Deploy the contents of this folder directly into:

`/httpdocs/ipmdb/`

Do not upload this folder as a nested folder.

Required final server layout:

- `/httpdocs/ipmdb/index.html`
- `/httpdocs/ipmdb/css/`
- `/httpdocs/ipmdb/js/`
- `/httpdocs/ipmdb/api/`
- `/httpdocs/ipmdb/database/`

## Server-only configuration

This release does not include `api/config.local.php`.

After deployment, restore your server copy of:

`/httpdocs/ipmdb/api/config.local.php`

Use `api/config.local.example.php` only as a template.

## First test

Open:

`https://ajfisherco.com/ipmdb/`

Then submit one test idea and confirm:

- Asset ID appears.
- Database row is created.
- Acknowledgement email is sent.
