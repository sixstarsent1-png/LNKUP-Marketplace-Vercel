#Requires -Version 5.1
<#
.SYNOPSIS
  Configure (interactive) iOS credentials, build production, submit to App Store Connect / TestFlight.

.DESCRIPTION
  Run in an interactive PowerShell / Windows Terminal (not an agent shell).
  Requires: Apple Developer Program, ASC app for com.lnkup.marketplace, eas login.

.PARAMETER SkipSubmit
  Build only; do not submit.

.PARAMETER CredentialsOnly
  Open eas credentials for iOS and exit.
#>
param(
  [switch]$SkipSubmit,
  [switch]$CredentialsOnly
)

$ErrorActionPreference = "Stop"
Set-Location (Split-Path $PSScriptRoot -Parent)

$env:EAS_BUILD_NO_EXPO_GO_WARNING = "true"

Write-Host "==> EAS whoami" -ForegroundColor Cyan
npx eas-cli whoami
if ($LASTEXITCODE -ne 0) { throw "eas login required: npx eas-cli login" }

if ($CredentialsOnly) {
  Write-Host "==> iOS credentials (interactive)" -ForegroundColor Cyan
  npx eas-cli credentials --platform ios
  exit $LASTEXITCODE
}

$localSubmit = Join-Path (Get-Location) "eas-submit.local.json"
$submitArgs = @("--platform", "ios", "--profile", "production", "--latest")
if (Test-Path $localSubmit) {
  $cfg = Get-Content $localSubmit -Raw | ConvertFrom-Json
  Write-Host "==> Using eas-submit.local.json (gitignored)" -ForegroundColor DarkGray
  if ($cfg.ascApiKeyPath -and (Test-Path $cfg.ascApiKeyPath)) {
    $submitArgs += @(
      "--asc-api-key-path", $cfg.ascApiKeyPath,
      "--asc-api-key-id", $cfg.ascApiKeyId,
      "--asc-api-key-issuer-id", $cfg.ascApiIssuerId
    )
  } elseif ($cfg.appleId -and $cfg.ascAppId -and $cfg.appleTeamId) {
    $submitArgs += @(
      "--apple-id", $cfg.appleId,
      "--asc-app-id", $cfg.ascAppId,
      "--apple-team-id", $cfg.appleTeamId
    )
  }
}

Write-Host "==> Production iOS build (interactive credentials if needed)" -ForegroundColor Cyan
npx eas-cli build --platform ios --profile production
if ($LASTEXITCODE -ne 0) { throw "eas build failed" }

if ($SkipSubmit) {
  Write-Host "Build finished. Skipping submit (--SkipSubmit)." -ForegroundColor Yellow
  exit 0
}

Write-Host "==> Submit latest iOS build to App Store Connect" -ForegroundColor Cyan
npx --yes eas-cli submit @submitArgs
if ($LASTEXITCODE -ne 0) {
  Write-Host "Non-interactive submit failed or incomplete; retrying interactiveâ€¦" -ForegroundColor Yellow
  npx eas-cli submit --platform ios --profile production --latest
}

if ($LASTEXITCODE -ne 0) { throw "eas submit failed" }
Write-Host "Done. Check App Store Connect â†’ TestFlight (processing may take 5â€“30 min)." -ForegroundColor Green

