@echo off
echo ====================================================
echo  Palma's Elite Gym - Auto APK Publisher to Website
echo ====================================================
echo.

set SOURCE_APK=android\app\build\outputs\apk\debug\app-debug.apk
set DEST_DIR=..\downloads
set DEST_APK=..\downloads\palmas-elite-gym.apk

if not exist "%DEST_DIR%" (
    mkdir "%DEST_DIR%"
)

if exist "%SOURCE_APK%" (
    copy /Y "%SOURCE_APK%" "%DEST_APK%" >nul
    echo [SUCCESS] APK has been published to website downloads!
    echo Location: %DEST_APK%
    echo.
    echo Members can now download it at:
    echo http://localhost/gym/download.php
    echo.
) else (
    echo [ERROR] Could not find app-debug.apk in Android build outputs!
    echo Please make sure you built the APK first in Android Studio:
    echo 1. Open Android Studio
    echo 2. Click Build -^> Build Bundle(s) / APK(s) -^> Build APK(s)
    echo.
)

pause
