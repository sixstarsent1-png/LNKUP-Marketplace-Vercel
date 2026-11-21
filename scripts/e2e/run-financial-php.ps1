# Run every financial integration test against wp-env (local only; never production).
# Usage: powershell -File scripts/e2e/run-financial-php.ps1
$tests = @(
  'financial-schema-check',
  'financial-reconciliation-test',
  'financial-sync-test',
  'financial-contract-test',
  'financial-lists-test',
  'financial-repair-test',
  'financial-cron-test',
  'financial-security-test'
)
$failed = @()
foreach ($t in $tests) {
  Write-Output "== $t"
  $out = & npx.cmd -y @wordpress/env@10 run cli wp eval-file "wp-content/lnkup-e2e/php/$t.php" 2>&1 | Out-String
  ($out -split "`n") | Where-Object { $_ -match '^\s+(ok|FAIL)\s|PASSED|FAILED|Fatal|Warning:' } | ForEach-Object { Write-Output $_.TrimEnd() }
  if ($out -notmatch 'ALL PASSED') { $failed += $t }
}
if ($failed.Count) {
  Write-Output "`nFAILED: $($failed -join ', ')"
  exit 1
}
Write-Output "`nAll financial PHP suites passed."
