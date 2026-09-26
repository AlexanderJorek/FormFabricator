<#
.SYNOPSIS
    Builds a clean, WordPress.org-ready copy of FormFabricator into build/formfabricator/
    and zips it to build/formfabricator.zip.

.DESCRIPTION
    Copies the plugin into build/formfabricator/, then runs `composer install
    --no-dev` INSIDE that copy only. Your working vendor/ folder (with the dev
    tools like phpcs/wpcs) is never touched. Run this before every release.

    On a clone that has no vendor/ yet, the dev dependencies are installed first
    (see -Setup); there is no separate bootstrap step to remember.

.PARAMETER SkipAudit
    Skips `composer audit`, which needs network access to the advisory database, for an offline build.
    Offline also means the test database is not downloaded: when it is not set up yet, the WordPress
    integration suite is skipped with a warning instead.

.PARAMETER Setup
    Installs/refreshes the working tree's dev dependencies (composer install) and the test database
    for the integration suite (build-testdb.ps1), and stops without building. This is the first thing
    to run on a fresh clone, and the way to catch vendor/ up after a pull that changed composer.lock.

.EXAMPLE
    ./build.ps1

.EXAMPLE
    ./build.ps1 -Setup
#>

param(
    [switch]$SkipAudit,
    [switch]$Setup
)

$ErrorActionPreference = 'Stop'

$root      = $PSScriptRoot
$buildDir  = Join-Path $root 'build'
$stageDir  = Join-Path $buildDir 'formfabricator'
$zipPath   = Join-Path $buildDir 'formfabricator.zip'

# ---- Dev environment. vendor/ is gitignored (composer.json + composer.lock are the source of
# truth, vendor/pdfjs the one tracked exception), so a fresh clone has no phpcs, no wpcs and no
# security-audit sniffs -- the first release gate below would fail on a missing vendor\bin\phpcs.bat
# and say nothing about why. Restoring that is a plain `composer install`, so do it rather than
# report it: a new contributor's first run of this script should build, not hand them an errand.

# Write-Host + exit rather than throw: a missing PHP or Composer is the one failure mode that is
# reached before anything of this script has run, and a PowerShell error record buries the one
# sentence that helps under a stack trace.
function Assert-Tool {
    param([string]$Name, [string]$Hint)
    if (-not (Get-Command $Name -ErrorAction SilentlyContinue)) {
        Write-Host ""
        Write-Host "$Name is not on PATH." -ForegroundColor Red
        Write-Host "  $Hint" -ForegroundColor Red
        exit 1
    }
}

function Install-DevDependencies {
    # --ignore-platform-req=ext-gd/ext-fileinfo for the reason the staged --no-dev install further
    # down passes them: a CLI php.ini can leave both off, while every WordPress host has them.
    # Installing needs neither; the phpunit release gate does, and checks for them itself with a
    # message naming the php.ini. The extensions stay declared in composer.json for hosts.
    Write-Host "Installing dev dependencies (composer install)..." -ForegroundColor Cyan
    Push-Location $root
    $previousErrorActionPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        composer install --no-interaction --ignore-platform-req=ext-gd --ignore-platform-req=ext-fileinfo
    } finally {
        $ErrorActionPreference = $previousErrorActionPreference
        Pop-Location
    }
    if ($LASTEXITCODE -ne 0) { throw "composer install failed with exit code $LASTEXITCODE" }
}

# vendor/ can also be present but stale: a pull that changes composer.lock leaves last week's
# phpcs and wpcs in place, and a gate that passes on the wrong sniff versions is worth less than
# no gate. File timestamps cannot answer this -- a checkout rewrites them, and composer leaves
# installed.json untouched when it has nothing to do -- so compare what the lock pins against what
# composer recorded as installed. Exact, and therefore safe to act on rather than warn about.
function Get-StalePackages {
    $lock      = Get-Content -Raw -LiteralPath (Join-Path $root 'composer.lock') | ConvertFrom-Json
    $installed = Get-Content -Raw -LiteralPath $installedJson | ConvertFrom-Json
    $have      = @{}
    foreach ($package in $installed.packages) { $have[$package.name] = $package.version }
    $stale = @()
    foreach ($package in @($lock.packages) + @($lock.'packages-dev')) {
        if (-not $have.ContainsKey($package.name)) {
            $stale += "$($package.name) $($package.version) is not installed"
        } elseif ($have[$package.name] -ne $package.version) {
            $stale += "$($package.name) is $($have[$package.name]), locked at $($package.version)"
        }
    }
    return $stale
}

. (Join-Path $root 'build-testdb.ps1') # the throwaway MariaDB for the integration suite

Assert-Tool 'php' 'Install PHP 8.1 or newer and put it on PATH -- the release gates run php -l, phpcs and languages/make-pot.php.'
Assert-Tool 'composer' 'Install Composer from https://getcomposer.org/download/, then open a new shell so PATH is picked up.'
Assert-Tool 'npm' 'Install Node.js 22.13 or newer (it includes npm) from https://nodejs.org/ -- the JS test suite (tests/js/) is a release gate.'

$installedJson = Join-Path $root 'vendor\composer\installed.json'
$devToolsReady = (Test-Path (Join-Path $root 'vendor\autoload.php')) -and
                 (Test-Path (Join-Path $root 'vendor\bin\phpcs.bat')) -and
                 (Test-Path $installedJson)
if ($Setup) {
    Install-DevDependencies
    if (-not (Initialize-TestDatabase)) { throw 'The test database could not be set up.' }
    Write-Host "  Test database ready in $TestDbHome" -ForegroundColor Green
    Write-Host ""
    Write-Host "Dev environment ready. Run this script again (or build.cmd) to produce a release." -ForegroundColor Green
    exit 0
}
if (-not $devToolsReady) {
    Write-Host "No dev dependencies in vendor/ yet -- a clone starts without them." -ForegroundColor Yellow
    Install-DevDependencies
    Write-Host ""
} else {
    $stalePackages = @(Get-StalePackages)
    if ($stalePackages.Count -gt 0) {
        Write-Host "vendor/ no longer matches composer.lock:" -ForegroundColor Yellow
        $stalePackages | Select-Object -First 5 | ForEach-Object { Write-Host "  $_" -ForegroundColor Yellow }
        Install-DevDependencies
        Write-Host ""
    }
}

# ---- Release gates (NIST SSDF PW.4/PW.7): the checks CLAUDE.md documents, enforced before anything is staged, so a tree
# that fails them never produces an archive. Native tools write progress to stderr, so only their exit codes count.
# Each gate prints its name as it starts and "ok"/"FAILED" with its duration when it ends: the tools' own output is
# captured (shown only on failure), so without this line a build run from build.cmd sat on "Running release gates..."
# for minutes with no sign of progress. -Quiet is for gates run in a loop that report as one line (php -l).
function Invoke-ReleaseGate {
    param([string]$Name, [scriptblock]$Command, [switch]$Quiet)
    if (-not $Quiet) { Write-Host "  $Name ... " -NoNewline }
    $timer = [Diagnostics.Stopwatch]::StartNew()
    $previousPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $output = & $Command 2>&1
        $code   = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previousPreference
    }
    if ($code -ne 0) {
        if (-not $Quiet) { Write-Host 'FAILED' -ForegroundColor Red }
        $output | ForEach-Object { Write-Host "  $_" -ForegroundColor Red }
        throw "Release gate failed: $Name (exit code $code)"
    }
    if (-not $Quiet) { Write-Host ('ok ({0})' -f (Format-GateTime $timer.Elapsed)) -ForegroundColor Green }
}

function Format-GateTime {
    param([TimeSpan]$Elapsed)
    if ($Elapsed.TotalSeconds -lt 60) { return '{0:N0} s' -f [Math]::Max(1, $Elapsed.TotalSeconds) }
    return '{0}:{1:00} min' -f [int][Math]::Floor($Elapsed.TotalMinutes), $Elapsed.Seconds
}

Write-Host "Running release gates..." -ForegroundColor Cyan
Push-Location $root
try {
    # Matched against the path relative to the repository, never the absolute one: this repository lives under a
    # directory called .git (C:\.git\FormFabricator), so an absolute-path match excluded every file and the gate
    # checked nothing — make-pot.php prunes '.git' the same way for the same reason.
    $phpSources = Get-ChildItem -Path $root -Recurse -File -Filter '*.php' | Where-Object {
        $_.FullName.Substring($root.Length + 1) -notmatch '(^|[\\/])(vendor|build|node_modules|\.git)[\\/]'
    }
    Write-Host "  php -l ($(@($phpSources).Count) files) ... " -NoNewline
    $lintTimer = [Diagnostics.Stopwatch]::StartNew()
    try {
        foreach ($phpSource in $phpSources) {
            Invoke-ReleaseGate "php -l $($phpSource.FullName.Substring($root.Length + 1))" { php -l $phpSource.FullName } -Quiet
        }
    } catch {
        Write-Host 'FAILED' -ForegroundColor Red
        throw
    }
    Write-Host ('ok ({0})' -f (Format-GateTime $lintTimer.Elapsed)) -ForegroundColor Green
    # .phpcs.xml (picked up from the working directory) is the day-to-day PSR gate; errors fail, warnings don't.
    Invoke-ReleaseGate 'phpcs (.phpcs.xml)' { & (Join-Path $root 'vendor\bin\phpcs.bat') -q --runtime-set ignore_warnings_on_exit 1 }
    # .phpcs-security.xml: its ERRORS fail the release, as WordPress.org's Plugin Check rejects the same ones. Warnings
    # (-n hides them) stay review material, since the security-audit heuristics flag any dynamic filesystem call.
    $securityRuleset = Join-Path $root '.phpcs-security.xml'
    Invoke-ReleaseGate 'phpcs (.phpcs-security.xml, errors only)' { & (Join-Path $root 'vendor\bin\phpcs.bat') -q -n "--standard=$securityRuleset" }
    Invoke-ReleaseGate 'make-pot --check (languages/formfabricator.pot is current)' { php (Join-Path $root 'languages\make-pot.php') --check }
    # The unit and perf suites (tests/, phpunit.xml.dist). Perf holds the pathological-PDF shapes from CLAUDE.md
    # ("Scanning untrusted PDF bytes"), so a scan that turns quadratic stops the release. The integration suite follows
    # after the JS suite below.
    $missingExtensions = @('gd', 'fileinfo') | Where-Object { -not ((php -r "echo extension_loaded('$_') ? 1 : 0;") -eq '1') }
    if ($missingExtensions.Count -gt 0) {
        throw "Release gate failed: the tests need PHP's $($missingExtensions -join ' and ') extension(s), which every WordPress host has. Enable them in $((php -r 'echo php_ini_loaded_file();'))."
    }
    Invoke-ReleaseGate 'phpunit (unit + perf suites)' { & (Join-Path $root 'vendor\bin\phpunit.bat') --testsuite unit,perf --no-progress }
    # The JS suite (tests/js/): the real front.js in jsdom, against the globals Assets hands it, and checked case by case
    # against the server's condition logic. npm ci installs exactly what package-lock.json pins (the counterpart of the
    # composer.lock check above); --prefer-offline keeps a build working from npm's cache without the network.
    Invoke-ReleaseGate 'npm ci (JS test dependencies)' { npm ci --prefer-offline --no-audit --no-fund }
    Invoke-ReleaseGate 'npm test (JS suite)' { npm test }
    # The WordPress integration suite (tests/Integration, phpunit-integration.xml.dist): real WordPress from vendor/
    # against a throwaway MariaDB that exists only for this step (build-testdb.ps1), once as a single site and once as a
    # multisite network. Offline, without a database set up yet, it is skipped rather than downloaded.
    if (-not (Initialize-TestDatabase -Offline:$SkipAudit)) {
        Write-Host "  integration suite SKIPPED: no test database yet, and -SkipAudit (offline) does not download one." -ForegroundColor Yellow
        Write-Host "  Run a full build or build.ps1 -Setup once to set it up." -ForegroundColor Yellow
    } else {
        $testDbEnv = @('WP_TESTS_DB_HOST', 'WP_TESTS_DB_NAME', 'WP_TESTS_DB_USER', 'WP_TESTS_DB_PASSWORD', 'WP_MULTISITE', 'PHP_INI_SCAN_DIR')
        $savedEnv  = @{}
        foreach ($name in $testDbEnv) { $savedEnv[$name] = [Environment]::GetEnvironmentVariable($name, 'Process') }
        $testDb = Start-TestDatabase -IniDir (Get-MysqliIniDir)
        try {
            $env:WP_TESTS_DB_HOST     = "127.0.0.1:$($testDb.Port)"
            $env:WP_TESTS_DB_NAME     = 'wordpress_test'
            $env:WP_TESTS_DB_USER     = 'root'
            $env:WP_TESTS_DB_PASSWORD = $TestDbRootPw
            # Through PHP_INI_SCAN_DIR, not -d: WordPress installs the test site in a child PHP process, which needs it too.
            if ($testDb.IniDir) { $env:PHP_INI_SCAN_DIR = $testDb.IniDir }
            $integrationConfig = Join-Path $root 'phpunit-integration.xml.dist'
            $env:WP_MULTISITE = '0'
            Invoke-ReleaseGate 'phpunit integration (single site)' { & (Join-Path $root 'vendor\bin\phpunit.bat') -c $integrationConfig --no-progress }
            $env:WP_MULTISITE = '1'
            Invoke-ReleaseGate 'phpunit integration (multisite)' { & (Join-Path $root 'vendor\bin\phpunit.bat') -c $integrationConfig --no-progress }
        } finally {
            foreach ($name in $testDbEnv) { [Environment]::SetEnvironmentVariable($name, $savedEnv[$name], 'Process') }
            Stop-TestDatabase $testDb
        }
    }
    if ($SkipAudit) {
        Write-Host "  composer audit skipped (-SkipAudit)" -ForegroundColor Yellow
    } else {
        # --no-dev: the gate is about what ships. Dev tooling advisories still show up in a plain `composer audit`.
        Invoke-ReleaseGate 'composer audit (known vulnerabilities in shipped packages)' { composer audit --locked --no-dev --no-interaction }
    }
} finally {
    Pop-Location
}

Write-Host "Cleaning previous build..." -ForegroundColor Cyan
if (Test-Path $buildDir) { Remove-Item -Recurse -Force $buildDir }
New-Item -ItemType Directory -Path $stageDir | Out-Null

Write-Host "Copying plugin files..." -ForegroundColor Cyan
$exclude = @('.git', '.claude', '.vscode', '.gitignore', '.gitattributes', '.github', 'build', 'node_modules', 'vendor', '.phpcs.xml', '.phpcs-security.xml', 'build.ps1', 'build-testdb.ps1', 'build.cmd', 'CLAUDE.md', 'TESTING.md', 'tests', 'phpunit.xml.dist', 'phpunit-integration.xml.dist', '.phpunit.cache', 'package.json', 'package-lock.json')
Get-ChildItem -Path $root -Force | Where-Object { $exclude -notcontains $_.Name } | ForEach-Object {
    Copy-Item -Path $_.FullName -Destination $stageDir -Recurse -Force
}

# Dev-only files that live inside otherwise-shipped folders (not excludable by top-level name
# above) — remove them individually from the staged copy.
#
# The example field is development tooling: _ExampleField.php is a template that is deliberately
# absent from FieldRegistry::FIELD_MAP and therefore never loaded. Shipping unreachable code is
# something WordPress.org's guidelines ask plugins not to do, and it enlarges the review surface
# for no user-facing benefit. (The tests live in tests/, excluded above by top-level name.)
$nestedExclude = @(
    'includes/PDF/templates/HEADER-RENDERING.md',
    'languages/compile-mo.php',
    'languages/make-pot.php',
    'includes/Fields/_ExampleField.php',
    'assets/js/fabricator-perf-debug.js',
    'assets/js/fields/ExampleField.clientInitClickHandler.js',
    'assets/js/fields/ExampleField.clientValidationZip.js',
    'assets/css/fields/ExampleField.stylesComposite.css'
)
foreach ($rel in $nestedExclude) {
    $path = Join-Path $stageDir $rel
    if (Test-Path $path) { Remove-Item -Force $path }
}

# WordPress.org manages translations via translate.wordpress.org — .po/.mo files must not ship
# in the plugin package (the .pot source template is kept; it carries no translated content).
Get-ChildItem -Path (Join-Path $stageDir 'languages') -Include '*.po', '*.mo' -Recurse -Force -ErrorAction SilentlyContinue |
    Remove-Item -Force

Write-Host "Installing production-only dependencies (composer install --no-dev)..." -ForegroundColor Cyan
Push-Location $stageDir
try {
    # Composer writes its normal progress output to stderr. With
    # $ErrorActionPreference = 'Stop' (set at the top of this script), PowerShell
    # 5.1 wraps each such line in a NativeCommandError and aborts the script even
    # though composer itself exits 0 — a real failure is still caught below via
    # $LASTEXITCODE, which composer sets correctly regardless of this quirk.
    $previousErrorActionPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        # --ignore-platform-req=ext-gd/ext-fileinfo: your local CLI's php.ini doesn't have these
        # extensions enabled. A normal WordPress host does (both are listed as requirements), so
        # this only affects building the zip here, not the plugin at runtime.
        composer install --no-dev --optimize-autoloader --no-interaction --ignore-platform-req=ext-gd --ignore-platform-req=ext-fileinfo
    } finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }
    if ($LASTEXITCODE -ne 0) { throw "composer install failed with exit code $LASTEXITCODE" }
} finally {
    Pop-Location
}

# vendor/ above was excluded from the copy and rebuilt by composer install --no-dev,
# which only manages Composer-declared packages. vendor/pdfjs/ is placed there
# manually (pdf.js is an npm package, not installable via Composer), so it must
# be copied in separately or every release build would silently ship without it.
# Having no lock file, the copy is checked against the SHA-256 list pinned in vendor/pdfjs/VERSION (NIST SSDF PS.3/PW.4):
# an edited, swapped, half-updated or extra file stops the build instead of shipping under the documented version.
Write-Host "Verifying manually-vendored pdf.js against its pinned hashes..." -ForegroundColor Cyan
$pdfjsDir      = (Resolve-Path (Join-Path $root 'vendor\pdfjs')).ProviderPath
$pdfjsPinned   = @{}
foreach ($pin in [regex]::Matches((Get-Content -Raw -Path (Join-Path $pdfjsDir 'VERSION')), '(?m)^\s*([0-9a-fA-F]{64})\s+(\S+)\s*$')) {
    $pdfjsPinned[$pin.Groups[2].Value] = $pin.Groups[1].Value.ToLowerInvariant()
}
$pdfjsProblems = @()
foreach ($file in Get-ChildItem -Path $pdfjsDir -Recurse -File -Force | Where-Object { $_.Name -ne 'VERSION' }) {
    $rel = $file.FullName.Substring($pdfjsDir.TrimEnd('\').Length + 1).Replace('\', '/')
    if (-not $pdfjsPinned.ContainsKey($rel)) {
        $pdfjsProblems += "$rel has no pinned hash"
        continue
    }
    $actual = (Get-FileHash -Algorithm SHA256 -LiteralPath $file.FullName).Hash.ToLowerInvariant()
    if ($actual -ne $pdfjsPinned[$rel]) {
        $pdfjsProblems += "$rel is $actual, pinned $($pdfjsPinned[$rel])"
    }
}
foreach ($rel in $pdfjsPinned.Keys) {
    if (-not (Test-Path -LiteralPath (Join-Path $pdfjsDir $rel) -PathType Leaf)) {
        $pdfjsProblems += "$rel is pinned but missing"
    }
}
if ($pdfjsProblems.Count -gt 0 -or $pdfjsPinned.Count -eq 0) {
    throw "vendor/pdfjs does not match the SHA-256 list in its VERSION file: $(if ($pdfjsPinned.Count -eq 0) { 'no hashes pinned' } else { $pdfjsProblems -join '; ' })"
}
Write-Host "Copying manually-vendored pdf.js..." -ForegroundColor Cyan
Copy-Item -Path $pdfjsDir -Destination (Join-Path $stageDir 'vendor\pdfjs') -Recurse -Force

# mpdf/mpdf ships ~80 TTF/OTF font files (DejaVu, FreeFont, and many others for
# scripts like Devanagari/Khmer/Syriac/etc, ~88MB total) covering every font it
# ever might need. FormFabricator only ever *selects* one of 4 families — see the
# font_family match in includes/PDF/templates/layout.php ('dejavusans' [default],
# 'dejavuserif', 'dejavusansmono', 'freemono') — and mPDF's autoScriptToLang/
# autoLangToFont/useSubstitutions are all left at their default of false in
# Generator.php's $mpdf_config, so mPDF never auto-*switches* to a different font
# family for unsupported scripts.
#
# CORRECTION (2026-08-20): an earlier version of this comment claimed that meant
# mPDF "never touches any font file outside these 4 families" — that turned out
# to be wrong and caused a real production failure ("Cannot find TTF TrueType
# font file DejaVuSerifCondensed.ttf") the first time a submission actually
# exercised it. useSubstitutions genuinely does default to false (verified
# against vendor/mpdf/mpdf/src/Mpdf.php directly), but that's a different
# mechanism (missing-glyph fallback) from what actually fired here: mPDF's own
# built-in font-alias tables (vendor/mpdf/mpdf/src/Config/FontVariables.php,
# 'serif_fonts'/'sans_fonts') list the Condensed variant of each DejaVu family
# *before* the plain one, and that alias table is consulted independently of
# useSubstitutions/autoScriptToLang whenever mPDF resolves a font by role rather
# than by our literal $forge_font family name. Rather than chase every internal
# mPDF alias path that could reach a "trimmed away" file, keep the Condensed
# companions too — they're cheap (~2.5MB combined for both families) next to the
# ~85MB this step actually saves (which is almost entirely the CJK/Devanagari/
# Khmer/Syriac/etc script fonts FormFabricator has no path to ever request). Trimming
# ONLY in the staged release build (never the working vendor/, which a plain
# `composer install` would just restore anyway, and which stays full for local
# testing). If you add a fifth selectable PDF font, add its files to $keepFonts
# below or this step will delete them and PDF generation will fatal on "font
# file not found."
Write-Host "Trimming unused mPDF font files..." -ForegroundColor Cyan
$fontsDir = Join-Path $stageDir 'vendor\mpdf\mpdf\ttfonts'
$keepFonts = @(
    'DejaVuSans.ttf', 'DejaVuSans-Bold.ttf', 'DejaVuSans-Oblique.ttf', 'DejaVuSans-BoldOblique.ttf',
    'DejaVuSansCondensed.ttf', 'DejaVuSansCondensed-Bold.ttf', 'DejaVuSansCondensed-Oblique.ttf', 'DejaVuSansCondensed-BoldOblique.ttf',
    'DejaVuSerif.ttf', 'DejaVuSerif-Bold.ttf', 'DejaVuSerif-Italic.ttf', 'DejaVuSerif-BoldItalic.ttf',
    'DejaVuSerifCondensed.ttf', 'DejaVuSerifCondensed-Bold.ttf', 'DejaVuSerifCondensed-Italic.ttf', 'DejaVuSerifCondensed-BoldItalic.ttf',
    'DejaVuSansMono.ttf', 'DejaVuSansMono-Bold.ttf', 'DejaVuSansMono-Oblique.ttf', 'DejaVuSansMono-BoldOblique.ttf',
    'FreeMono.ttf', 'FreeMonoBold.ttf', 'FreeMonoOblique.ttf', 'FreeMonoBoldOblique.ttf',
    'DejaVuinfo.txt', 'GNUFreeFontinfo.txt'
)
if (-not (Test-Path $fontsDir)) {
    # A silent skip is the expensive failure here: if mPDF ever relocates ttfonts/, roughly 88 MB
    # of CJK/Devanagari/Khmer faces ship. The 40 MB total ceiling in the verification step would
    # catch that after the fact; failing here says why, at the step that actually went wrong.
    throw "mPDF font directory not found at $fontsDir - the trim would silently ship the full font set."
}
$before = (Get-ChildItem -Path $fontsDir -File | Measure-Object -Property Length -Sum).Sum
Get-ChildItem -Path $fontsDir -File | Where-Object { $keepFonts -notcontains $_.Name } | Remove-Item -Force
$after = (Get-ChildItem -Path $fontsDir -File | Measure-Object -Property Length -Sum).Sum
# The header comment above warns that removing a needed font makes PDF generation fatal at
# runtime with "font file not found". This turns that warning into something the build enforces.
$missingFonts = @($keepFonts | Where-Object { -not (Test-Path (Join-Path $fontsDir $_)) })
if ($missingFonts.Count -gt 0) {
    throw "fonts named in `$keepFonts are absent after the trim (upstream renamed them?): $($missingFonts -join ', ')"
}
Write-Host ("  {0:N1} MB -> {1:N1} MB" -f ($before / 1MB), ($after / 1MB)) -ForegroundColor Cyan

# Dev-only tooling bundled inside Composer packages themselves (not something
# `composer install --no-dev` strips, since they ship as regular package files, and
# `--no-dev` only controls OUR OWN require-dev, not what each dependency chooses to
# include in its own distributed files): CI workflows/issue templates, static-analysis
# (PHPStan/Psalm) configs, PHPUnit configs, a phpcs ruleset, .gitignore files, an unused
# scratch tmp/ dir (mPDF only falls back to vendor/mpdf/mpdf/tmp when no 'tempDir' is
# passed in config — Generator.php always passes its own, so this is never touched), and
# a phar-building shell script (WordPress.org's Plugin Check disallows shipping shell
# scripts) plus the build artifacts/PHP script it invoked. None of this is needed at
# runtime; none of it is excluded by composer.json's own `require-dev` (that only
# affects OUR dependency tree, not what upstream packages bundle in their own dist).
Write-Host "Removing dev-only tooling bundled inside dependencies..." -ForegroundColor Cyan
$vendorExclude = @(
    'vendor/paragonie/random_compat/build-phar.sh',
    'vendor/paragonie/random_compat/dist',
    'vendor/paragonie/random_compat/other',
    'vendor/paragonie/random_compat/psalm-autoload.php',
    'vendor/paragonie/random_compat/psalm.xml',
    'vendor/mpdf/mpdf/.github',
    'vendor/mpdf/mpdf/.gitignore',
    'vendor/mpdf/mpdf/tmp',
    'vendor/mpdf/mpdf/phpstan-baseline.neon',
    'vendor/mpdf/mpdf/phpstan.neon',
    'vendor/mpdf/mpdf/phpunit.xml',
    'vendor/mpdf/mpdf/ruleset.xml',
    'vendor/mpdf/psr-log-aware-trait/.gitignore'
)
foreach ($rel in $vendorExclude) {
    $path = Join-Path $stageDir $rel
    if (Test-Path $path) { Remove-Item -Recurse -Force $path }
}

# Hidden files and folders that upstream packages bundle (.gitattributes, .editorconfig, .php-cs-fixer.dist.php, ...) are
# never needed at runtime, and Plugin Check reports every hidden file as an error. Only vendor/ is cleaned this way; a
# hidden file in the plugin's own tree fails the verification below instead of vanishing quietly. Deepest paths first,
# so a folder's contents are gone before the folder itself.
Get-ChildItem -Path (Join-Path $stageDir 'vendor') -Recurse -Force |
    Where-Object { $_.Name -like '.*' } |
    Sort-Object { $_.FullName.Length } -Descending |
    ForEach-Object { if (Test-Path -LiteralPath $_.FullName) { Remove-Item -LiteralPath $_.FullName -Recurse -Force } }

Write-Host "Creating zip..." -ForegroundColor Cyan
if (Test-Path $zipPath) { Remove-Item -Force $zipPath }

# Deliberately NOT Compress-Archive. The Microsoft.PowerShell.Archive that ships with
# Windows PowerShell 5.1 (1.0.1.0) writes every entry name using the platform separator,
# i.e. a backslash, and ZIP APPNOTE.TXT 4.4.17.1 requires forward slashes. PHP's dirname()
# on Linux then finds no directory component in an entry name at all, so WordPress's
# unzip_file() creates no formfabricator/ folder and drops 800+ flat files -- their
# separators still embedded in the filenames -- straight into wp-content/plugins/. That
# makes the archive uninstallable on every non-Windows host, which is every host that
# matters. Building the entries by hand keeps the separator ours, not the platform's.
Add-Type -AssemblyName System.IO.Compression | Out-Null
Add-Type -AssemblyName System.IO.Compression.FileSystem | Out-Null

$sep       = [string][char]92
$prefixLen = (Split-Path $stageDir -Parent).Length + 1
$zip       = [System.IO.Compression.ZipFile]::Open($zipPath, [System.IO.Compression.ZipArchiveMode]::Create)
try {
    Get-ChildItem -Path $stageDir -Recurse -Force -File | ForEach-Object {
        $entryName = $_.FullName.Substring($prefixLen).Replace($sep, '/')
        [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
            $zip, $_.FullName, $entryName,
            [System.IO.Compression.CompressionLevel]::Optimal) | Out-Null
    }
    # Directory entries only where a directory would otherwise vanish; unzip_file()
    # recreates every other directory from the file paths.
    Get-ChildItem -Path $stageDir -Recurse -Force -Directory | Where-Object {
        -not (Get-ChildItem -Path $_.FullName -Recurse -Force -File | Select-Object -First 1)
    } | ForEach-Object {
        $zip.CreateEntry($_.FullName.Substring($prefixLen).Replace($sep, '/') + '/') | Out-Null
    }
} finally {
    $zip.Dispose()
}

# ---- Verify the artifact instead of trusting that the steps above did what they claim.
# The exclusion lists are matched against exact names, so anything new landing inside an
# otherwise-shipped folder passes them silently -- that is how an 81 MB testpdfs/ directory
# shipped undetected for three rounds, and how the separator defect above survived eleven
# source-level reviews: nothing ever looked at the output.
Write-Host "Verifying archive..." -ForegroundColor Cyan
$violations = @()

$verify = [System.IO.Compression.ZipFile]::OpenRead($zipPath)
try {
    $entryNames = @($verify.Entries | ForEach-Object { $_.FullName })
} finally {
    $verify.Dispose()
}

$badSep = @($entryNames | Where-Object { $_.Contains($sep) })
if ($badSep.Count -gt 0) {
    $violations += "$($badSep.Count) archive entries use a backslash separator (ZIP APPNOTE 4.4.17.1 requires '/'); first: $($badSep[0])"
}

$roots = @($entryNames | ForEach-Object { ($_ -split '/')[0] } | Sort-Object -Unique)
if ($roots.Count -ne 1 -or $roots[0] -ne 'formfabricator') {
    $violations += "archive must contain exactly one top-level folder 'formfabricator'; found: $($roots -join ', ')"
}

# Relative paths, normalized to '/', so nothing below has to reason about separators.
$stagedFiles = @()
Get-ChildItem -Path $stageDir -Recurse -Force -File | ForEach-Object {
    $stagedFiles += [PSCustomObject]@{
        Rel    = $_.FullName.Substring($stageDir.Length + 1).Replace($sep, '/')
        Length = $_.Length
    }
}
$ownFiles = @($stagedFiles | Where-Object { $_.Rel -notlike 'vendor/*' })

# Every staged file must appear in the archive under its own name, and the archive must contain
# nothing else. A count alone would not do: CreateEntryFromFile skipping one locked or unreadable
# file produces a short archive that satisfies every other check here.
$expected = @{}
foreach ($file in $stagedFiles) { $expected['formfabricator/' + $file.Rel] = $true }
Get-ChildItem -Path $stageDir -Recurse -Force -Directory | Where-Object {
    -not (Get-ChildItem -Path $_.FullName -Recurse -Force -File | Select-Object -First 1)
} | ForEach-Object {
    $expected['formfabricator/' + $_.FullName.Substring($stageDir.Length + 1).Replace($sep, '/') + '/'] = $true
}
$actual = @{}
foreach ($name in $entryNames) { $actual[$name] = $true }
$absent = @($expected.Keys | Where-Object { -not $actual.ContainsKey($_) })
$unexpected = @($entryNames | Where-Object { -not $expected.ContainsKey($_) })
if ($absent.Count -gt 0) {
    $violations += "$($absent.Count) staged file(s) missing from the archive; first: $($absent[0])"
}
if ($unexpected.Count -gt 0) {
    $violations += "$($unexpected.Count) archive entr(ies) match no staged file; first: $($unexpected[0])"
}

# Dev/test material anywhere in the plugin's own tree; vendor/ is upstream's business and
# is pruned separately above.
$devPattern = '(?i)(^|/)(tests?|testpdfs|fixtures|__tests__|[.]github|node_modules|[.]git|[.]vscode|[.]claude|phpunit(-[a-z]+)?[.]xml([.]dist)?|[.]phpunit[.]cache|package(-lock)?[.]json)(/|$)'
$devLeaks   = @($ownFiles | Where-Object { $_.Rel -match $devPattern })
if ($devLeaks.Count -gt 0) {
    $violations += "dev/test material in package: $(($devLeaks | Select-Object -First 5 | ForEach-Object { $_.Rel }) -join ', ')"
}

# The exclusion lists above match exact names, so anything new slips past them. Hidden files (Plugin Check rejects them),
# OS and editor debris, logs and backup copies are caught by pattern instead, anywhere in the package.
$junkPattern = '(?i)(^|/)(\.[^/]+|thumbs\.db|desktop\.ini|__macosx)(/|$)|\.(log|env|bak|swp|swo|tmp|orig|rej)$'
$junk        = @($stagedFiles | Where-Object { $_.Rel -match $junkPattern })
if ($junk.Count -gt 0) {
    $violations += "hidden, OS, editor, log or backup files in package: $(($junk | Select-Object -First 5 | ForEach-Object { $_.Rel }) -join ', ')"
}

# Executables and scripts: Plugin Check rejects them (which is why build-phar.sh is pruned from
# random_compat above). The lists that keep build.ps1 and build.cmd out match exact names, and
# an upstream package can start bundling a helper script at any release -- both slip past a name
# list, neither slips past a pattern over the finished tree.
$scriptPattern = '(?i)\.(ps1|psm1|psd1|bat|cmd|sh|bash|exe|com|msi|scr|vbs)$'
$scriptLeaks   = @($stagedFiles | Where-Object { $_.Rel -match $scriptPattern })
if ($scriptLeaks.Count -gt 0) {
    $violations += "executable or shell-script files in package: $(($scriptLeaks | Select-Object -First 5 | ForEach-Object { $_.Rel }) -join ', ')"
}

# The nested and translation exclusion lists are only believable if their targets are gone.
foreach ($rel in $nestedExclude) {
    if (Test-Path (Join-Path $stageDir $rel)) { $violations += "excluded file still present: $rel" }
}
$leftoverTranslations = @(Get-ChildItem -Path (Join-Path $stageDir 'languages') -Include '*.po', '*.mo' -Recurse -Force -ErrorAction SilentlyContinue)
if ($leftoverTranslations.Count -gt 0) {
    $violations += "$($leftoverTranslations.Count) .po/.mo files still in languages/ (translate.wordpress.org owns these)"
}

# Size ceilings are the only controls here that catch a mistake nobody predicted, so they have to
# cover the whole package: vendor/ is ~89% of it, and the step that keeps it small (the mPDF font
# trim) used to be able to no-op silently. Two ceilings rather than one, because they fail for
# different reasons -- our own tree grows when something like testpdfs/ slips in, the total grows
# when a dependency starts shipping more.
$ownBytes   = ($ownFiles | Measure-Object -Property Length -Sum).Sum
$totalBytes = ($stagedFiles | Measure-Object -Property Length -Sum).Sum
$zipBytes   = (Get-Item $zipPath).Length
if ($ownBytes -gt 12MB) {
    $violations += "plugin tree excluding vendor/ is $([math]::Round($ownBytes / 1MB, 1)) MB, over the 12 MB ceiling -- something large is shipping that probably should not be"
}
if ($totalBytes -gt 40MB) {
    $violations += "staged package is $([math]::Round($totalBytes / 1MB, 1)) MB, over the 40 MB ceiling -- check that the mPDF font trim actually ran"
}

if ($violations.Count -gt 0) {
    # Never leave a rejected archive at the path the success message advertises: the next person
    # to reach for build/formfabricator.zip would find one and have no way to tell it had failed.
    Remove-Item -Force $zipPath -ErrorAction SilentlyContinue
    Write-Host ""
    Write-Host "Build REJECTED -- this package is not shippable:" -ForegroundColor Red
    $violations | ForEach-Object { Write-Host "  - $_" -ForegroundColor Red }
    Write-Host "  (the archive has been deleted; $stageDir is left in place for inspection)" -ForegroundColor Red
    throw "Package verification failed ($($violations.Count) problem(s))."
}

# Not fatal: WordPress.org's plugin submission form has historically capped uploads around 10 MB.
# Worth knowing before the upload rather than during it, but not worth failing a build over a
# limit this script cannot verify.
if ($zipBytes -gt 9MB) {
    Write-Host ("  NOTE: archive is {0:N1} MB, close to the ~10 MB WordPress.org submission limit" -f ($zipBytes / 1MB)) -ForegroundColor Yellow
}

Write-Host ("  {0} entries, all reconciled against {1} staged files, forward-slash separators, single 'formfabricator/' root" -f $entryNames.Count, $stagedFiles.Count) -ForegroundColor DarkGray
Write-Host ("  {0} MB staged ({1} MB excluding vendor/), archive {2} MB" -f [math]::Round($totalBytes / 1MB, 1), [math]::Round($ownBytes / 1MB, 1), [math]::Round($zipBytes / 1MB, 1)) -ForegroundColor DarkGray

# ---- Software bill of materials (NIST SSDF PS.3): CycloneDX 1.5 JSON next to the zip, never inside it. Lists what the
# package ships: every Composer package in composer.lock's production set (what `install --no-dev` put into the staged
# vendor/), plus the two hand-vendored front-end libraries.
Write-Host "Writing SBOM..." -ForegroundColor Cyan
$sbomPath      = Join-Path $buildDir 'formfabricator-sbom.cdx.json'
$lock          = Get-Content -Raw -Path (Join-Path $root 'composer.lock') | ConvertFrom-Json
$pluginVersion = ([regex]::Match((Get-Content -Raw -Path (Join-Path $root 'formfabricator.php')), 'Version:\s*([0-9.]+)')).Groups[1].Value
$components    = New-Object System.Collections.ArrayList
foreach ($package in $lock.packages) {
    $component = [ordered]@{
        type     = 'library'
        name     = $package.name
        version  = $package.version
        purl     = "pkg:composer/$($package.name)@$($package.version)"
        licenses = @($package.license | ForEach-Object { [ordered]@{ license = [ordered]@{ id = $_ } } })
    }
    if ($package.dist -and $package.dist.url) {
        $component['externalReferences'] = @([ordered]@{ type = 'distribution'; url = $package.dist.url; comment = "reference $($package.dist.reference)" })
    }
    [void]$components.Add($component)
}
$pdfjsVersionText = Get-Content -Raw -Path (Join-Path $root 'vendor\pdfjs\VERSION')
$pdfjsVersion     = ([regex]::Match($pdfjsVersionText, '(?m)^pdfjs-dist\s+([0-9.]+)')).Groups[1].Value
$pdfjsComponent   = [ordered]@{
    type     = 'library'
    name     = 'pdfjs-dist'
    version  = $pdfjsVersion
    purl     = "pkg:npm/pdfjs-dist@$pdfjsVersion"
    licenses = @([ordered]@{ license = [ordered]@{ id = 'Apache-2.0' } })
}
# The npm tarball's integrity hash, recorded in VERSION; the shipped files were checked against its SHA-256 list above.
$pdfjsIntegrity = [regex]::Match($pdfjsVersionText, 'Tarball integrity:\s*sha512-([A-Za-z0-9+/=]+)').Groups[1].Value
if ($pdfjsIntegrity -ne '') {
    $pdfjsComponent['externalReferences'] = @([ordered]@{
        type   = 'distribution'
        url    = "https://registry.npmjs.org/pdfjs-dist/-/pdfjs-dist-$pdfjsVersion.tgz"
        hashes = @([ordered]@{ alg = 'SHA-512'; content = ([BitConverter]::ToString([Convert]::FromBase64String($pdfjsIntegrity)) -replace '-', '').ToLowerInvariant() })
    })
}
[void]$components.Add($pdfjsComponent)
$faVersion = ([regex]::Match((Get-Content -Raw -Path (Join-Path $root 'assets\vendor\fontawesome\css\all.min.css')), 'Font Awesome Free ([0-9.]+)')).Groups[1].Value
[void]$components.Add([ordered]@{
    type     = 'library'
    name     = '@fortawesome/fontawesome-free'
    version  = $faVersion
    purl     = "pkg:npm/%40fortawesome/fontawesome-free@$faVersion"
    licenses = @(
        [ordered]@{ license = [ordered]@{ id = 'CC-BY-4.0' } },
        [ordered]@{ license = [ordered]@{ id = 'OFL-1.1' } },
        [ordered]@{ license = [ordered]@{ id = 'MIT' } }
    )
})
$sbom = [ordered]@{
    bomFormat    = 'CycloneDX'
    specVersion  = '1.5'
    serialNumber = 'urn:uuid:' + [guid]::NewGuid().ToString()
    version      = 1
    metadata     = [ordered]@{
        timestamp = (Get-Date).ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ')
        component = [ordered]@{
            type     = 'application'
            name     = 'formfabricator'
            version  = $pluginVersion
            licenses = @([ordered]@{ license = [ordered]@{ id = 'GPL-3.0-or-later' } })
        }
    }
    components   = $components
}
# BOM-less UTF-8: Windows PowerShell 5.1's Set-Content -Encoding utf8 writes a BOM that strict JSON parsers reject.
[System.IO.File]::WriteAllText($sbomPath, ($sbom | ConvertTo-Json -Depth 10), (New-Object System.Text.UTF8Encoding $false))

Write-Host ""
Write-Host "Done. Release build: $zipPath" -ForegroundColor Green
Write-Host "SBOM (not shipped): $sbomPath" -ForegroundColor Green
Write-Host "Unpacked copy for inspection: $stageDir" -ForegroundColor Green

# Explicit, so a caller (build.cmd, CI) reads success as 0 rather than inheriting whatever
# exit code the last native tool in this script happened to leave behind.
exit 0
