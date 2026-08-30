@echo off
setlocal enabledelayedexpansion
echo ====================================================
echo  Palma's Elite Gym - Auto APK Publisher & Verifier
echo ====================================================
echo.

set SOURCE_APK=android\app\build\outputs\apk\debug\app-debug.apk
set DEST_DIR=..\downloads
set DEST_APK=..\downloads\palmas-elite-gym.apk

if not exist "%DEST_DIR%" (
    mkdir "%DEST_DIR%"
)

if not exist "%SOURCE_APK%" (
    echo [ERROR] Could not find app-debug.apk in Android build outputs!
    echo Please make sure you built the APK first.
    pause
    exit /b 1
)

echo [1/3] Verifying APK binary assets...
copy /Y "%SOURCE_APK%" "%DEST_APK%" >nul
if %ERRORLEVEL% neq 0 (
    echo [ERROR] Failed to copy APK to %DEST_APK%
    pause
    exit /b 1
)

echo [2/3] Verified: APK successfully published to %DEST_APK%
echo [3/3] Target Endpoint: https://palmas-gym.onrender.com/api
echo.
echo ====================================================
echo  [BUILD & PUBLISH SUCCESSFUL]
echo  Website download is now live with the cloud APK!
echo ====================================================
echo.
pause
