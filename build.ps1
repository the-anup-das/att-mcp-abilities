<#
.SYNOPSIS
  Builds the distributable plugin zip: dist/att-mcp-abilities-<version>.zip

.DESCRIPTION
  Zips the plugin folder (att-mcp-abilities/) with that folder as the zip root,
  using forward-slash entry names so it unpacks correctly on Linux servers
  (Compress-Archive on Windows PowerShell 5.1 writes backslashes, which breaks
  WordPress uploads). Fails if the plugin header Version, the ATT_MCP_VERSION
  constant and the readme.txt Stable tag disagree. Runs on Windows PowerShell 5.1
  and PowerShell 7 (Windows, macOS, Linux).

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File .\build.ps1
#>
$ErrorActionPreference = 'Stop'
foreach ($assembly in 'System.IO.Compression', 'System.IO.Compression.FileSystem') {
    try { Add-Type -AssemblyName $assembly } catch { } # already loaded on PowerShell 7
}

$slug   = 'att-mcp-abilities'
$root   = Split-Path -Parent $MyInvocation.MyCommand.Path
$source = Join-Path $root $slug
$main   = Join-Path $source "$slug.php"

$version  = (Select-String -Path $main -Pattern '^\s*\*\s*Version:\s*(\S+)').Matches[0].Groups[1].Value
$constant = (Select-String -Path $main -Pattern "define\( 'ATT_MCP_VERSION', '([^']+)' \)").Matches[0].Groups[1].Value
$stable   = (Select-String -Path (Join-Path $source 'readme.txt') -Pattern '^Stable tag:\s*(\S+)').Matches[0].Groups[1].Value
if ($version -ne $stable -or $version -ne $constant) {
    throw "Version mismatch: header $version, ATT_MCP_VERSION $constant, readme Stable tag $stable - they must be equal."
}

# Files that never ship.
$exclude = @('.DS_Store', 'Thumbs.db', '.gitkeep')

$dist = Join-Path $root 'dist'
New-Item -ItemType Directory -Force -Path $dist | Out-Null
$zipPath = Join-Path $dist "$slug-$version.zip"
if (Test-Path -LiteralPath $zipPath) { Remove-Item -LiteralPath $zipPath -Force }

$zip = [System.IO.Compression.ZipFile]::Open($zipPath, [System.IO.Compression.ZipArchiveMode]::Create)
try {
    $count = 0
    Get-ChildItem -LiteralPath $source -Recurse -File | Where-Object { $exclude -notcontains $_.Name } | ForEach-Object {
        $relative = $_.FullName.Substring($source.Length).TrimStart('\', '/') -replace '\\', '/'
        [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $_.FullName, "$slug/$relative", [System.IO.Compression.CompressionLevel]::Optimal) | Out-Null
        $count++
    }
} finally {
    $zip.Dispose()
}

Write-Host "Built $zipPath ($count files, version $version)"
