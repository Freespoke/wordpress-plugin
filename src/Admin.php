<?php

declare (strict_types=1);
namespace Freespoke\Wordpress;

class Admin
{
    private \Freespoke\Wordpress\Settings $settings;
    private \Freespoke\Wordpress\PostMeta $postMeta;
    private \Freespoke\Wordpress\Publisher $publisher;
    private \Freespoke\Wordpress\ClientFactory $factory;
    public function __construct(\Freespoke\Wordpress\Settings $settings, \Freespoke\Wordpress\PostMeta $postMeta, \Freespoke\Wordpress\Publisher $publisher, \Freespoke\Wordpress\ClientFactory $factory)
    {
        $this->settings = $settings;
        $this->postMeta = $postMeta;
        $this->publisher = $publisher;
        $this->factory = $factory;
    }
    public function registerPage(): void
    {
        add_management_page(__('Freespoke Publisher', 'freespoke-widget'), __('Freespoke Publisher', 'freespoke-widget'), 'manage_options', 'freespoke-publisher', [$this, 'renderPage']);
    }
    public function renderPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $message = '';
        if (isset($_POST['freespoke_publisher_settings_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['freespoke_publisher_settings_nonce'])), 'freespoke_publisher_settings')) {
            $message = $this->handleFormSubmit();
        }
        $epochValue = $this->publisher->getEpoch();
        $pendingPosts = is_wp_error($epochValue) ? [] : $this->postMeta->getPostsNeedingIndex((int) $epochValue);
        $pendingCount = count($pendingPosts);
        $pendingJobs = $this->postMeta->getPostsWithPendingJobs();
        $pendingJobCount = count($pendingJobs);
        $errorPosts = $this->postMeta->getPostsWithErrors();
        $errorCount = count($errorPosts);
        $authMode = $this->settings->getAuthMode();
        ?>
        <div class="wrap">
            <h1><?php 
        esc_html_e('Freespoke Publisher', 'freespoke-widget');
        ?></h1>

            <?php 
        if (!empty($message)) {
            ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php 
            echo esc_html($message);
            ?></p>
                </div>
            <?php 
        }
        ?>

            <form method="post">
                <?php 
        wp_nonce_field('freespoke_publisher_settings', 'freespoke_publisher_settings_nonce');
        ?>

                <h2><?php 
        esc_html_e('Authentication', 'freespoke-widget');
        ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="freespoke_auth_mode"><?php 
        esc_html_e('Auth Method', 'freespoke-widget');
        ?></label>
                        </th>
                        <td>
                            <select name="auth_mode" id="freespoke_auth_mode">
                                <option value="client_credentials" <?php 
        selected($authMode, 'client_credentials');
        ?>>
                                    <?php 
        esc_html_e('Client Credentials (OAuth2)', 'freespoke-widget');
        ?>
                                </option>
                                <option value="api_key" <?php 
        selected($authMode, 'api_key');
        ?>>
                                    <?php 
        esc_html_e('API Key', 'freespoke-widget');
        ?>
                                </option>
                            </select>
                        </td>
                    </tr>
                </table>

                <table class="form-table" role="presentation" id="freespoke-auth-client-credentials" style="<?php 
        echo $authMode !== 'client_credentials' ? 'display:none;' : '';
        ?>">
                    <tr>
                        <th scope="row">
                            <label for="freespoke_client_id"><?php 
        esc_html_e('Client ID', 'freespoke-widget');
        ?></label>
                        </th>
                        <td>
                            <input name="client_id" id="freespoke_client_id" type="text" class="regular-text"
                                   value="<?php 
        echo esc_attr($this->settings->getClientId());
        ?>"
                                   <?php 
        disabled($this->settings->isClientIdLocked());
        ?> />
                            <?php 
        if ($this->settings->isClientIdLocked()) {
            ?>
                                <p class="description"><?php 
            esc_html_e('Set via FREESPOKE_CLIENT_ID constant.', 'freespoke-widget');
            ?></p>
                            <?php 
        }
        ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="freespoke_client_secret"><?php 
        esc_html_e('Client Secret', 'freespoke-widget');
        ?></label>
                        </th>
                        <td>
                            <input name="client_secret" id="freespoke_client_secret" type="password" class="regular-text"
                                   value="<?php 
        echo esc_attr($this->settings->getClientSecret());
        ?>"
                                   <?php 
        disabled($this->settings->isClientSecretLocked());
        ?> />
                            <?php 
        if ($this->settings->isClientSecretLocked()) {
            ?>
                                <p class="description"><?php 
            esc_html_e('Set via FREESPOKE_CLIENT_SECRET constant.', 'freespoke-widget');
            ?></p>
                            <?php 
        }
        ?>
                        </td>
                    </tr>
                </table>

                <table class="form-table" role="presentation" id="freespoke-auth-api-key" style="<?php 
        echo $authMode !== 'api_key' ? 'display:none;' : '';
        ?>">
                    <tr>
                        <th scope="row">
                            <label for="freespoke_publisher_api_key"><?php 
        esc_html_e('API Key', 'freespoke-widget');
        ?></label>
                        </th>
                        <td>
                            <input name="api_key" id="freespoke_publisher_api_key" type="password" class="regular-text"
                                   value="<?php 
        echo esc_attr($this->settings->getApiKey());
        ?>"
                                   <?php 
        disabled($this->settings->isApiKeyLocked());
        ?> />
                            <?php 
        if ($this->settings->isApiKeyLocked()) {
            ?>
                                <p class="description"><?php 
            esc_html_e('Set via FREESPOKE_PUBLISHER_API_KEY constant.', 'freespoke-widget');
            ?></p>
                            <?php 
        }
        ?>
                        </td>
                    </tr>
                </table>

                <p>
                    <button type="button" id="freespoke-test-auth" class="button button-secondary">
                        <?php 
        esc_html_e('Test Authentication', 'freespoke-widget');
        ?>
                    </button>
                    <span id="freespoke-test-auth-result" style="margin-left: 8px;"></span>
                </p>

                <h2><?php 
        esc_html_e('Settings', 'freespoke-widget');
        ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="freespoke_notice_emails"><?php 
        esc_html_e('Notice Emails', 'freespoke-widget');
        ?></label>
                        </th>
                        <td>
                            <input name="notice_emails" id="freespoke_notice_emails" type="text" class="regular-text"
                                   value="<?php 
        echo esc_attr(implode(', ', $this->settings->getNoticeEmails()));
        ?>"
                                   <?php 
        disabled($this->settings->isNoticeEmailsLocked());
        ?> />
                            <p class="description"><?php 
        esc_html_e('Comma-separated email addresses.', 'freespoke-widget');
        ?></p>
                            <?php 
        if ($this->settings->isNoticeEmailsLocked()) {
            ?>
                                <p class="description"><?php 
            esc_html_e('Set via FREESPOKE_NOTICE_EMAILS constant.', 'freespoke-widget');
            ?></p>
                            <?php 
        }
        ?>
                        </td>
                    </tr>
                </table>

                <?php 
        submit_button(__('Save Settings', 'freespoke-widget'));
        ?>
            </form>

            <h2><?php 
        esc_html_e('Status', 'freespoke-widget');
        ?></h2>
            <p>
                <strong><?php 
        esc_html_e('Current auth mode:', 'freespoke-widget');
        ?></strong>
                <?php 
        echo esc_html($this->settings->getAuthMode());
        ?>
            </p>
            <p>
                <strong><?php 
        esc_html_e('Latest epoch:', 'freespoke-widget');
        ?></strong>
                <?php 
        if (is_wp_error($epochValue)) {
            echo esc_html($epochValue->get_error_message());
        } else {
            echo esc_html((string) $epochValue);
        }
        ?>
            </p>
            <p>
                <strong><?php 
        esc_html_e('Posts waiting submit/resubmit:', 'freespoke-widget');
        ?></strong>
                <?php 
        echo esc_html((string) $pendingCount);
        ?>
            </p>
            <p>
                <strong><?php 
        esc_html_e('Posts with pending jobs:', 'freespoke-widget');
        ?></strong>
                <?php 
        echo esc_html((string) $pendingJobCount);
        ?>
            </p>
            <p>
                <strong><?php 
        esc_html_e('Posts with submit errors:', 'freespoke-widget');
        ?></strong>
                <?php 
        echo esc_html((string) $errorCount);
        ?>
            </p>

            <?php 
        if ($errorCount > 0) {
            ?>
                <h3><?php 
            esc_html_e('Errored posts', 'freespoke-widget');
            ?></h3>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php 
            esc_html_e('Post', 'freespoke-widget');
            ?></th>
                            <th><?php 
            esc_html_e('Error', 'freespoke-widget');
            ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php 
            foreach ($errorPosts as $errorPost) {
                ?>
                        <tr>
                            <td>
                                <a href="<?php 
                echo esc_url(get_edit_post_link($errorPost['ID']));
                ?>">
                                    <?php 
                echo esc_html($errorPost['title']);
                ?>
                                </a>
                            </td>
                            <td>
                                <?php 
                $errorMessage = $errorPost['message'];
                if (!empty($errorPost['code'])) {
                    $errorMessage = sprintf('[%s] %s', $errorPost['code'], $errorMessage);
                }
                echo esc_html($errorMessage ?: __('Unknown error', 'freespoke-widget'));
                ?>
                            </td>
                        </tr>
                    <?php 
            }
            ?>
                    </tbody>
                </table>
            <?php 
        }
        ?>
        </div>

        <script>
        (function () {
            // Auth mode toggle
            var modeSelect = document.getElementById('freespoke_auth_mode');
            var ccSection = document.getElementById('freespoke-auth-client-credentials');
            var akSection = document.getElementById('freespoke-auth-api-key');

            if (modeSelect && ccSection && akSection) {
                modeSelect.addEventListener('change', function () {
                    if (this.value === 'client_credentials') {
                        ccSection.style.display = '';
                        akSection.style.display = 'none';
                    } else {
                        ccSection.style.display = 'none';
                        akSection.style.display = '';
                    }
                });
            }

            // Test auth with current (unsaved) form values
            var btn = document.getElementById('freespoke-test-auth');
            var result = document.getElementById('freespoke-test-auth-result');
            if (!btn || !result) return;

            btn.addEventListener('click', function () {
                btn.disabled = true;
                result.textContent = '<?php 
        echo esc_js(__('Testing...', 'freespoke-widget'));
        ?>';
                result.style.color = '';

                var mode = modeSelect ? modeSelect.value : 'api_key';
                var clientId = document.getElementById('freespoke_client_id');
                var clientSecret = document.getElementById('freespoke_client_secret');
                var apiKey = document.getElementById('freespoke_publisher_api_key');

                var params = 'action=freespoke_test_auth'
                    + '&_ajax_nonce=<?php 
        echo esc_js(wp_create_nonce('freespoke_test_auth'));
        ?>'
                    + '&auth_mode=' + encodeURIComponent(mode)
                    + '&client_id=' + encodeURIComponent(clientId ? clientId.value : '')
                    + '&client_secret=' + encodeURIComponent(clientSecret ? clientSecret.value : '')
                    + '&api_key=' + encodeURIComponent(apiKey ? apiKey.value : '');

                var xhr = new XMLHttpRequest();
                xhr.open('POST', '<?php 
        echo esc_js(admin_url('admin-ajax.php'));
        ?>');
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onload = function () {
                    btn.disabled = false;
                    try {
                        var data = JSON.parse(xhr.responseText);
                        if (data.success) {
                            result.textContent = '<?php 
        echo esc_js(__('Authentication successful.', 'freespoke-widget'));
        ?>';
                            result.style.color = '#00a32a';
                        } else {
                            result.textContent = data.data || '<?php 
        echo esc_js(__('Authentication failed.', 'freespoke-widget'));
        ?>';
                            result.style.color = '#d63638';
                        }
                    } catch (e) {
                        result.textContent = '<?php 
        echo esc_js(__('Unexpected response from server.', 'freespoke-widget'));
        ?>';
                        result.style.color = '#d63638';
                    }
                };
                xhr.onerror = function () {
                    btn.disabled = false;
                    result.textContent = '<?php 
        echo esc_js(__('Request failed.', 'freespoke-widget'));
        ?>';
                    result.style.color = '#d63638';
                };
                xhr.send(params);
            });
        })();
        </script>
        <?php 
    }
    public function renderMissingCredentialsNotice(): void
    {
        ?>
        <div class="notice notice-warning">
            <p><?php 
        esc_html_e('Freespoke Publisher requires authentication credentials to automatically publish content to Freespoke. Configure them in Tools &rarr; Freespoke Publisher.', 'freespoke-widget');
        ?></p>
        </div>
        <?php 
    }
    public function handleTestAuth(): void
    {
        check_ajax_referer('freespoke_test_auth');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'freespoke-widget'));
        }
        $authMode = isset($_POST['auth_mode']) ? sanitize_text_field(wp_unslash($_POST['auth_mode'])) : '';
        $clientId = isset($_POST['client_id']) ? sanitize_text_field(wp_unslash($_POST['client_id'])) : '';
        $clientSecret = isset($_POST['client_secret']) ? wp_unslash((string) $_POST['client_secret']) : '';
        $apiKey = isset($_POST['api_key']) ? wp_unslash((string) $_POST['api_key']) : '';
        try {
            $client = $this->factory->createFromCredentials($authMode, $clientId, $clientSecret, $apiKey);
            $client->getEpoch();
        } catch (\Throwable $e) {
            wp_send_json_error($e->getMessage());
        }
        wp_send_json_success();
    }
    private function handleFormSubmit(): string
    {
        if (!current_user_can('manage_options')) {
            return '';
        }
        $this->settings->save(['auth_mode' => isset($_POST['auth_mode']) ? wp_unslash($_POST['auth_mode']) : null, 'client_id' => isset($_POST['client_id']) ? wp_unslash($_POST['client_id']) : null, 'client_secret' => isset($_POST['client_secret']) ? wp_unslash($_POST['client_secret']) : null, 'api_key' => isset($_POST['api_key']) ? wp_unslash($_POST['api_key']) : null, 'notice_emails' => isset($_POST['notice_emails']) ? wp_unslash($_POST['notice_emails']) : null]);
        return __('Settings saved.', 'freespoke-widget');
    }
}
