<?php

declare(strict_types=1);

namespace Freespoke\Wordpress\Tests;

use Brain\Monkey\Functions;
use Freespoke\Wordpress\Settings;

class SettingsTest extends TestCase
{
    public function testGetClientIdFromOverrides(): void
    {
        $settings = new Settings(['client_id' => 'override-id']);
        $this->assertSame('override-id', $settings->getClientId());
    }

    public function testGetClientIdFromOption(): void
    {
        Functions\expect('get_option')
            ->once()
            ->with('freespoke_client_id', '')
            ->andReturn('option-id');

        $settings = new Settings();
        $this->assertSame('option-id', $settings->getClientId());
    }

    public function testGetClientIdDefaultsToEmpty(): void
    {
        Functions\expect('get_option')
            ->once()
            ->with('freespoke_client_id', '')
            ->andReturn('');

        $settings = new Settings();
        $this->assertSame('', $settings->getClientId());
    }

    public function testIsClientIdLocked(): void
    {
        $locked = new Settings(['client_id' => 'x']);
        $this->assertTrue($locked->isClientIdLocked());

        $unlocked = new Settings();
        $this->assertFalse($unlocked->isClientIdLocked());
    }

    public function testGetClientSecretFromOverrides(): void
    {
        $settings = new Settings(['client_secret' => 'secret']);
        $this->assertSame('secret', $settings->getClientSecret());
    }

    public function testGetClientSecretFromOption(): void
    {
        Functions\expect('get_option')
            ->once()
            ->with('freespoke_client_secret', '')
            ->andReturn('db-secret');

        $settings = new Settings();
        $this->assertSame('db-secret', $settings->getClientSecret());
    }

    public function testIsClientSecretLocked(): void
    {
        $this->assertTrue((new Settings(['client_secret' => 'x']))->isClientSecretLocked());
        $this->assertFalse((new Settings())->isClientSecretLocked());
    }

    public function testGetTokenUrlFromOverrides(): void
    {
        $settings = new Settings(['token_url' => 'https://custom.example.com/token']);
        $this->assertSame('https://custom.example.com/token', $settings->getTokenUrl());
    }

    public function testGetTokenUrlDefault(): void
    {
        Functions\expect('get_option')
            ->once()
            ->andReturn('');

        $settings = new Settings();
        $this->assertStringContainsString('accounts.freespoke.com', $settings->getTokenUrl());
    }

    public function testGetApiKeyFromOverrides(): void
    {
        $settings = new Settings(['api_key' => 'my-key']);
        $this->assertSame('my-key', $settings->getApiKey());
    }

    public function testGetApiKeyFromOption(): void
    {
        Functions\expect('get_option')
            ->once()
            ->with('freespoke_publisher_api_key', '')
            ->andReturn('db-key');

        $settings = new Settings();
        $this->assertSame('db-key', $settings->getApiKey());
    }

    public function testIsApiKeyLocked(): void
    {
        $this->assertTrue((new Settings(['api_key' => 'x']))->isApiKeyLocked());
        $this->assertFalse((new Settings())->isApiKeyLocked());
    }

    public function testGetPublisherUrlFromOverrides(): void
    {
        $settings = new Settings(['publisher_url' => 'https://custom.example.com']);
        $this->assertSame('https://custom.example.com', $settings->getPublisherUrl());
    }

    public function testGetPublisherUrlNormalizesLegacySuffix(): void
    {
        $settings = new Settings(['publisher_url' => 'https://api.partners.freespoke.com/v1/content']);
        $this->assertSame('https://api.partners.freespoke.com', $settings->getPublisherUrl());
    }

    public function testGetPublisherUrlStripsTrailingSlash(): void
    {
        $settings = new Settings(['publisher_url' => 'https://api.example.com/']);
        $this->assertSame('https://api.example.com', $settings->getPublisherUrl());
    }

    public function testGetPublisherUrlDefault(): void
    {
        Functions\expect('get_option')
            ->once()
            ->andReturn('');

        $settings = new Settings();
        $this->assertSame('https://api.partners.freespoke.com', $settings->getPublisherUrl());
    }

    public function testGetNoticeEmailsFromOverrides(): void
    {
        $settings = new Settings(['notice_emails' => ['a@b.com', 'c@d.com']]);
        $this->assertSame(['a@b.com', 'c@d.com'], $settings->getNoticeEmails());
    }

    public function testGetNoticeEmailsFromOverridesAsString(): void
    {
        $settings = new Settings(['notice_emails' => 'a@b.com, c@d.com']);
        $this->assertSame(['a@b.com', 'c@d.com'], $settings->getNoticeEmails());
    }

    public function testGetNoticeEmailsDefault(): void
    {
        Functions\expect('get_option')
            ->once()
            ->andReturn(['technology@freespoke.com']);

        $settings = new Settings();
        $this->assertSame(['technology@freespoke.com'], $settings->getNoticeEmails());
    }

    public function testIsNoticeEmailsLocked(): void
    {
        $this->assertTrue((new Settings(['notice_emails' => []]))->isNoticeEmailsLocked());
        $this->assertFalse((new Settings())->isNoticeEmailsLocked());
    }

    public function testGetPostTypesDefaultPostOnly(): void
    {
        Functions\expect('get_option')
            ->andReturnUsing(function (string $key, $default = false) {
                return $default; // Neither option set
            });

        $settings = new Settings();
        $this->assertSame(['post'], $settings->getPostTypes());
    }

    public function testGetPostTypesFromOption(): void
    {
        Functions\expect('get_option')
            ->with('freespoke_post_types', false)
            ->andReturn(['post', 'page', 'recipe']);

        $settings = new Settings();
        $this->assertSame(['post', 'page', 'recipe'], $settings->getPostTypes());
    }

    public function testGetPostTypesFromOverrideArray(): void
    {
        $settings = new Settings(['post_types' => ['page', 'recipe']]);
        $this->assertSame(['post', 'page', 'recipe'], $settings->getPostTypes());
    }

    public function testGetPostTypesFromOverrideString(): void
    {
        $settings = new Settings(['post_types' => 'page, recipe']);
        $this->assertSame(['post', 'page', 'recipe'], $settings->getPostTypes());
    }

    public function testGetPostTypesOverrideExcludesAttachment(): void
    {
        $settings = new Settings(['post_types' => ['page', 'attachment']]);
        $this->assertSame(['post', 'page'], $settings->getPostTypes());
    }

    public function testGetPostTypesOptionExcludesAttachment(): void
    {
        Functions\expect('get_option')
            ->with('freespoke_post_types', false)
            ->andReturn(['page', 'attachment']);

        $settings = new Settings();
        $this->assertSame(['post', 'page'], $settings->getPostTypes());
    }

    public function testGetPostTypesAlwaysIncludesPost(): void
    {
        Functions\expect('get_option')
            ->with('freespoke_post_types', false)
            ->andReturn(['page']);

        $settings = new Settings();
        $this->assertContains('post', $settings->getPostTypes());
    }

    public function testGetPostTypesLegacyOverrideIncludePages(): void
    {
        $settings = new Settings(['include_pages' => true]);
        $this->assertSame(['post', 'page'], $settings->getPostTypes());
    }

    public function testGetPostTypesLegacyOverrideExcludePages(): void
    {
        $settings = new Settings(['include_pages' => false]);
        $this->assertSame(['post'], $settings->getPostTypes());
    }

    public function testGetPostTypesLegacyOptionFallback(): void
    {
        Functions\expect('get_option')
            ->andReturnUsing(function (string $key, $default = false) {
                return match ($key) {
                    'freespoke_post_types' => false,
                    'freespoke_include_pages' => true,
                    default => $default,
                };
            });

        $settings = new Settings();
        $this->assertSame(['post', 'page'], $settings->getPostTypes());
    }

    public function testGetPostTypesNewOverrideTakesPrecedenceOverLegacy(): void
    {
        $settings = new Settings(['post_types' => ['recipe'], 'include_pages' => true]);
        $this->assertSame(['post', 'recipe'], $settings->getPostTypes());
    }

    public function testIsPostTypesLocked(): void
    {
        $this->assertTrue((new Settings(['post_types' => ['page']]))->isPostTypesLocked());
        $this->assertTrue((new Settings(['include_pages' => true]))->isPostTypesLocked());
        $this->assertFalse((new Settings())->isPostTypesLocked());
    }

    public function testGetAuthModeClientCredentials(): void
    {
        $settings = new Settings(['client_id' => 'id', 'client_secret' => 'secret']);
        $this->assertSame('client_credentials', $settings->getAuthMode());
    }

    public function testGetAuthModeApiKey(): void
    {
        Functions\expect('get_option')->andReturn('');

        $settings = new Settings();
        $this->assertSame('api_key', $settings->getAuthMode());
    }

    public function testGetAuthModeApiKeyWhenOnlyClientId(): void
    {
        Functions\expect('get_option')->andReturn('');

        $settings = new Settings(['client_id' => 'id']);
        $this->assertSame('api_key', $settings->getAuthMode());
    }

    public function testHasCredentialsWithClientCredentials(): void
    {
        $settings = new Settings(['client_id' => 'id', 'client_secret' => 'secret']);
        $this->assertTrue($settings->hasCredentials());
    }

    public function testHasCredentialsWithApiKey(): void
    {
        Functions\expect('get_option')->andReturn('');

        $settings = new Settings(['api_key' => 'key']);
        $this->assertTrue($settings->hasCredentials());
    }

    public function testHasCredentialsFalseWhenNothingSet(): void
    {
        Functions\expect('get_option')->andReturn('');

        $settings = new Settings();
        $this->assertFalse($settings->hasCredentials());
    }

    public function testSaveUpdatesUnlockedFields(): void
    {
        Functions\expect('sanitize_text_field')->andReturnFirstArg();
        Functions\expect('wp_unslash')->andReturnFirstArg();
        Functions\expect('update_option')->times(6);

        $settings = new Settings();
        $settings->save([
            'auth_mode' => 'client_credentials',
            'client_id' => 'cid',
            'client_secret' => 'cs',
            'api_key' => 'ak',
            'notice_emails' => 'a@b.com',
            'post_types' => ['page', 'recipe'],
        ]);
    }

    public function testSaveSkipsLockedFields(): void
    {
        // update_option should NOT be called for locked fields
        Functions\expect('update_option')->never();

        $settings = new Settings([
            'client_id' => 'x',
            'client_secret' => 'x',
            'api_key' => 'x',
            'notice_emails' => 'x',
            'post_types' => ['page'],
        ]);

        $settings->save([
            'client_id' => 'new',
            'client_secret' => 'new',
            'api_key' => 'new',
            'notice_emails' => 'new',
            'post_types' => ['page'],
        ]);
    }

    public function testSaveSkipsLockedFieldsWithLegacyOverride(): void
    {
        Functions\expect('update_option')->never();

        $settings = new Settings([
            'client_id' => 'x',
            'client_secret' => 'x',
            'api_key' => 'x',
            'notice_emails' => 'x',
            'include_pages' => true,
        ]);

        $settings->save([
            'client_id' => 'new',
            'client_secret' => 'new',
            'api_key' => 'new',
            'notice_emails' => 'new',
            'post_types' => ['page'],
        ]);
    }

    public function testSaveIgnoresMissingKeys(): void
    {
        // post_types always saves when unlocked (no checkboxes = post only)
        Functions\expect('update_option')
            ->once()
            ->with('freespoke_post_types', ['post']);

        $settings = new Settings();
        $settings->save([]);
    }

    public function testSavePostTypesAlwaysIncludesPost(): void
    {
        Functions\expect('sanitize_text_field')->andReturnFirstArg();
        Functions\expect('update_option')
            ->once()
            ->with('freespoke_post_types', \Mockery::on(function ($value) {
                return is_array($value) && in_array('post', $value, true);
            }));

        $settings = new Settings();
        $settings->save(['post_types' => ['page']]);
    }

    public function testSavePostTypesExcludesAttachment(): void
    {
        Functions\expect('sanitize_text_field')->andReturnFirstArg();
        Functions\expect('update_option')
            ->once()
            ->with('freespoke_post_types', \Mockery::on(function ($value) {
                return is_array($value) && !in_array('attachment', $value, true);
            }));

        $settings = new Settings();
        $settings->save(['post_types' => ['page', 'attachment']]);
    }

    public function testGetOptionReturnsNonString(): void
    {
        Functions\expect('get_option')
            ->with('freespoke_client_id', '')
            ->andReturn(false);

        $settings = new Settings();
        $this->assertSame('', $settings->getClientId());
    }

    public function testGetContentMetaFieldsDefaultsEmpty(): void
    {
        Functions\expect('get_option')
            ->with('freespoke_content_meta_fields', [])
            ->andReturn([]);

        $settings = new Settings();
        $this->assertSame([], $settings->getContentMetaFields());
        $this->assertSame([], $settings->getContentMetaFieldKeys());
    }

    public function testGetContentMetaFieldsFromOption(): void
    {
        Functions\expect('get_option')
            ->with('freespoke_content_meta_fields', [])
            ->andReturn([
                'acf-custom-abc123' => 'Product description',
                'page_builder_c4f92' => 'Hero copy',
            ]);

        $settings = new Settings();
        $this->assertSame([
            'acf-custom-abc123' => 'Product description',
            'page_builder_c4f92' => 'Hero copy',
        ], $settings->getContentMetaFields());
        $this->assertSame(
            ['acf-custom-abc123', 'page_builder_c4f92'],
            $settings->getContentMetaFieldKeys()
        );
    }

    public function testGetContentMetaFieldsFromConstantOverride(): void
    {
        $settings = new Settings([
            'content_meta_fields' => [
                'ACF-CUSTOM-XYZ' => 'Case-sensitive field',
            ],
        ]);
        $this->assertTrue($settings->isContentMetaFieldsLocked());
        $this->assertSame('FREESPOKE_CONTENT_META_FIELDS', $settings->getContentMetaFieldsLockSource());
        $this->assertSame(
            ['ACF-CUSTOM-XYZ' => 'Case-sensitive field'],
            $settings->getContentMetaFields()
        );
    }

    public function testGetContentMetaFieldsNormalizationFromOption(): void
    {
        Functions\expect('get_option')
            ->once()
            ->with('freespoke_content_meta_fields', [])
            ->andReturn([
                '  leading_trailing  ' => '  some description  ',
                '' => 'empty key dropped',
                '   ' => 'whitespace key dropped',
                42 => 'non-string key dropped',
                'keep' => 123, // non-string description becomes ''
            ]);

        $settings = new Settings();
        $this->assertSame([
            'leading_trailing' => 'some description',
            'keep' => '',
        ], $settings->getContentMetaFields());
    }

    public function testGetContentMetaFieldsPreservesCase(): void
    {
        // Regression guard: case-sensitive page-builder keys must not be lowercased.
        Functions\expect('get_option')
            ->once()
            ->andReturn(['ACF-CUSTOM-ABC' => 'desc']);

        $settings = new Settings();
        $this->assertArrayHasKey('ACF-CUSTOM-ABC', $settings->getContentMetaFields());
    }

    public function testGetContentMetaFieldsFromRowShape(): void
    {
        // Row shape matches what the admin form submits; exercise via the
        // public save() → get() round-trip since normalize is private.
        Functions\expect('update_option')
            ->once()
            ->with('freespoke_content_meta_fields', ['a' => 'A second', 'b' => 'B']);
        Functions\expect('update_option')->zeroOrMoreTimes();
        Functions\expect('sanitize_text_field')->andReturnFirstArg();

        $settings = new Settings();
        $settings->save([
            'content_meta_fields' => [
                ['field_name' => 'a', 'description' => 'A'],
                ['field_name' => '', 'description' => 'blank row dropped'],
                ['field_name' => 'b', 'description' => 'B'],
                ['field_name' => 'a', 'description' => 'A second'], // last-wins
            ],
            'post_types' => ['page'],
        ]);
    }

    public function testGetContentMetaFieldsRejectsNonArrayInput(): void
    {
        Functions\expect('get_option')
            ->once()
            ->andReturn('not an array');

        $settings = new Settings();
        $this->assertSame([], $settings->getContentMetaFields());
    }

    public function testSaveContentMetaFields(): void
    {
        Functions\expect('sanitize_text_field')->andReturnFirstArg();
        Functions\expect('update_option')
            ->once()
            ->with('freespoke_content_meta_fields', [
                'acf-one' => 'One',
                'acf-two' => 'Two',
            ]);
        // Any other update_option calls in the same save() path are fine.
        Functions\expect('update_option')->zeroOrMoreTimes();

        $settings = new Settings();
        $settings->save([
            'content_meta_fields' => [
                ['field_name' => 'acf-one', 'description' => 'One'],
                ['field_name' => 'acf-two', 'description' => 'Two'],
                ['field_name' => '', 'description' => 'dropped'],
            ],
            'post_types' => ['page'],
        ]);
    }

    public function testSaveContentMetaFieldsSkipsLocked(): void
    {
        // update_option should not be called for content_meta_fields when locked.
        Functions\expect('sanitize_text_field')->andReturnFirstArg();
        Functions\expect('update_option')
            ->never()
            ->with('freespoke_content_meta_fields', \Mockery::any());
        Functions\expect('update_option')->zeroOrMoreTimes();

        $settings = new Settings([
            'content_meta_fields' => ['locked' => 'locked desc'],
        ]);
        $settings->save([
            'content_meta_fields' => [
                ['field_name' => 'ignored', 'description' => 'ignored'],
            ],
            'post_types' => ['page'],
        ]);
    }
}
