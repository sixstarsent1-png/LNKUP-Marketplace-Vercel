/**
 * Generate responsive WebP siblings for theme JPGs (name-640w.webp, name-1280w.webp, name-1920w.webp).
 * lnkup_public_image() serves them through <picture> when present; the original JPG stays the fallback.
 *
 * Usage: npm run images
 */
import sharp from 'sharp';
import { readdirSync, statSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', 'assets', 'images');
const jobs = [
  { dir: 'marketing', widths: [640, 1280, 1920], quality: 72 },
  { dir: 'events', widths: [640, 1280], quality: 74 },
];

for (const job of jobs) {
  const folder = join(root, job.dir);
  for (const file of readdirSync(folder)) {
    if (!/\.jpe?g$/i.test(file)) continue;
    const source = join(folder, file);
    const base = source.replace(/\.jpe?g$/i, '');
    const meta = await sharp(source).metadata();
    for (const width of job.widths) {
      if (meta.width && width > meta.width) continue;
      const target = `${base}-${width}w.webp`;
      await sharp(source).resize({ width, withoutEnlargement: true }).webp({ quality: job.quality, effort: 5 }).toFile(target);
      console.log(`${job.dir}/${file} -> ${width}w ${(statSync(target).size / 1024).toFixed(0)} KB`);
    }
  }
}
