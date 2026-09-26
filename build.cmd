@echo off
rem ===========================================================================
rem  build.cmd - launcher for build.ps1
rem
rem  Windows blocks .\build.ps1 out of the box: the default execution policy
rem  (Restricted, or AllSigned on a managed machine) refuses unsigned local
rem  scripts, which is why the build otherwise has to be started as
rem  `powershell -ExecutionPolicy Bypass -File .\build.ps1`. This launcher
rem  passes that flag itself, for this one process only - the machine's policy
rem  is read, never written - so the release build starts from a double-click
rem  in Explorer, or from `build` in cmd or PowerShell.
rem
rem  On a fresh clone there is nothing to install first: vendor/ is gitignored,
rem  and build.ps1 runs `composer install` itself when the dev tools are absent,
rem  and downloads the integration suite's test database (build-testdb.ps1) the
rem  first time it needs it. Menu item 3 (or `build -Setup`) does both on its
rem  own, without building.
rem
rem  Usage
rem    build                menu: full build / offline build / dev setup / quit
rem    build -y             full release build, no menu, no pause
rem    build -Setup         composer install for the working tree, then stop
rem    build -SkipAudit     forwarded to build.ps1 (any argument skips the menu)
rem    build -y -SkipAudit  both
rem
rem  powershell.exe (Windows PowerShell 5.1) on purpose, not pwsh.exe: build.ps1
rem  is written against 5.1 behaviour - see its notes on Compress-Archive entry
rem  separators and on Set-Content's UTF-8 BOM.
rem
rem  Dev tooling, never shipped: build.ps1's $exclude list drops this file from
rem  the staged copy, and its archive verification rejects any .cmd that reaches
rem  the package regardless.
rem ===========================================================================

setlocal

set "PS1=%~dp0build.ps1"
if not exist "%PS1%" (
    echo ERROR: build.ps1 was not found next to this launcher.
    echo        Expected: "%PS1%"
    exit /b 1
)

set "PSARGS="
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
set "PSARGS=%PSARGS% %1"
shift
goto parse

:usage
echo Usage: build [-y] [-Setup] [-SkipAudit]
echo   -y          run the full build immediately, without the menu
echo   -Setup      install this working tree's dev dependencies, then stop
echo   -SkipAudit  forwarded to build.ps1: skip the networked composer audit
exit /b 0

:dispatch
if "%SHOWMENU%"=="0" goto run

:menu
echo.
echo   FormFabricator - release build
echo   =============================
echo     1  Full release build   (all gates, including composer audit)
echo     2  Offline build        (-SkipAudit: no network advisory check)
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
    set "PSARGS=-Setup"
    goto run
)
if errorlevel 2 (
    set "PSARGS=-SkipAudit"
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
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%PS1%" %PSARGS%
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
