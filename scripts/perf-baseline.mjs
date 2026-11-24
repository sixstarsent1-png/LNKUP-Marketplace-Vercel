/**
 * Lab performance snapshot for public pages: LCP, CLS, transfer size, request count, DOM size.
 * Lab numbers (no CPU/network throttling) are for before/after comparison, not field Core Web Vitals.
 *
 * Usage: node scripts/perf-baseline.mjs [baseUrl] [outJson] [paths...]
 * With no paths it measures /, one live event (from the public cards API), /markets/charlotte/ and /opportunities/.
 */
import { chromium } from '@playwright/test';
import { writeFileSync } from 'node:fs';

const [, , baseArg, outArg, ...pathArgs] = process.argv;
const base = (baseArg || 'http://localhost:8888').replace(/\/$/, '');

async function defaultPaths() {
  const paths = ['/', '/markets/charlotte/', '/opportunities/'];
  try {
    const res = await fetch(`${base}/wp-json/lnkup/v1/public/discovery/cards?set=upcoming&limit=1`);
    const json = await res.json();
    if (json.items && json.items[0]) paths.splice(1, 0, new URL(json.items[0].url).pathname + new URL(json.items[0].url).search);
  } catch {
    /* no event available */
  }
  return paths;
}

const paths = pathArgs.length ? pathArgs : await defaultPaths();
const browser = await chromium.launch();
const results = [];
try {
  for (const width of [390, 1440]) {
    for (const path of paths) {
      const context = await browser.newContext({ viewport: { width, height: width < 600 ? 844 : 900 } });
      const page = await context.newPage();
      await page.addInitScript(() => {
        window.__lcp = 0;
        window.__cls = 0;
        new PerformanceObserver((list) => {
          for (const entry of list.getEntries()) window.__lcp = entry.startTime;
        }).observe({ type: 'largest-contentful-paint', buffered: true });
        new PerformanceObserver((list) => {
          for (const entry of list.getEntries()) if (!entry.hadRecentInput) window.__cls += entry.value;
        }).observe({ type: 'layout-shift', buffered: true });
      });
      const response = await page.goto(base + path, { waitUntil: 'networkidle', timeout: 60000 });
      await page.waitForTimeout(500);
      const m = await page.evaluate(() => {
        const res = performance.getEntriesByType('resource');
        const nav = performance.getEntriesByType('navigation')[0];
        const bytes = res.reduce((sum, r) => sum + (r.transferSize || 0), 0) + (nav ? nav.transferSize : 0);
        return {
          lcp: Math.round(window.__lcp),
          cls: Math.round(window.__cls * 1000) / 1000,
          ttfb: nav ? Math.round(nav.responseStart) : 0,
          kb: Math.round(bytes / 1024),
          requests: res.length + 1,
          dom: document.getElementsByTagName('*').length,
        };
      });
      results.push({ path, width, status: response ? response.status() : 0, ...m });
      console.log(`${width}px ${path} status=${response ? response.status() : '?'} lcp=${m.lcp}ms cls=${m.cls} ttfb=${m.ttfb}ms ${m.kb}KB ${m.requests} req ${m.dom} nodes`);
      await context.close();
    }
  }
} finally {
  await browser.close();
}
if (outArg) writeFileSync(outArg, JSON.stringify({ base, at: new Date().toISOString(), results }, null, 2));
