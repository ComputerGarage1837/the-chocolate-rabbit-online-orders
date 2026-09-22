# Releasing an Android update

Android accepts an update only when it uses the same application ID and signing certificate as the installed APK. Preserve this local key and its password backup:

`../.private-signing/the-chocolate-rabbit-online-orders.jks`

`../.private-signing/signing.properties`

Both paths are excluded from Git. Keep an encrypted off-computer backup before distributing the first APK.

For each update:

1. Increase `versionCode` and `versionName` in `android/app/build.gradle`.
2. Build a signed release using the four `TCR_ORDERS_*` environment variables documented in that Gradle file.
3. Rename the APK to `The-Chocolate-Rabbit-Online-Orders.apk`.
4. Update `release/latest.json` with the version, exact byte size, APK SHA-256, release notes, and UTC publication time.
5. Commit and push `release/latest.json` before publishing the GitHub release.
6. Create tag `v<versionName>` and attach the APK with the exact filename above.
7. Install the existing version on a phone and use its **Update** button to verify the full upgrade path.

The updater accepts only the fixed GitHub repository, exact release URL pattern, package ID, pinned signing certificate, declared file size, and declared SHA-256.

