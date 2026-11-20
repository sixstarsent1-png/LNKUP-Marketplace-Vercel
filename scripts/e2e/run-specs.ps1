# Load scripts/e2e/.env.e2e (written by seed.php) and run Playwright against wp-env.
# Usage: powershell -File scripts/e2e/run-specs.ps1 tests/e2e/vendor-security.spec.ts --project=chromium
$envFile = Join-Path $PSScriptRoot '.env.e2e'
if (-not (Test-Path $envFile)) {
  Write-Error 'Missing scripts/e2e/.env.e2e - run: npm run e2e:seed'
  exit 1
}
Get-Content $envFile | Where-Object { $_ -match '^[A-Z0-9_]+=' } | ForEach-Object {
  $name, $value = $_ -split '=', 2
  Set-Item -Path "Env:$name" -Value $value
}
# One wp-env Apache container: more than two workers causes intermittent reauth=1 logins.
$extra = @()
if (-not ($args -match '^--workers')) { $extra += '--workers=2' }
& npx.cmd playwright test @args @extra
exit $LASTEXITCODE
