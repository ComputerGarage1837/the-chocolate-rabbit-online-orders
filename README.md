# The Chocolate Rabbit Online Orders

Owner-only Android order manager for The Chocolate Rabbit WooCommerce store.

## Included

- Installable Android APK with The Chocolate Rabbit logo.
- One-time owner PIN sign-in per device. Each phone keeps its own encrypted access token; WooCommerce API keys and the PIN are never embedded in the APK.
- Order list, status filters, search, order details, payment information, private notes, and status changes.
- Full or partial WooCommerce refunds, including an explicit choice to refund through the original payment gateway.
- Full capture of eligible pre-authorized WooCommerce Square card charges through Square's gateway.
- Firebase push notifications for new orders.
- Visible **Update** button with signed APK, size, and SHA-256 verification.
- Companion WordPress/WooCommerce plugin.

## Install

1. In WordPress, install `the-chocolate-rabbit-orders-app.zip` from **Plugins → Add Plugin → Upload Plugin**, then activate it.
2. In Firebase, create an Android app with package name `ca.thechocolaterabbit.onlineorders`.
3. In WordPress, open **WooCommerce → Orders App**. Enter the Firebase API key, app ID, project ID, sender ID, and service-account JSON.
4. Install `The-Chocolate-Rabbit-Online-Orders.apk` on the owner’s Android phone.
5. In **WooCommerce → Orders App**, privately set the 4-digit owner PIN. It is stored as a hash on the site, not in this public repository.
6. Enter that PIN once on each Android device. Existing paired phones remain connected after the plugin update. Confirm Firebase and registered devices, then use **Send test notification**.

Each device receives a separate persistent token; adding a device does not disconnect another. Use **Revoke all devices** if a phone is lost or access must be reset. A shared 4-digit PIN is weak for an app that can capture and refund payments: keep it private, and change it immediately if disclosed.

## Firebase values

The four public Android values come from Firebase project settings. Create the private JSON under **Project settings → Service accounts → Generate new private key**. The private key is stored only in WordPress and must never be committed to this repository.

## Refunds

The app limits refunds to the remaining refundable order balance. If **Refund the payment through the gateway** is selected, WooCommerce asks the original payment gateway to return the money. Gateway support and credentials still control whether that succeeds. Inventory is not automatically restocked from the app.

## Square pre-authorizations

On an eligible **On hold** Square card order, tap **Capture charge through Square** to capture the full authorized amount. The button is unavailable if Square reports an expired or previously captured authorization, or if the order total differs from the authorization. The app blocks a normal status change to Processing or Completed while the authorization is uncaptured.

Build checks use a simulated gateway and do not charge the live Square account. Before relying on this in production, test a controlled authorization and verify the WooCommerce order note and Square payment status. Square's WooCommerce extension says authorizations expire after six days.

## Project folders

- `android/` — Android application source.
- `wordpress-plugin/` — companion plugin source.
- `release/latest.json` — public metadata used by the built-in updater.
- `docs/RELEASING.md` — version and signing instructions.

## Local release outputs

- `release/The-Chocolate-Rabbit-Online-Orders.apk`
- `release/the-chocolate-rabbit-orders-app.zip`

These binaries are attached to GitHub Releases instead of committed to Git.
