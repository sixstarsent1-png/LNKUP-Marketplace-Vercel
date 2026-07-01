# Build a WordPress-ready LNKup Marketplace plugin zip.
# Output: dist/lnkup-marketplace.zip with lnkup-marketplace/ as the zip root folder.

$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent $PSScriptRoot
$pluginDir = Join-Path $repoRoot 'wp-content\plugins\lnkup-marketplace'
$distDir = Join-Path $repoRoot 'dist'
$zipPath = Join-Path $distDir 'lnkup-marketplace.zip'

if (-not (Test-Path (Join-Path $pluginDir 'lnkup-marketplace.php'))) {
    Write-Error "Plugin main file not found at $pluginDir\lnkup-marketplace.php"
}

if (-not (Test-Path $distDir)) {
    New-Item -ItemType Directory -Path $distDir | Out-Null
}

if (Test-Path $zipPath) {
    Remove-Item $zipPath -Force
}

$staging = Join-Path $env:TEMP ("lnkup-marketplace-" + [guid]::NewGuid().ToString())
New-Item -ItemType Directory -Path $staging | Out-Null

try {
    $target = Join-Path $staging 'lnkup-marketplace'
    New-Item -ItemType Directory -Path $target | Out-Null

    $excludeDirs = @('.git', 'node_modules', 'tests', '.cursor')
    Get-ChildItem -Path $pluginDir -Force | ForEach-Object {
        if ($excludeDirs -contains $_.Name) {
            return
        }
        Copy-Item -Path $_.FullName -Destination (Join-Path $target $_.Name) -Recurse -Force
    }

    Compress-Archive -Path $target -DestinationPath $zipPath -CompressionLevel Optimal
    Write-Host "Created $zipPath"
    Write-Host ""
    Write-Host "Remote deploy steps:"
    Write-Host "  1. Plugins -> Deactivate -> Delete old LNKup Marketplace"
    Write-Host "  2. Plugins -> Add New -> Upload Plugin -> select dist/lnkup-marketplace.zip"
    Write-Host "  3. Activate plugin, then hard-refresh organizer-dashboard (Ctrl+Shift+R)"
    Write-Host "  4. DevTools Network: confirm organizer-command.js and organizer-crm.css use ?ver=12.1.2"
    Write-Host "Upload this zip via Plugins -> Add New -> Upload Plugin in WordPress."
}
finally {
    if (Test-Path $staging) {
        Remove-Item $staging -Recurse -Force
    }
}
