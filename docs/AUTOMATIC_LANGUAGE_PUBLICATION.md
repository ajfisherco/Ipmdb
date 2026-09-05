# Automatic language publication

IPMdb publishes each asset in the language selected by the signed-in Doer.

## Selection order

1. Saved Doer language.
2. Device language from `Accept-Language` when no Doer setting exists.
3. The Doer's saved fallback language.
4. English.
5. The published original when no requested or fallback translation exists.

Language tags use BCP 47, such as `en`, `fr-CA`, `es`, or `zh-Hant`.

## Record chain

`ORIGINAL → TRANSLATION → REVIEWER → REVISION → PUBLICATION`

The original is never overwritten. Each translation records its source publication, language, version, method, provider/model when AI assisted, confidence, reviewer, and publication status.

AI output can be stored as a draft. Rare or low-resource language publication requires review. Indigenous-language publication requires approval by a fluent community reviewer before it is marked authoritative.

## API

- `GET api/doer-language.php` returns the active language choice.
- `POST api/doer-language.php` saves the signed-in Doer's choice and writes a ledger event.
- `GET api/publication.php?asset_id=IPM-...` returns the best published version for that Doer.

The Doer setting wins over the device setting. Turning automatic publication off leaves language choice under direct Doer control.

## Installation

Back up the database, then apply `database/language-publication.sql`. No existing records are changed.
