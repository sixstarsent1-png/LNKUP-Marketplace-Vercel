# One-off laptop deploy of LNKup plugin + theme to SiteGround WordPress.
# Reads credentials from environment only. Never commit secrets.
#
# Required:
#   SG_SSH_HOST, SG_SSH_PORT, SG_SSH_USER, SG_WP_ROOT
#   SG_SSH_KEY  = path to private key (preferred)
#
# Example:
#   $env:SG_SSH_HOST = "ssh.thelnkup.com"
#   $env:SG_SSH_PORT = "18765"
#   $env:SG_SSH_USER = "<from Site Tools>"
#   $env:SG_WP_ROOT  = "/home/customer/www/thelnkup.com/public_html"
#   $env:SG_SSH_KEY  = "$HOME\.ssh\lnkup_siteground"
#   .\scripts\deploy-siteground.ps1

$ErrorActionPreference = "Stop"

function Require-Env([string]$name) {
  $value = [Environment]::GetEnvironmentVariable($name)
  if ([string]::IsNullOrWhiteSpace($value)) {
    throw "Missing environment variable $name"
  }
  return $value
}

$hostName = Require-Env "SG_SSH_HOST"
$port = Require-Env "SG_SSH_PORT"
$user = Require-Env "SG_SSH_USER"
$wpRoot = (Require-Env "SG_WP_ROOT").TrimEnd("/")
$key = Require-Env "SG_SSH_KEY"

if (-not (Test-Path $key)) {
  throw "SG_SSH_KEY file not found: $key"
}

$root = Resolve-Path (Join-Path $PSScriptRoot "..")
$pluginSrc = Join-Path $root "wp-content\plugins\lnkup-marketplace"
$themeSrc = Join-Path $root "wp-content\themes\lnkup-theme"

if (-not (Test-Path $pluginSrc)) {
  throw "Plugin folder missing: $pluginSrc"
}
if (-not (Test-Path $themeSrc)) {
  throw "Theme folder missing: $themeSrc"
}

$sshTarget = "${user}@${hostName}"
$sshArgs = @("-p", $port, "-i", $key, "-o", "IdentitiesOnly=yes", "-o", "StrictHostKeyChecking=accept-new")

Write-Host "Checking remote WordPress root..."
& ssh @sshArgs $sshTarget "test -d '$wpRoot/wp-content/plugins' && test -d '$wpRoot/wp-content/themes' && echo WP_ROOT_OK"
if ($LASTEXITCODE -ne 0) {
  throw "Remote wp-content not found under SG_WP_ROOT. Confirm the path over SSH."
}

$rsync = Get-Command rsync -ErrorAction SilentlyContinue
if ($rsync) {
  Write-Host "Rsync plugin..."
  & rsync -az --delete --exclude node_modules --exclude .git --exclude tests --exclude vendor `
    -e "ssh -p $port -i `"$key`" -o IdentitiesOnly=yes" `
    "$pluginSrc/" "${sshTarget}:${wpRoot}/wp-content/plugins/lnkup-marketplace/"
  if ($LASTEXITCODE -ne 0) { throw "Plugin rsync failed" }

  Write-Host "Rsync theme..."
  & rsync -az --delete --exclude node_modules --exclude .git --exclude tests `
    -e "ssh -p $port -i `"$key`" -o IdentitiesOnly=yes" `
    "$themeSrc/" "${sshTarget}:${wpRoot}/wp-content/themes/lnkup-theme/"
  if ($LASTEXITCODE -ne 0) { throw "Theme rsync failed" }
} else {
  Write-Host "rsync not found; using scp (does not delete leftover remote files)."
  $scpArgs = @("-P", $port, "-i", $key, "-o", "IdentitiesOnly=yes", "-o", "StrictHostKeyChecking=accept-new", "-r")
  & ssh @sshArgs $sshTarget "mkdir -p '$wpRoot/wp-content/plugins/lnkup-marketplace' '$wpRoot/wp-content/themes/lnkup-theme'"
  & scp @scpArgs "$pluginSrc\*" "${sshTarget}:${wpRoot}/wp-content/plugins/lnkup-marketplace/"
  if ($LASTEXITCODE -ne 0) { throw "Plugin scp failed" }
  & scp @scpArgs "$themeSrc\*" "${sshTarget}:${wpRoot}/wp-content/themes/lnkup-theme/"
  if ($LASTEXITCODE -ne 0) { throw "Theme scp failed" }
}

Write-Host "composer install --no-dev on server..."
& ssh @sshArgs $sshTarget "cd '$wpRoot/wp-content/plugins/lnkup-marketplace' && composer install --no-dev --optimize-autoloader"
if ($LASTEXITCODE -ne 0) {
  throw "Remote composer install failed"
}

Write-Host "SiteGround WordPress deploy finished."
