param(
    [string]$OutputDirectory = ""
)

$ErrorActionPreference = "Stop"

$pluginRoot = Split-Path -Parent $PSScriptRoot
$versionFile = Join-Path $pluginRoot "version.php"
if (-not (Test-Path -LiteralPath $versionFile -PathType Leaf)) {
    throw "version.php is missing."
}

$versionSource = Get-Content -LiteralPath $versionFile -Raw
$pluginMatch = [regex]::Match(
    $versionSource,
    "VIDEOS_PLUGIN_VERSION'\s*,\s*'([0-9]+\.[0-9]+\.[0-9]+)'"
)
$geeklogMatch = [regex]::Match(
    $versionSource,
    "VIDEOS_MIN_GEEKLOG_VERSION'\s*,\s*'([0-9]+\.[0-9]+\.[0-9]+)'"
)
if (-not $pluginMatch.Success -or -not $geeklogMatch.Success) {
    throw "Cannot read plugin or Geeklog version from version.php."
}

$pluginVersion = $pluginMatch.Groups[1].Value
$geeklogVersion = $geeklogMatch.Groups[1].Value
if ([string]::IsNullOrWhiteSpace($OutputDirectory)) {
    $OutputDirectory = $pluginRoot
}
$outputRoot = [System.IO.Path]::GetFullPath($OutputDirectory)
$workspaceRoot = [System.IO.Path]::GetFullPath($pluginRoot)
if (-not (Test-Path -LiteralPath $outputRoot)) {
    New-Item -ItemType Directory -Path $outputRoot | Out-Null
}

$archiveName = "videos_${pluginVersion}_${geeklogVersion}.tar.gz"
$archivePath = Join-Path $outputRoot $archiveName
$stageRoot = Join-Path $workspaceRoot ".package-stage"
$stagePlugin = Join-Path $stageRoot "videos"

$resolvedStage = [System.IO.Path]::GetFullPath($stageRoot)
if (-not $resolvedStage.StartsWith(
    $workspaceRoot + [System.IO.Path]::DirectorySeparatorChar,
    [System.StringComparison]::OrdinalIgnoreCase
)) {
    throw "Unsafe staging path."
}

if (Test-Path -LiteralPath $stageRoot) {
    Remove-Item -LiteralPath $stageRoot -Recurse -Force
}
New-Item -ItemType Directory -Path $stagePlugin | Out-Null

$excludedRootNames = @(
    ".git",
    ".gitignore",
    ".agents",
    ".codex",
    ".package-stage",
    "tools"
)

Get-ChildItem -LiteralPath $pluginRoot -Force | ForEach-Object {
    if ($excludedRootNames -contains $_.Name) {
        return
    }
    if ($_.Name -match '^videos_[0-9]+\.[0-9]+\.[0-9]+_[0-9]+\.[0-9]+\.[0-9]+(?:-r[0-9]+)?\.tar\.gz$') {
        return
    }
    Copy-Item -LiteralPath $_.FullName -Destination $stagePlugin -Recurse
}

$required = @(
    "videos/autoinstall.php",
    "videos/functions.inc",
    "videos/version.php",
    "videos/install_defaults.php",
    "videos/install_updates.php",
    "videos/language/english.php",
    "videos/public_html/index.php",
    "videos/admin/index.php"
)
foreach ($entry in $required) {
    $localEntry = Join-Path $stageRoot ($entry -replace '/', '\')
    if (-not (Test-Path -LiteralPath $localEntry)) {
        throw "Required package entry is missing: $entry"
    }
}

if (Test-Path -LiteralPath $archivePath) {
    Remove-Item -LiteralPath $archivePath -Force
}

Push-Location $stageRoot
try {
    & tar -czf $archivePath "videos"
    if ($LASTEXITCODE -ne 0) {
        throw "tar failed with exit code $LASTEXITCODE."
    }
} finally {
    Pop-Location
}

$archiveEntries = & tar -tzf $archivePath
if ($LASTEXITCODE -ne 0) {
    throw "Cannot inspect the generated archive."
}
foreach ($entry in $required) {
    if ($archiveEntries -notcontains $entry) {
        throw "Archive verification failed for: $entry"
    }
}
if ($archiveEntries | Where-Object { $_ -notmatch '^videos(?:/|$)' }) {
    throw "Archive contains an entry outside the videos/ root."
}
$unsafeEntries = $archiveEntries | Where-Object {
    $_ -match '(^|/)\.(?:gitignore|git|agents|codex)(?:/|$)' -or
    $_ -match '^videos/tools(?:/|$)' -or
    $_ -notmatch '^[A-Za-z0-9_./-]+$'
}
if ($unsafeEntries) {
    throw "Archive contains unsafe entries: $($unsafeEntries -join ', ')"
}

Remove-Item -LiteralPath $stageRoot -Recurse -Force

$hash = (Get-FileHash -LiteralPath $archivePath -Algorithm SHA256).Hash.ToLowerInvariant()
[pscustomobject]@{
    Archive = $archivePath
    PluginVersion = $pluginVersion
    GeeklogVersion = $geeklogVersion
    Sha256 = $hash
    Entries = $archiveEntries.Count
}
