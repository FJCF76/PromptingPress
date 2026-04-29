<?php
/**
 * tests/AiSettingsTest.php — PHPUnit tests for AI Settings
 *
 * Covers: provider data validation, migration logic, sanitize callback
 * for Base URL derivation, test connection parameter assembly.
 */

use PHPUnit\Framework\TestCase;

class AiSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = [
            'post_meta' => [],
            'posts'     => [],
            'options'   => [],
            'next_id'   => 100,
        ];
    }

    // ── Provider Data Validation ─────────────────────────────────────────

    public function testProviderArrayHasRequiredKeys(): void
    {
        $providers = pp_ai_get_providers();
        foreach ($providers as $key => $provider) {
            $this->assertArrayHasKey('label', $provider, "Provider '$key' missing 'label'");
            $this->assertArrayHasKey('base_url', $provider, "Provider '$key' missing 'base_url'");
            $this->assertArrayHasKey('models', $provider, "Provider '$key' missing 'models'");
            $this->assertIsArray($provider['models'], "Provider '$key' models must be array");
        }
    }

    public function testGitHubModelsProviderExists(): void
    {
        $providers = pp_ai_get_providers();
        $this->assertArrayHasKey('github_models', $providers);
        $this->assertNotEmpty($providers['github_models']['base_url']);
        $this->assertNotEmpty($providers['github_models']['models']);
    }

    public function testCustomProviderHasEmptyBaseUrl(): void
    {
        $providers = pp_ai_get_providers();
        $this->assertArrayHasKey('custom', $providers);
        $this->assertEmpty($providers['custom']['base_url']);
        $this->assertEmpty($providers['custom']['models']);
    }

    public function testDefaultModelExistsInGitHubModelsProvider(): void
    {
        $providers = pp_ai_get_providers();
        $models = $providers['github_models']['models'];
        $this->assertArrayHasKey(PP_AI_DEFAULT_MODEL, $models);
    }

    public function testModelKeysAreValidFormat(): void
    {
        $providers = pp_ai_get_providers();
        foreach ($providers as $key => $provider) {
            foreach ($provider['models'] as $model_id => $label) {
                $this->assertIsString($model_id, "Model key in '$key' must be string");
                $this->assertNotEmpty($model_id, "Model key in '$key' must not be empty");
                $this->assertIsString($label, "Model label in '$key' must be string");
            }
        }
    }

    // ── Migration ────────────────────────────────────────────────────────

    public function testMigrationFromGitHubModelsString(): void
    {
        update_option(PP_AI_OPT_PROVIDER, 'GitHub Models');
        pp_ai_maybe_migrate_provider();
        $this->assertEquals('github_models', get_option(PP_AI_OPT_PROVIDER));
    }

    public function testMigrationFromUnknownStringMapsToCustom(): void
    {
        update_option(PP_AI_OPT_PROVIDER, 'Some Other Provider');
        pp_ai_maybe_migrate_provider();
        $this->assertEquals('custom', get_option(PP_AI_OPT_PROVIDER));
    }

    public function testMigrationDoesNotChangeValidKey(): void
    {
        update_option(PP_AI_OPT_PROVIDER, 'github_models');
        pp_ai_maybe_migrate_provider();
        $this->assertEquals('github_models', get_option(PP_AI_OPT_PROVIDER));
    }

    public function testMigrationDoesNotChangeCustomKey(): void
    {
        update_option(PP_AI_OPT_PROVIDER, 'custom');
        pp_ai_maybe_migrate_provider();
        $this->assertEquals('custom', get_option(PP_AI_OPT_PROVIDER));
    }

    public function testMigrationDoesNotChangeEmptyProvider(): void
    {
        // Empty string = fresh install, no migration needed
        update_option(PP_AI_OPT_PROVIDER, '');
        pp_ai_maybe_migrate_provider();
        $this->assertEquals('', get_option(PP_AI_OPT_PROVIDER));
    }

    // ── Base URL Sanitize Callback ───────────────────────────────────────

    public function testSanitizeBaseUrlOverridesForGitHubModels(): void
    {
        $_POST[PP_AI_OPT_PROVIDER] = 'github_models';
        $result = pp_ai_sanitize_base_url('https://wrong.example.com');
        $providers = pp_ai_get_providers();
        $this->assertEquals($providers['github_models']['base_url'], $result);
        unset($_POST[PP_AI_OPT_PROVIDER]);
    }

    public function testSanitizeBaseUrlPassesThroughForCustom(): void
    {
        $_POST[PP_AI_OPT_PROVIDER] = 'custom';
        $url = 'https://my-provider.com/v1/chat/completions';
        $result = pp_ai_sanitize_base_url($url);
        $this->assertEquals($url, $result);
        unset($_POST[PP_AI_OPT_PROVIDER]);
    }

    public function testSanitizeBaseUrlPassesThroughWhenNoProvider(): void
    {
        // No $_POST provider set
        unset($_POST[PP_AI_OPT_PROVIDER]);
        $url = 'https://fallback.example.com/v1/completions';
        $result = pp_ai_sanitize_base_url($url);
        $this->assertEquals($url, $result);
    }

    // ── Default Constants ────────────────────────────────────────────────

    public function testDefaultProviderIsGitHubModels(): void
    {
        $this->assertEquals('github_models', PP_AI_DEFAULT_PROVIDER);
    }

    public function testDefaultModelIsGpt5Chat(): void
    {
        $this->assertEquals('openai/gpt-5-chat', PP_AI_DEFAULT_MODEL);
    }
}
