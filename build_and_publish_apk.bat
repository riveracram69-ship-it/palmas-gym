@echo off
setlocal enabledelayedexpansion

echo ====================================================
echo  PALMAS ELITE GYM - COMPILE AND PUBLISH ANDROID APK
echo ====================================================
echo.

set "SCRIPT_DIR=%~dp0"
set "JAVA_HOME=C:\Users\Emman\.jdks\jbr-17.0.14"
set "PATH=%JAVA_HOME%\bin;%PATH%"

echo [1/4] Syncing web assets and plugins to Android platform...
cd /d "%SCRIPT_DIR%mobile-app"
call npx cap sync android
if %ERRORLEVEL% neq 0 (
    echo [ERROR] Capacitor sync failed!
    exit /b 1
)

echo.
echo [2/4] Compiling Android debug APK via Gradle...
cd /d "%SCRIPT_DIR%mobile-app\android"
call .\gradlew.bat assembleDebug --no-daemon
if %ERRORLEVEL% neq 0 (
    echo [ERROR] Gradle build failed!
    exit /b 1
)

echo.
echo [3/4] Publishing APK to downloads directory...
set "SOURCE_APK=%SCRIPT_DIR%mobile-app\android\app\build\outputs\apk\debug\app-debug.apk"
set "DEST_DIR=%SCRIPT_DIR%downloads"
set "DEST_APK=%DEST_DIR%\palmas-elite-gym.apk"

if not exist "%DEST_DIR%" (
    mkdir "%DEST_DIR%"
)

copy /Y "%SOURCE_APK%" "%DEST_APK%" >nul
if %ERRORLEVEL% neq 0 (
    echo [ERROR] Failed to copy APK to %DEST_APK%
    exit /b 1
)

echo.
echo [4/4] Verifying APK binary...
if exist "%DEST_APK%" (
    echo [SUCCESS] APK compiled and published successfully to:
    echo %DEST_APK%
) else (
    echo [ERROR] Destination APK not found!
    exit /b 1
)

echo.
echo ====================================================
echo  BUILD AND PUBLISH COMPLETE!
echo ====================================================
