# DAD Payment Flow

## Launch rule

Payments activate only after a contribution value is selected or entered.

## Contribution options

Display:

- $1/day
- $365/year
- Custom Amount

Remove:

- monthly option

## Initial screen

Show:

CONTRIBUTION: $/DAY

- $1/day
- $365/year
- Custom Amount

Payment methods are visible but inactive until a value is selected or entered.

## After amount selection

Once a value exists, hide the option list.

Show only:

CONTRIBUTION:
$[amount]

DAYS COVERED: [days]
NEXT PAYMENT DATE:
[date]

## Active payment methods

Activate:

- PAY BY CARD
- E-TRANSFER
- QR CODE

Remove the COMPLETE button from the public payment flow.

## Method behavior

### Pay by Card

Opens the Square payment path using the selected amount.

### E-Transfer

Shows e-transfer instructions.

Temporary recipient:

ajfisherco@gmail.com

### QR Code

Shows or opens the payment QR code using the selected amount where supported.

## Confirmation behavior

After payment activity:

1. Send confirmation email.
2. Display thank-you page.
3. Record the contribution event.

## Launch note

This is the last launch-critical wire before public launch.

Customization comes after the working payment path is live.
