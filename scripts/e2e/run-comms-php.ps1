# Run every lnkup-communications integration suite against wp-env (local only; never production).
# Usage: powershell -File scripts/e2e/run-comms-php.ps1
# Requires: wp-env started with lnkup-communications mapped (see .wp-env.json).
$tests = @(
  'comms-schema-test',
  'comms-engine-test',
  'comms-lifecycle-test',
  'comms-preferences-test',
  'comms-push-test',
  'comms-organizer-test',
  'comms-activation-test',
  'comms-security-test',
  'comms-recovery-test',
  'comms-partners-test'
)
$failed = @()
foreach ($t in $tests) {
  Write-Output "== $t"
  $out = & npx.cmd -y @wordpress/env@10 run cli wp eval-file "wp-content/lnkup-e2e/php/$t.php" 2>&1 | Out-String
  ($out -split "`n") | Where-Object { $_ -match '^\s+(ok|FAIL)\s|PASSED|FAILED|Fatal|Warning:|Notice:|Deprecated:' } | ForEach-Object { Write-Output $_.TrimEnd() }
  if ($out -notmatch 'ALL PASSED') { $failed += $t }
}
if ($failed.Count) {
  Write-Output "`nFAILED: $($failed -join ', ')"
  exit 1
}
Write-Output "`nAll communications PHP suites passed."
