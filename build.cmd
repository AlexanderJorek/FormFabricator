@echo off
rem ===========================================================================
rem  build.cmd - Windows launcher for the release build (tools/build.php)
rem
rem  The build itself is PHP, the same on every system; build.sh is this
rem  launcher's macOS/Linux twin. This one adds the menu, so the build starts
rem  from a double-click in Explorer, or from `build` in cmd or PowerShell.
rem
rem  On a fresh clone there is nothing to install first: vendor/ is gitignored,
rem  and the build runs `composer install` itself when the dev tools are
rem  absent, and downloads the integration suite's test database the first
rem  time it needs it. Menu item 3 (or `build -Setup`) does both on its own,
rem  without building.
rem
rem  Usage
rem    build                menu: full build / offline build / dev setup / quit
rem    build -y             full release build, no menu, no pause
rem    build -Setup         dev dependencies and test database, then stop
rem    build -SkipAudit     offline build (any argument skips the menu)
rem    build -y -SkipAudit  both
rem
rem  Dev tooling, never shipped: tools/build-config.php leaves this file out
rem  of the package, and the archive check rejects any .cmd that reaches it.
rem ===========================================================================

setlocal

set "BUILD=%~dp0tools\build.php"
if not exist "%BUILD%" (
    echo ERROR: tools\build.php was not found next to this launcher.
    echo        Expected: "%BUILD%"
    exit /b 1
)
where /q php || (
    echo ERROR: php is not on PATH. Install PHP 8.1 or newer and put it on PATH.
    exit /b 1
)

set "ARGS="
set "SHOWMENU=1"
set "HOLD=1"

rem Any argument at all means the caller already knows what they want: no menu
rem and no closing pause, so a scripted or CI invocation never blocks on input.
:parse
if "%~1"=="" goto dispatch
set "SHOWMENU=0"
set "HOLD=0"
if /i "%~1"=="-?" goto usage
if /i "%~1"=="/?" goto usage
if /i "%~1"=="-h" goto usage
if /i "%~1"=="--help" goto usage
if /i "%~1"=="-y" (
    shift
    goto parse
)
if /i "%~1"=="/y" (
    shift
    goto parse
)
if /i "%~1"=="-Setup" (
    set "ARGS=%ARGS% --setup"
    shift
    goto parse
)
if /i "%~1"=="-SkipAudit" (
    set "ARGS=%ARGS% --skip-audit"
    shift
    goto parse
)
set "ARGS=%ARGS% %1"
shift
goto parse

:usage
echo Usage: build [-y] [-Setup] [-SkipAudit]
echo   -y          run the full build immediately, without the menu
echo   -Setup      install this working tree's dev dependencies and test database, then stop
echo   -SkipAudit  offline build: no composer audit, no test database download
exit /b 0

:dispatch
if "%SHOWMENU%"=="0" goto run

:menu
echo.
echo   FormFabricator - release build
echo   =============================
echo     1  Full release build   (all gates, including composer audit)
echo     2  Offline build        (no network advisory check)
echo     3  Set up dev tools     (composer install + test database - first run)
echo     Q  Quit
echo.
rem choice.exe rather than `set /p`: it accepts only the keys named in /c, so a
rem typo, a stray paste or a closed stdin can never reach the branches below as a
rem value, and there is nothing to re-prompt for.
where /q choice.exe || goto nochoice
choice /c 123Q /n /m "Choice (1, 2, 3 or Q): "
rem `if errorlevel N` is "N or higher", so the keys are tested top down.
if errorlevel 4 exit /b 0
if errorlevel 3 (
    set "ARGS=--setup"
    goto run
)
if errorlevel 2 (
    set "ARGS=--skip-audit"
    goto run
)
goto run

:nochoice
echo   choice.exe is not available on this machine. Run "build -y" for a full
echo   release build, "build -SkipAudit" for an offline one, or "build -Setup"
echo   to install the dev dependencies only.
exit /b 1

:run
echo.
php "%BUILD%" %ARGS%
set "RC=%ERRORLEVEL%"
if not "%RC%"=="0" (
    echo.
    echo Build FAILED - exit code %RC%. No shippable package was produced.
)
if "%HOLD%"=="1" (
    echo.
    pause
)
exit /b %RC%
