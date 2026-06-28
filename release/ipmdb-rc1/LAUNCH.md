# IPMdb RC1 Launch

## Launch Definition

IPMdb RC1 is launch-ready when a visitor can enter an idea, receive an Asset ID, create a database record, and receive an acknowledgement email without administrator intervention.

## Included

- Fullscreen IPMdb and DAD app shell
- IPMdb idea submission
- DAD contribution actions
- PHP backend
- MariaDB schema
- Deployment instructions

## Excluded

- `api/config.local.php`
- Passwords
- Private credentials

## Validation

Before public use, verify:

- `/ipmdb/` loads.
- CSS files load.
- JavaScript files load.
- DAD buttons work.
- IPMdb submission works.
- Asset ID is generated.
- Database rows are created.
- Acknowledgement email is received.
- No placeholder text is visible.
- No nested deployment folder remains.
