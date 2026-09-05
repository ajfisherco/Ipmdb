# E.D.G.E. article-lock seed v1

This package seeds five attributed records from the September 5, 2026 E.D.G.E. edition.

## Lock rule

A lock proves what IPMdb recorded, when it recorded it, and who made each claim. It does not prove that every claim is true.

- Store the headline, link, dates, categories, original IPMdb summary, and attribution.
- Do not copy article bodies, paywalled text, or publisher images.
- Keep the publisher, first claimant, and corroborating source separate.
- Change a record by adding a later ledger event. Do not erase the first record.

## Status keys

| Status | Plain meaning |
|---|---|
| `PRIMARY_CONFIRMED` | The record comes from the body that owns or produces the data. |
| `OFFICIAL_CLAIM_CORROBORATED` | An official made the claim and another news source reported it. |
| `MULTI_PARTY_REPORTED` | More than one named party is on the record, but the outcome is still open. |
| `REPORTED_UNRESOLVED` | The event is reported, but no final result exists yet. |
| `SELF_ACKNOWLEDGED_REVIEW_PENDING` | The subject admits the event; outside review is not complete. |

`authority_scope` is limited. A government office is primary for its own order or data, but not automatically for every conclusion drawn from it. A news service is a reporting authority, not the original authority for a government or military claim.

## Files

- `articles-2026-09-05.json`: five locked article records and their claim links.
- `source-authorities.json`: source history, type, scope, and evidence links.
- `seed.sql`: an idempotent seed for the current `ideas`, `assets`, and `ledger` tables.

## Run

Review the JSON first. Back up the database, then run:

```sh
mysql --default-character-set=utf8mb4 "$IPMDB_DATABASE" < edge/seed/v1/seed.sql
```

The SQL creates no tables and changes no existing row. It inserts only missing asset IDs and missing ledger event types for those IDs.
