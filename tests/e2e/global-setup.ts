import { test as setup, expect } from '@playwright/test';
import { execSync } from 'child_process';
import path from 'path';
import fs from 'fs';

const authFile = path.join(__dirname, '.auth', 'admin.json');

setup('authenticate as admin', async ({ page }) => {
  // Configure a dummy AI connector so the admin chat UI (#pp-ai-messages)
  // renders. pp_ai_is_configured() requires a connector with an API key set;
  // a fresh wp-env has none. The validation specs intercept network routes, so
  // no real provider call is made — any non-empty key unlocks the UI.
  execSync(
    'npx wp-env run cli wp option update connectors_ai_anthropic_api_key "test-key-e2e"',
    { cwd: process.cwd(), stdio: 'ignore' }
  );

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
