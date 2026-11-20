import { test, expect, type Page, type Locator } from '@playwright/test';

const vendorUser = process.env.PLAYWRIGHT_VENDOR_USER;
const vendorPass = process.env.PLAYWRIGHT_VENDOR_PASS;
const organizerUser = process.env.PLAYWRIGHT_ORGANIZER_USER;
const organizerPass = process.env.PLAYWRIGHT_ORGANIZER_PASS;

const PNG_1X1 = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
  'base64'
);

/**
 * Log into WordPress with the given credentials.
 */
async function login(page: Page, user: string, pass: string) {
  await page.goto('/wp-login.php');
  await page.getByLabel(/username or email/i).fill(user);
  await page.getByLabel(/^password$/i).fill(pass);
  await page.getByRole('button', { name: /log in/i }).click();
  await expect(page).not.toHaveURL(/wp-login/);
}

/**
 * Upload a file through an image upload control and wait for the upload request to finish.
 */
async function uploadInto(page: Page, control: Locator, file: { name: string; mimeType: string; buffer: Buffer }) {
  const chooser = page.waitForEvent('filechooser');
  await control.getByRole('button', { name: /^upload|^add photos/i }).click();
  const fileChooser = await chooser;
  const response = page.waitForResponse((res) => res.url().includes('admin-ajax.php') && res.request().method() === 'POST');
  await fileChooser.setFiles(file);
  return response;
}

test.describe('Organizer profile images', () => {
  test.beforeEach(async ({ page }) => {
    test.skip(!organizerUser || !organizerPass, 'Set PLAYWRIGHT_ORGANIZER_USER and PLAYWRIGHT_ORGANIZER_PASS');
    await login(page, organizerUser!, organizerPass!);
  });

  test('Profile page shows the form below the sticky top bar', async ({ page }) => {
    await page.goto('/organizer-dashboard/?view=profile');
    await expect(page.getByRole('heading', { level: 1, name: 'Profile' })).toBeVisible();
    const name = page.getByLabel('Organization name');
    await expect(name).toBeVisible();
    const bar = page.locator('.lnkup-workspace-bar').first();
    if (await bar.count()) {
      const barBox = await bar.boundingBox();
      const fieldBox = await name.boundingBox();
      expect(fieldBox && barBox ? fieldBox.y >= barBox.y + barBox.height : true, 'form should not sit under the top bar').toBe(true);
    }
  });

  test('old profile=edit links land on the Profile page', async ({ page }) => {
    await page.goto('/organizer-dashboard/?view=events&profile=edit');
    await expect(page).toHaveURL(/view=profile/);
  });

  test('uploading a logo previews it and survives save and reload', async ({ page }) => {
    await page.goto('/organizer-dashboard/?view=profile');
    const logo = page.getByTestId('lnkup-organizer-logo-upload');
    const response = await uploadInto(page, logo, { name: 'logo.png', mimeType: 'image/png', buffer: PNG_1X1 });
    expect(response.ok()).toBe(true);
    await expect(logo.getByRole('img')).toBeVisible();
    const uploadedId = await page.locator('#lnkup-organizer-logo-id').inputValue();
    expect(Number(uploadedId)).toBeGreaterThan(0);

    await page.getByRole('button', { name: 'Save profile' }).click();
    await expect(page.getByText(/saved|updated/i).first()).toBeVisible();
    await page.reload();
    await expect(page.locator('#lnkup-organizer-logo-id')).toHaveValue(uploadedId);
  });

  test('a non-image file shows an inline error and changes nothing', async ({ page }) => {
    await page.goto('/organizer-dashboard/?view=profile');
    const cover = page.getByTestId('lnkup-organizer-cover-upload');
    const before = await page.locator('#lnkup-organizer-cover-id').inputValue();
    const chooser = page.waitForEvent('filechooser');
    await cover.getByRole('button', { name: /upload cover/i }).click();
    await (await chooser).setFiles({ name: 'notes.txt', mimeType: 'text/plain', buffer: Buffer.from('not an image') });
    await expect(cover.getByRole('status')).toHaveText(/JPG, PNG, WebP, or GIF/);
    await expect(page.locator('#lnkup-organizer-cover-id')).toHaveValue(before);
  });
});

test.describe('Vendor profile images', () => {
  test.beforeEach(async ({ page }) => {
    test.skip(!vendorUser || !vendorPass, 'Set PLAYWRIGHT_VENDOR_USER and PLAYWRIGHT_VENDOR_PASS');
    await login(page, vendorUser!, vendorPass!);
  });

  test('vendor logo upload previews without the media library', async ({ page }) => {
    await page.goto('/vendor-profile/');
    const logo = page.getByTestId('lnkup-vendor-logo-upload');
    const response = await uploadInto(page, logo, { name: 'logo.png', mimeType: 'image/png', buffer: PNG_1X1 });
    expect(response.ok()).toBe(true);
    await expect(logo.getByRole('img')).toBeVisible();
    await expect(page.locator('.media-modal')).toHaveCount(0);
  });
});
