#!/usr/bin/env bash
# ==============================================================================
# PALMA'S ELITE GYM - PRODUCTION ANDROID RELEASE BUILD SCRIPT (LINUX / MACOS)
# ==============================================================================
set -euo pipefail

echo "🚀 [1/4] Applying production Capacitor configuration..."
cp capacitor.config.prod.json capacitor.config.json

echo "📱 [2/4] Syncing web assets to Android platform..."
npx cap sync android

echo "🔨 [3/4] Building production Release APK via Gradle..."
cd android
./gradlew assembleRelease

echo "🎉 [4/4] Release build completed! Output located at:"
echo "android/app/build/outputs/apk/release/app-release.apk"
