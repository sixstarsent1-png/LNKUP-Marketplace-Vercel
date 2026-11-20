# Run a command in the wp-env cli container with npm engine noise filtered out.
# Usage: powershell -File scripts/e2e/wpe.ps1 wp option get siteurl
$noise = 'EBADENGINE|npm WARN|required: \{|current: \{|package: |RemoteException|^\s*\}$|Starting .* on the cli|Ran `'
& npx.cmd -y @wordpress/env@10 run cli @args 2>&1 | ForEach-Object { "$_" } | Where-Object { $_ -notmatch $noise }
exit $LASTEXITCODE
