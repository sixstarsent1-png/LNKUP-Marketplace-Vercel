import { test, expect } from '@playwright/test';

/**
 * Geographic data integrity (LNKup_Location_Service via lnkup/v1/locations/*): normalization,
 * correction suggestions and ambiguity handling are the same for every client.
 */

type Resolve = { status: string; city: string; state_code: string; suggestions: Array<{ display: string }> };

const CASES: Array<{ city: string; state: string; status: string; display: string }> = [
  { city: 'Atlanta', state: 'GE', status: 'suggested', display: 'Atlanta, GA' },
  { city: 'Atlanta', state: 'GA', status: 'verified', display: 'Atlanta, GA' },
  { city: 'Atlanta', state: 'Georgia', status: 'normalized', display: 'Atlanta, GA' },
  { city: 'atlanta ga', state: '', status: 'normalized', display: 'Atlanta, GA' },
  { city: 'Charlotte NC', state: '', status: 'normalized', display: 'Charlotte, NC' },
  { city: 'Houston Texas', state: '', status: 'normalized', display: 'Houston, TX' },
  { city: 'Washington D.C.', state: '', status: 'normalized', display: 'Washington, DC' },
  { city: 'Virginia Beach', state: 'VA', status: 'verified', display: 'Virginia Beach, VA' },
  { city: 'Columbus', state: '', status: 'ambiguous', display: 'Columbus, OH' },
  { city: 'Toronto', state: 'ON', status: 'normalized', display: 'Toronto, ON' },
];

test.describe('Location Service', () => {
  for (const c of CASES) {
    test(`${c.city}${c.state ? `, ${c.state}` : ''} resolves as ${c.status}`, async ({ request }) => {
      const country = c.city === 'Toronto' ? 'Canada' : '';
      const params = new URLSearchParams({ city: c.city, state: c.state, country });
      const response = await request.get(`/wp-json/lnkup/v1/locations/resolve?${params.toString()}`);
      expect(response.ok()).toBeTruthy();
      const data = (await response.json()) as Resolve;
      expect(data.status).toBe(c.status);
      const shown = ['suggested', 'ambiguous'].includes(data.status) ? data.suggestions[0]?.display : `${data.city}, ${data.state_code}`;
      expect(shown).toBe(c.display);
    });
  }

  test('autocomplete distinguishes cities that share a name', async ({ request }) => {
    const response = await request.get('/wp-json/lnkup/v1/locations/suggest?q=Columbus&limit=5');
    const data = (await response.json()) as { results: Array<{ display: string }> };
    const displays = data.results.map((r) => r.display);
    expect(displays).toEqual(expect.arrayContaining(['Columbus, OH', 'Columbus, GA', 'Columbus, IN']));
  });

  test('Atlanta market page lists only canonical Atlanta-metro events', async ({ page }) => {
    const response = await page.goto('/markets/atlanta/');
    test.skip(response?.status() !== 200, 'Atlanta market page not enabled');
    const cards = page.getByTestId('lnkup-market-opp-card');
    const count = await cards.count();
    for (let i = 0; i < count; i += 1) {
      await expect(cards.nth(i)).not.toContainText(/,\s*GE\b/);
    }
  });
});
