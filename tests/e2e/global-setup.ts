import { test as setup, expect } from '@playwright/test';
import path from 'path';
import fs from 'fs';

const authFile = path.join(__dirname, '.auth', 'admin.json');

setup('authenticate as admin', async ({ page }) => {
  // Ensure .auth directory exists
  const authDir = path.dirname(authFile);
  if (!fs.existsSync(authDir)) {
    fs.mkdirSync(authDir, { recursive: true });
  }

  // Log in to WordPress admin
  await page.goto('/wp-login.php');

  const userField = page.locator('#user_login');
  const passField = page.locator('#user_pass');

  await userField.click();
  await userField.fill('admin');
  await passField.click();
  await passField.fill('password');

  await page.locator('#wp-submit').click();

  // Wait for redirect to wp-admin dashboard
  await page.waitForURL('**/wp-admin/**', { timeout: 10000 });

  // Save signed-in state
  await page.context().storageState({ path: authFile });
});
