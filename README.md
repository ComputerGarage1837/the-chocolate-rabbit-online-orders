# The Chocolate Rabbit Online Orders

Owner-only Android order manager for The Chocolate Rabbit WooCommerce store.

## Included

- Installable Android APK with The Chocolate Rabbit logo.
- Secure one-time pairing. WooCommerce API keys are never embedded in the APK.
- Order list, status filters, search, order details, payment information, private notes, and status changes.
- Full or partial WooCommerce refunds, including an explicit choice to refund through the original payment gateway.
- Firebase push notifications for new orders.
- Visible **Update** button with signed APK, size, and SHA-256 verification.
- Companion WordPress/WooCommerce plugin.

## Install

1. In WordPress, install `the-chocolate-rabbit-orders-app.zip` from **Plugins → Add Plugin → Upload Plugin**, then activate it.
2. In Firebase, create an Android app with package name `ca.thechocolaterabbit.onlineorders`.
3. In WordPress, open **WooCommerce → Orders App**. Enter the Firebase API key, app ID, project ID, sender ID, and service-account JSON.
4. Install `The-Chocolate-Rabbit-Online-Orders.apk` on the owner’s Android phone.
5. In **WooCommerce → Orders App**, generate a pairing code. Enter the store address and code in the app.

The code expires after ten minutes and can be used once. Generating a new owner token replaces the old one. Use **Revoke paired phone** if the phone is lost or replaced.

## Firebase values

The four public Android values come from Firebase project settings. Create the private JSON under **Project settings → Service accounts → Generate new private key**. The private key is stored only in WordPress and must never be committed to this repository.

## Refunds

The app limits refunds to the remaining refundable order balance. If **Refund the payment through the gateway** is selected, WooCommerce asks the original payment gateway to return the money. Gateway support and credentials still control whether that succeeds. Inventory is not automatically restocked from the app.

## Project folders

- `android/` — Android application source.
- `wordpress-plugin/` — companion plugin source.
- `release/latest.json` — public metadata used by the built-in updater.
- `docs/RELEASING.md` — version and signing instructions.

## Local release outputs

- `release/The-Chocolate-Rabbit-Online-Orders.apk`
- `release/the-chocolate-rabbit-orders-app.zip`

These binaries are attached to GitHub Releases instead of committed to Git.

