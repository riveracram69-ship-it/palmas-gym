@echo off
rem ==============================================================================
rem PALMA'S ELITE GYM - PRODUCTION ANDROID RELEASE BUILD SCRIPT
rem ==============================================================================

echo [1/4] Copying production Capacitor configuration...
copy /Y capacitor.config.prod.json capacitor.config.json >nul

echo [2/4] Syncing web assets to Android platform...
call npx cap sync android

echo [3/4] Building signed Release APK/AAB via Gradle...
cd android
call gradlew.bat assembleRelease

if %ERRORLEVEL% equ 0 (
    echo.
    echo ==============================================================================
    echo [SUCCESS] Production Release APK created successfully!
    echo Output: android\app\build\outputs\apk\release\app-release.apk
    echo ==============================================================================
) else (
    echo.
    echo [ERROR] Gradle release build failed. Please verify Android SDK and keystore configuration.
)
cd ..
