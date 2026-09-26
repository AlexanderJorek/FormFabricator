<#
.SYNOPSIS
    A throwaway MariaDB for the WordPress integration suite, dot-sourced by build.ps1.

.DESCRIPTION
    The integration suite (tests/Integration, phpunit-integration.xml.dist) needs MySQL or MariaDB. Rather than ask
    for a database server to be installed, the build keeps a portable MariaDB of its own:

      - lives in %LOCALAPPDATA%\FormFabricator\test-db, outside the repository, so the release build, phpcs, php -l
        and make-pot never walk through it, and every clone on this machine shares one copy;
      - is downloaded once from mariadb.org and checked against the SHA-256 pinned below before anything is
        extracted (NIST SSDF PS.3/PW.4, as for vendor/pdfjs);
      - is unpacked without debug symbols (.pdb), link libraries (.lib) and the admin tools in bin/, which the build
        never runs — about 40 MB instead of 290 MB;
      - runs only for the duration of a build: bound to 127.0.0.1 on a free port, started with --no-defaults so no
        my.ini elsewhere on the machine can change it, and shut down afterwards;
      - holds nothing worth keeping: the WordPress test library drops and recreates its tables on every run.

    Dev tooling, never shipped: build.ps1's $exclude list drops this file, and the package verification rejects
    any .ps1 that reaches the archive.
#>

$TestDbVersion = '11.4.13'
$TestDbUrl     = "https://downloads.mariadb.org/rest-api/mariadb/$TestDbVersion/mariadb-$TestDbVersion-winx64.zip"
$TestDbSha256  = 'd62986d433eeebfde218560b276103831604a61e929e87f1a17f5aebd80257e2'
$TestDbHome    = Join-Path $env:LOCALAPPDATA 'FormFabricator\test-db'
$TestDbServer  = Join-Path $TestDbHome "mariadb-$TestDbVersion"
$TestDbData    = Join-Path $TestDbHome 'data'
$TestDbIniDir  = Join-Path $TestDbHome 'php-ini'
$TestDbRootPw  = 'root' # a localhost-only server that exists for the length of one build

function Test-TestDatabaseReady {
    (Test-TestDatabaseServerComplete) -and (Test-Path (Join-Path $TestDbData 'mysql'))
}

function Test-TestDatabaseServerComplete {
    foreach ($exe in @('mariadbd.exe', 'mysqld.exe', 'mariadb-install-db.exe', 'server.dll')) {
        if (-not (Test-Path (Join-Path $TestDbServer "bin\$exe"))) { return $false }
    }
    return $true
}

function Test-TestDatabaseZip {
    param([string]$ZipPath)
    (Test-Path $ZipPath) -and ((Get-FileHash -Algorithm SHA256 -Path $ZipPath).Hash.ToLowerInvariant() -eq $TestDbSha256)
}

<#
    Downloads, verifies, unpacks and initializes the server if it is not there yet. Returns $true when it is ready,
    $false when it is missing and $Offline forbids the download.
#>
function Initialize-TestDatabase {
    param([switch]$Offline)

    if (Test-TestDatabaseReady) { return $true }
    if ($Offline) { return $false }

    New-Item -ItemType Directory -Force -Path $TestDbHome | Out-Null

    # The verified zip stays until the database is initialized, so an interrupted or failed setup resumes without
    # downloading 91 MB again; a zip whose hash does not match is never used.
    $zipPath = Join-Path $TestDbHome "mariadb-$TestDbVersion-winx64.zip"
    if (-not (Test-TestDatabaseServerComplete)) {
        if (-not (Test-TestDatabaseZip -ZipPath $zipPath)) {
            Write-Host "  Downloading MariaDB $TestDbVersion for the integration suite (about 91 MB, once)..." -ForegroundColor Cyan
            [Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12
            $previousProgress = $ProgressPreference
            $ProgressPreference = 'SilentlyContinue' # Windows PowerShell 5.1's progress bar slows the download many times over
            try {
                Invoke-WebRequest -UseBasicParsing -Uri $TestDbUrl -OutFile $zipPath
            } finally {
                $ProgressPreference = $previousProgress
            }
            if (-not (Test-TestDatabaseZip -ZipPath $zipPath)) {
                Remove-Item -Force $zipPath
                throw 'MariaDB download does not match its pinned SHA-256. Nothing was extracted.'
            }
        }
        # A server folder from an interrupted unpack may be missing files; unpack it whole again.
        if (Test-Path $TestDbServer) { Remove-Item -Recurse -Force $TestDbServer }
        Expand-TestDatabaseZip -ZipPath $zipPath -Target $TestDbServer
    }

    if (-not (Test-Path (Join-Path $TestDbData 'mysql'))) {
        # A data folder left half-made by an interrupted run would make the installer refuse; start it over.
        if (Test-Path $TestDbData) { Remove-Item -Recurse -Force $TestDbData }
        Write-Host '  Initializing the test database...' -ForegroundColor Cyan
        $installer = Join-Path $TestDbServer 'bin\mariadb-install-db.exe'
        $ErrorActionPreference = 'Continue' # function scope: the installer writes progress to stderr
        $output = & $installer "--datadir=$TestDbData" "--password=$TestDbRootPw" 2>&1
        if ($LASTEXITCODE -ne 0) {
            $output | ForEach-Object { Write-Host "  $_" -ForegroundColor Red }
            throw "mariadb-install-db failed with exit code $LASTEXITCODE"
        }
    }

    $ready = Test-TestDatabaseReady
    if ($ready -and (Test-Path $zipPath)) { Remove-Item -Force $zipPath }
    return $ready
}

<#
    Unpacks the server without its debug symbols, link libraries, headers and tools, refusing any entry that would
    land outside $Target.
#>
function Expand-TestDatabaseZip {
    param([string]$ZipPath, [string]$Target)

    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $targetRoot = [IO.Path]::GetFullPath($Target).TrimEnd('\') + '\'
    $zip = [IO.Compression.ZipFile]::OpenRead($ZipPath)
    try {
        foreach ($entry in $zip.Entries) {
            if ($entry.Name -eq '') { continue } # a directory entry
            if ($entry.FullName -match '(?i)\.(pdb|lib)$' -or $entry.FullName -match '(?i)/include/') { continue }
            # From bin/ only the server (mariadbd.exe, and mysqld.exe, the name the initializer bootstraps through), its
            # initializer and the DLLs they load (the server is server.dll): the backup, dump, client and storage-engine
            # tools there are most of the archive and never run here.
            if ($entry.FullName -match '(?i)/bin/' -and $entry.Name -notmatch '(?i)^(mariadbd|mysqld|mariadb-install-db)\.exe$|\.dll$') { continue }
            # Drop the archive's own top folder (mariadb-<version>-winx64/).
            $relative = $entry.FullName.Substring($entry.FullName.IndexOf('/') + 1)
            $dest = [IO.Path]::GetFullPath((Join-Path $Target $relative))
            if (-not $dest.StartsWith($targetRoot, [StringComparison]::OrdinalIgnoreCase)) {
                throw "MariaDB archive entry escapes the target folder: $($entry.FullName)"
            }
            New-Item -ItemType Directory -Force -Path (Split-Path $dest) | Out-Null
            [IO.Compression.ZipFileExtensions]::ExtractToFile($entry, $dest, $true)
        }
    } finally {
        $zip.Dispose()
    }
}

<#
    The folder to put in PHP_INI_SCAN_DIR so every PHP process of the suite loads mysqli — including the child
    process in which the WordPress test library installs the site, which a -d flag would not reach. $null when this
    PHP already loads mysqli (loading it twice prints a warning into the test output).
#>
function Get-MysqliIniDir {
    $loaded = (php -r "echo extension_loaded('mysqli') ? 1 : 0;")
    if ($loaded -eq '1') { return $null }
    New-Item -ItemType Directory -Force -Path $TestDbIniDir | Out-Null
    Set-Content -Encoding ascii -Path (Join-Path $TestDbIniDir 'mysqli.ini') -Value 'extension=mysqli'
    return $TestDbIniDir
}

function Get-FreeTcpPort {
    $listener = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback, 0)
    $listener.Start()
    try { return ([Net.IPEndPoint]$listener.LocalEndpoint).Port } finally { $listener.Stop() }
}

<#
    Runs a PHP snippet with mysqli available; used for the two statements the build sends the server itself.
#>
function Invoke-TestDatabasePhp {
    param([string]$Code, [string]$IniDir)
    $ErrorActionPreference = 'Continue' # function scope: a refused connection prints to stderr while the server starts
    $previous = $env:PHP_INI_SCAN_DIR
    try {
        if ($IniDir) { $env:PHP_INI_SCAN_DIR = $IniDir }
        $output = php -r $Code 2>&1
        return @{ Code = $LASTEXITCODE; Output = $output }
    } finally {
        $env:PHP_INI_SCAN_DIR = $previous
    }
}

<#
    Starts the server on a free localhost port, waits until it answers, and creates the test database.
    Returns what Stop-TestDatabase needs.
#>
function Start-TestDatabase {
    param([string]$IniDir)

    Write-Host '  starting the test database ... ' -NoNewline
    $timer = [Diagnostics.Stopwatch]::StartNew()
    $port = Get-FreeTcpPort
    $log  = Join-Path $TestDbHome 'server.log'
    $arguments = @(
        '--no-defaults', # must come first: no my.ini on this machine may change this server
        "--datadir=`"$TestDbData`"",
        "--port=$port",
        '--bind-address=127.0.0.1',
        '--innodb-log-file-size=8M',   # the default 100 MB redo log is most of the data folder's size
        '--innodb-buffer-pool-size=64M',
        '--console'
    )
    $process = Start-Process -FilePath (Join-Path $TestDbServer 'bin\mariadbd.exe') -ArgumentList $arguments `
        -WindowStyle Hidden -PassThru -RedirectStandardError $log

    $connect = "`$m = @mysqli_connect('127.0.0.1', 'root', '$TestDbRootPw', '', $port); exit(`$m ? 0 : 1);"
    $deadline = (Get-Date).AddSeconds(60)
    $ready = $false
    while ((Get-Date) -lt $deadline) {
        if ($process.HasExited) { break }
        if ((Invoke-TestDatabasePhp -Code $connect -IniDir $IniDir).Code -eq 0) { $ready = $true; break }
        Start-Sleep -Milliseconds 500
    }
    if (-not $ready) {
        if (-not $process.HasExited) { Stop-Process -Id $process.Id -Force }
        Write-Host 'FAILED' -ForegroundColor Red
        Get-Content -Tail 20 $log -ErrorAction SilentlyContinue | ForEach-Object { Write-Host "  $_" -ForegroundColor Red }
        throw "The test database did not start (log: $log)"
    }

    $create = "`$m = mysqli_connect('127.0.0.1', 'root', '$TestDbRootPw', '', $port); exit(mysqli_query(`$m, 'CREATE DATABASE IF NOT EXISTS wordpress_test') ? 0 : 1);"
    if ((Invoke-TestDatabasePhp -Code $create -IniDir $IniDir).Code -ne 0) {
        Write-Host 'FAILED' -ForegroundColor Red
        Stop-TestDatabase @{ Process = $process; Port = $port; IniDir = $IniDir }
        throw 'Could not create the wordpress_test database.'
    }

    Write-Host ('ok (port {0}, {1:N0} s)' -f $port, [Math]::Max(1, $timer.Elapsed.TotalSeconds)) -ForegroundColor Green
    return @{ Process = $process; Port = $port; IniDir = $IniDir }
}

<#
    Shuts the server down cleanly (SHUTDOWN, so InnoDB needs no crash recovery next time), killing it only if it does
    not stop within 30 seconds.
#>
function Stop-TestDatabase {
    param($Server)
    if (-not $Server -or -not $Server.Process -or $Server.Process.HasExited) { return }
    Write-Host '  stopping the test database ... ' -NoNewline
    $shutdown = "`$m = @mysqli_connect('127.0.0.1', 'root', '$TestDbRootPw', '', $($Server.Port)); if (`$m) { @mysqli_query(`$m, 'SHUTDOWN'); }"
    Invoke-TestDatabasePhp -Code $shutdown -IniDir $Server.IniDir | Out-Null
    if ($Server.Process.WaitForExit(30000)) {
        Write-Host 'ok' -ForegroundColor Green
    } else {
        Stop-Process -Id $Server.Process.Id -Force
        Write-Host 'killed (it did not shut down within 30 s)' -ForegroundColor Yellow
    }
}
