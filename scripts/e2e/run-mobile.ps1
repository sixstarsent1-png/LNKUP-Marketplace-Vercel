# Run the mobile app (Expo web) Playwright specs against wp-env.
# Usage: powershell -File scripts/e2e/run-mobile.ps1 tests/mobile/vendor.spec.ts --project=iphone-15
$envFile = Join-Path $PSScriptRoot '.env.e2e'
if (-not (Test-Path $envFile)) {
  Write-Error 'Missing scripts/e2e/.env.e2e - run: npm run e2e:seed'
  exit 1
}
Get-Content $envFile | Where-Object { $_ -match '^[A-Z0-9_]+=' } | ForEach-Object {
  $name, $value = $_ -split '=', 2
  Set-Item -Path "Env:$name" -Value $value
}
Push-Location (Join-Path $PSScriptRoot '..\..')
& npx.cmd playwright test -c playwright.mobile.config.ts @args
$code = $LASTEXITCODE
Pop-Location
exit $code
