import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';

/**
 * Load scripts/e2e/.env.e2e (written by seed.php) so a plain `npx playwright test` targets the
 * seeded wp-env and its fixture IDs. Variables already set in the shell win; a shell base URL
 * that differs from the seeded site is reported because the fixture IDs will not match it.
 */
export function loadSeedEnv(repoRoot: string): void {
  const file = join(repoRoot, 'scripts', 'e2e', '.env.e2e');
  if (!existsSync(file)) return;
  for (const line of readFileSync(file, 'utf8').split(/\r?\n/)) {
    const match = /^([A-Z0-9_]+)=(.*)$/.exec(line);
    if (!match) continue;
    const [, name, value] = match;
    if (process.env[name] === undefined) {
      process.env[name] = value;
    } else if (name === 'PLAYWRIGHT_BASE_URL' && process.env[name] !== value) {
      console.warn(
        `PLAYWRIGHT_BASE_URL=${process.env[name]} but fixtures were seeded on ${value}; fixture IDs will not match.`,
      );
    }
  }
}
