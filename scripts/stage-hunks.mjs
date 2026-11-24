/**
 * Stage only the hunks of a file whose changed lines match one of the given markers.
 * Used when another session has uncommitted edits in the same file.
 *
 * The staged content is built from HEAD by applying the selected zero-context hunks at their
 * old-file line numbers, then written straight into the index (the working tree is untouched).
 * The result is printed as a short report; run `git diff --cached` to review before committing.
 *
 * Usage: node scripts/stage-hunks.mjs <file> <marker> [marker...]
 */
import { execFileSync } from 'node:child_process';

const [, , file, ...markers] = process.argv;
if (!file || !markers.length) {
  console.error('usage: node scripts/stage-hunks.mjs <file> <marker> [marker...]');
  process.exit(2);
}

const git = (args, input) => execFileSync('git', args, { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024, input });

const diff = git(['diff', 'HEAD', '-U0', '--no-color', '--', file]);
if (!diff.trim()) {
  console.log(`${file}: no changes`);
  process.exit(0);
}

const hunks = [];
let current = null;
for (const line of diff.split('\n')) {
  const m = /^@@ -(\d+)(?:,(\d+))? \+\d+(?:,\d+)? @@/.exec(line);
  if (m) {
    current = { head: line, oldStart: parseInt(m[1], 10), oldLen: m[2] === undefined ? 1 : parseInt(m[2], 10), removed: [], added: [] };
    hunks.push(current);
    continue;
  }
  if (!current) continue;
  if (line.startsWith('-')) current.removed.push(line.slice(1));
  else if (line.startsWith('+')) current.added.push(line.slice(1));
}

const matches = (h) => [...h.removed, ...h.added].some((l) => markers.some((m) => l.includes(m)));
const keep = hunks.filter(matches);
for (const h of hunks) console.log(`${keep.includes(h) ? 'STAGE' : 'skip '} ${h.head}`);
if (!keep.length) process.exit(0);

let base;
try {
  base = git(['show', `HEAD:${file}`]);
} catch {
  base = '';
}
const endsWithNewline = base.endsWith('\n');
const lines = base.length ? (endsWithNewline ? base.slice(0, -1) : base).split('\n') : [];

for (const h of [...keep].sort((a, b) => b.oldStart - a.oldStart)) {
  const index = h.oldLen === 0 ? h.oldStart : h.oldStart - 1;
  const actual = lines.slice(index, index + h.oldLen);
  if (actual.join('\n') !== h.removed.join('\n')) {
    console.error(`Hunk does not match HEAD: ${h.head}`);
    process.exit(1);
  }
  lines.splice(index, h.oldLen, ...h.added);
}

const content = lines.join('\n') + (endsWithNewline || !base.length ? '\n' : '');
const blob = git(['hash-object', '-w', '--stdin', `--path=${file}`], content).trim();
const mode = (() => {
  try {
    return git(['ls-files', '-s', '--', file]).split(' ')[0] || '100644';
  } catch {
    return '100644';
  }
})();
git(['update-index', '--add', '--cacheinfo', `${mode},${blob},${file}`]);
console.log(`staged ${keep.length}/${hunks.length} hunks of ${file}`);
