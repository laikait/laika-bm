<#
    Laika Bill Manager - release build.

    Produces dist\lbm-<version>.zip from the app root.

    ---------------------------------------------------------------------------
    Why this exists
    ---------------------------------------------------------------------------
    Hand-zipping the app root fails in two directions, and both only show up
    after somebody has downloaded the result:

      - too much - this machine's encryption key, database credentials, install
        lock and logs
      - too little - vendor/laikait/laika-bm is a JUNCTION, and zip tools
        disagree about those. Some follow them, some store a reparse point that
        expands to nothing, which ships an archive with no product code in it
        at all and looks completely normal locally

    So: copy to a staging tree with the junction resolved to real files, rewrite
    what describes this machine, then refuse to write an archive unless
    bin\verify-stage.php passes.

    ---------------------------------------------------------------------------
    Notes
    ---------------------------------------------------------------------------
    Windows PowerShell 5.1. No '&&', no ternary, no null-coalescing.

    The version is never passed in - it is read from LBM\Support\Version::CURRENT
    so a zip cannot be named after a version the application inside it disagrees
    with. Bump the constant, commit, then build.

    Usage:
        powershell -ExecutionPolicy Bypass -File bin\release.ps1
        powershell -ExecutionPolicy Bypass -File bin\release.ps1 -AppRoot D:\sites\cloud
#>

[CmdletBinding()]
param(
    [string] $AppRoot   = 'C:\xampp\htdocs\cloud',
    [string] $OutputDir = '',
    [switch] $AllowDirty
)

$ErrorActionPreference = 'Stop'

$RepoRoot = Split-Path -Parent $PSScriptRoot

if ($OutputDir -eq '') { $OutputDir = Join-Path $RepoRoot 'dist' }

function Write-Step  ([string] $Text) { Write-Host ""; Write-Host "==> $Text" -ForegroundColor Cyan }
function Write-Note  ([string] $Text) { Write-Host "    $Text" -ForegroundColor DarkGray }
function Write-Ok    ([string] $Text) { Write-Host "    $Text" -ForegroundColor Green }

function Stop-Build ([string] $Reason) {
    Write-Host ""
    Write-Host "  BUILD ABORTED" -ForegroundColor Red
    Write-Host "  $Reason" -ForegroundColor Red
    Write-Host ""
    exit 1
}

# ---------------------------------------------------------------------------
Write-Step "Checking the source tree"
# ---------------------------------------------------------------------------

if (-not (Test-Path (Join-Path $AppRoot 'index.php'))) {
    Stop-Build "No index.php under $AppRoot - that is not an app root."
}

# The product ships from the junction target, so the repo being built had
# better be the one wired into the app. Shipping code that was never the code
# under test is the quietest possible mistake.
$JunctionPath = Join-Path $AppRoot 'vendor\laikait\laika-bm'
$Junction = Get-Item $JunctionPath -Force -ErrorAction SilentlyContinue

if ($null -eq $Junction) {
    Stop-Build "$JunctionPath does not exist - run composer install first."
}

if ($Junction.LinkType -eq 'Junction') {
    $Target = @($Junction.Target)[0]

    if ((Resolve-Path $Target).Path.TrimEnd('\') -ne (Resolve-Path $RepoRoot).Path.TrimEnd('\')) {
        Stop-Build "The app's junction points at '$Target', not at this repo ('$RepoRoot'). You would ship code you did not build."
    }

    Write-Note "junction verified -> $Target"
} else {
    Write-Note "vendor/laikait/laika-bm is already real files (LinkType: $($Junction.LinkType))"
}

# A release nobody can reproduce from a commit is not a release.
$Dirty = & git -C $RepoRoot status --porcelain

if ($LASTEXITCODE -ne 0) { Stop-Build "git could not read $RepoRoot" }

if ($null -ne $Dirty -and $Dirty.Length -gt 0) {
    if (-not $AllowDirty) {
        Write-Host ""
        Write-Host "    Uncommitted changes in the package:" -ForegroundColor Yellow
        $Dirty | ForEach-Object { Write-Host "      $_" -ForegroundColor Yellow }
        Stop-Build "Commit them, or re-run with -AllowDirty for a throwaway build."
    }

    Write-Note "WARNING: building a dirty tree (-AllowDirty)"
}

$Sha = (& git -C $RepoRoot rev-parse HEAD).Trim()
Write-Note "laika-bm at $Sha"

# ---------------------------------------------------------------------------
Write-Step "Reading the version"
# ---------------------------------------------------------------------------

$Version = (& php (Join-Path $PSScriptRoot 'version.php') $AppRoot)

if ($LASTEXITCODE -ne 0) { Stop-Build "Could not read LBM\Support\Version::CURRENT" }

$Version = $Version.Trim()

if ($Version -notmatch '^\d+\.\d+\.\d+$') {
    Stop-Build "Version::CURRENT is '$Version', which is not MAJOR.MINOR.PATCH."
}

Write-Ok "building version $Version"

# ---------------------------------------------------------------------------
Write-Step "Staging"
# ---------------------------------------------------------------------------

$Stage = Join-Path ([System.IO.Path]::GetTempPath()) "lbm-release-$Version"

if (Test-Path $Stage) { Remove-Item $Stage -Recurse -Force }

New-Item -ItemType Directory -Path $Stage -Force | Out-Null
Write-Note "staging in $Stage"

# Directories that never ship. '.git' is bare on purpose so it matches at any
# depth, including inside vendor packages; everything else is a full path so a
# vendor package's own 'docs' directory is not collateral damage.
$ExcludeDirs = @(
    '.git',
    (Join-Path $AppRoot '.github'),
    (Join-Path $AppRoot 'docs'),
    (Join-Path $AppRoot 'lf-logs'),
    (Join-Path $AppRoot 'lf-storage\cache'),
    (Join-Path $AppRoot 'lf-storage\keys'),
    (Join-Path $AppRoot 'uploads')
)

# Files that never ship. The lf-app entries are the framework skeleton's demo
# classes - nothing references the App\ namespace anywhere - but their
# directories stay, because composer maps App\ to lf-app/.
#
# 'laika' and 'worker' are the CLI entrypoints, and they are developer tools.
# The operator path is the web wizard, a scheduled cron.php, and the update
# utility at /admin/utils/update - which runs Installer::migrate() in process
# precisely so there is nothing to shell out to. verify-stage.php asserts both
# are absent from the stage, so an edit here cannot quietly put them back.
$ExcludeFiles = @(
    (Join-Path $AppRoot '.gitignore'),
    (Join-Path $AppRoot 'composer.phar'),
    (Join-Path $AppRoot 'laika'),
    (Join-Path $AppRoot 'worker'),
    (Join-Path $AppRoot 'lf-storage\lbm\install.lock'),
    (Join-Path $AppRoot 'lf-storage\queues\jobs.json'),
    (Join-Path $AppRoot 'lf-app\Controller\HomeController.php'),
    (Join-Path $AppRoot 'lf-app\Filter\LogFilter.php'),
    (Join-Path $AppRoot 'lf-app\Job\WriteLog.php'),
    (Join-Path $AppRoot 'lf-app\Pipeline\HomePipeline.php'),
    (Join-Path $AppRoot 'lf-app\Relay\SampleRelay.php'),
    (Join-Path $AppRoot 'lf-app\Service\SampleService.php')
)

# /XJ so robocopy does not follow the junction - the package is copied as real
# files immediately afterwards, from the repo, deterministically.
$RoboArgs = @($AppRoot, $Stage, '/E', '/XJ', '/R:1', '/W:1', '/NFL', '/NDL', '/NJH', '/NJS', '/NP')
$RoboArgs += '/XD'
$RoboArgs += $ExcludeDirs
$RoboArgs += '/XF'
$RoboArgs += $ExcludeFiles

& robocopy @RoboArgs | Out-Null

# robocopy exit codes below 8 are success; 8 and above are genuine failures.
if ($LASTEXITCODE -ge 8) { Stop-Build "robocopy failed copying the app root (exit $LASTEXITCODE)" }

Write-Ok "app root copied"

# The product, as real files.
$PkgStage = Join-Path $Stage 'vendor\laikait\laika-bm'

if (Test-Path $PkgStage) { Remove-Item $PkgStage -Recurse -Force }

& robocopy $RepoRoot $PkgStage '/E' '/XJ' '/R:1' '/W:1' '/NFL' '/NDL' '/NJH' '/NJS' '/NP' '/XD' '.git' 'dist' 'bin' | Out-Null

if ($LASTEXITCODE -ge 8) { Stop-Build "robocopy failed copying laika-bm (exit $LASTEXITCODE)" }

Write-Ok "laika-bm resolved to real files"

# ---------------------------------------------------------------------------
Write-Step "Rewriting what describes this machine"
# ---------------------------------------------------------------------------

& php (Join-Path $PSScriptRoot 'stage-fixup.php') $Stage $Version $Sha

if ($LASTEXITCODE -ne 0) { Stop-Build "stage fixup failed" }

# ---------------------------------------------------------------------------
Write-Step "Verifying the stage"
# ---------------------------------------------------------------------------

& php (Join-Path $PSScriptRoot 'verify-stage.php') $Stage

if ($LASTEXITCODE -ne 0) { Stop-Build "The staged tree is not fit to ship. Nothing was written." }

# ---------------------------------------------------------------------------
Write-Step "Writing the archive"
# ---------------------------------------------------------------------------

if (-not (Test-Path $OutputDir)) { New-Item -ItemType Directory -Path $OutputDir -Force | Out-Null }

$Zip = Join-Path $OutputDir "lbm-$Version.zip"

if (Test-Path $Zip) { Remove-Item $Zip -Force }

# Not Compress-Archive: on 5.1 it is very slow across the ~5,000 files in
# vendor/ and carries a 2GB ceiling.
Add-Type -AssemblyName System.IO.Compression.FileSystem
[System.IO.Compression.ZipFile]::CreateFromDirectory(
    $Stage,
    $Zip,
    [System.IO.Compression.CompressionLevel]::Optimal,
    $false
)

$Size = [math]::Round((Get-Item $Zip).Length / 1MB, 1)
$Files = (Get-ChildItem $Stage -Recurse -File).Count

Remove-Item $Stage -Recurse -Force

Write-Host ""
Write-Ok "lbm-$Version.zip  ($Size MB, $Files files)"
Write-Note "$Zip"
Write-Note "built from laika-bm $Sha"
Write-Note "archive contents sit at the root - extract into an empty web directory"
Write-Host ""

exit 0
