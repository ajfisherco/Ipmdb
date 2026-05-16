# DAD Beta Fix Log

Status: active cleanup pass.

Date: current DAD beta correction pass.

## Problem observed

Three public-facing DAD surfaces are not yet visually unified:

1. An older AJF&Co./DAD page can still show the house-heart DAD mark and the old Pledge / Opt In language.
2. The current DAD beta page uses the newer community-heart DAD mark and the Contribute Now flow.
3. The Square payment page still shows the old circular money-hand DAD badge and exposes a One-time / Weekly selector.

This creates an inconsistent public experience.

## Locked correction

One DAD identity should be used going forward:

- community-heart DAD mark
- DAD — Dollar a Day wordmark
- headline: One dollar a day. One community at a time.
- primary CTA: Contribute Now
- Square as the primary beta contribution path
- AJF&Co. metallic emblem as the semi-discreet parent-company link near the lower continuity/footer area

## Deprecated public elements

The following should not remain as the main public beta flow:

- house-heart DAD header mark
- circular money-hand DAD badge as primary logo
- Pledge / Opt In as the main homepage CTA
- e-transfer-first homepage flow
- competing payment CTAs on the same screen

These may remain as archive/history/internal reference only.

## Website fix

All AJF&Co. build/test pages that present DAD should point to the current DAD beta page:

https://ipmdb.ajfisherco.com/dad/

Use cache-busting test links when needed:

https://ipmdb.ajfisherco.com/dad/?v=6
https://ajfisherco.com/?v=7

## Square fix required

Square is external to the GitHub site and must be corrected inside Square.

Required Square cleanup:

1. Replace the old circular money-hand DAD badge with the current community-heart DAD mark.
2. Confirm whether the live beta payment page is weekly-only or still shows One-time / Weekly.
3. If possible, create a weekly-only $7/week contribution link for beta.
4. If Square must show both One-time and Weekly, set Weekly as the intended/default contribution path in page copy.
5. Keep the Square title as: Dollar a Day.
6. Keep the Square description plain:

Support the Dollar a Day initiative to help build predictable community funding for housing, support, and local outcomes tracked through IPM.db.

## Current beta rule

The public site should show one obvious path:

Contribute Now → Square → receipt → public record / IPM.db tracking.

## Next verification checklist

Test on iPhone, MacBook, and one outside phone:

- no old DAD mark appears on the DAD beta page
- no Pledge / Opt In button appears as the main CTA
- Contribute Now opens Square
- Square uses the right logo or is flagged for manual Square replacement
- hamburger menu works
- public ledger opens
- AJF&Co. emblem link opens ajfisherco.com
- no sideways scrolling
