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
        $postTypes = $this->settings->getPostTypes();
        $epochValue = $this->publisher->getEpoch();
        $pendingPosts = is_wp_error($epochValue) ? [] : $this->postMeta->getPostsNeedingIndex((int) $epochValue, 50, $postTypes);
        $pendingCount = count($pendingPosts);
        $pendingJobs = $this->postMeta->getPostsWithPendingJobs(50, $postTypes);
        $pendingJobCount = count($pendingJobs);
        $errorPosts = $this->postMeta->getPostsWithErrors(50, $postTypes);
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
                            <p class="description"><?php 
        esc_html_e('How the plugin authenticates with the Freespoke API. API keys are supported for backwards compatibility only.', 'freespoke-widget');
        ?></p>
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
                            <p class="description">
                                <?php 
        esc_html_e('The OAuth2 client ID provided by Freespoke for your application.', 'freespoke-widget');
        ?>
                                <?php 
        if ($this->settings->isClientIdLocked()) {
            ?>
                                    <br /><?php 
            esc_html_e('Set via FREESPOKE_CLIENT_ID constant.', 'freespoke-widget');
            ?>
                                <?php 
        }
        ?>
                            </p>
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
                            <p class="description">
                                <?php 
        esc_html_e('The OAuth2 client secret provided by Freespoke. Keep this value confidential.', 'freespoke-widget');
        ?>
                                <?php 
        if ($this->settings->isClientSecretLocked()) {
            ?>
                                    <br /><?php 
            esc_html_e('Set via FREESPOKE_CLIENT_SECRET constant.', 'freespoke-widget');
            ?>
                                <?php 
        }
        ?>
                            </p>
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
                            <p class="description">
                                <?php 
        esc_html_e('Your Freespoke Publisher API key. Keep this value confidential.', 'freespoke-widget');
        ?>
                                <?php 
        if ($this->settings->isApiKeyLocked()) {
            ?>
                                    <br /><?php 
            esc_html_e('Set via FREESPOKE_PUBLISHER_API_KEY constant.', 'freespoke-widget');
            ?>
                                <?php 
        }
        ?>
                            </p>
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
                            <?php 
        esc_html_e('Content Types', 'freespoke-widget');
        ?>
                        </th>
                        <td>
                            <fieldset>
                                <?php 
        $enabledTypes = $this->settings->getPostTypes();
        $typesLocked = $this->settings->isPostTypesLocked();
        $publicTypes = get_post_types(['public' => \true], 'objects');
        unset($publicTypes['attachment']);
        foreach ($publicTypes as $typeSlug => $typeObj) {
            $isPost = $typeSlug === 'post';
            $isChecked = $isPost || in_array($typeSlug, $enabledTypes, \true);
            ?>
                                    <label>
                                        <?php 
            if ($isPost) {
                ?>
                                            <input type="checkbox" disabled checked />
                                        <?php 
            } else {
                ?>
                                            <input name="post_types[]" type="checkbox"
                                                   value="<?php 
                echo esc_attr($typeSlug);
                ?>"
                                                   <?php 
                checked($isChecked);
                ?>
                                                   <?php 
                disabled($typesLocked);
                ?> />
                                        <?php 
            }
            ?>
                                        <?php 
            echo esc_html($typeObj->labels->name);
            ?>
                                    </label>
                                    <br />
                                <?php 
        }
        ?>
                                <p class="description">
                                    <?php 
        esc_html_e('Which WordPress content types to publish to Freespoke. Posts are always included.', 'freespoke-widget');
        ?>
                                    <?php 
        if ($typesLocked) {
            ?>
                                        <br /><?php 
            echo esc_html(sprintf(__('Set via %s constant.', 'freespoke-widget'), $this->settings->getPostTypesLockSource()));
            ?>
                                    <?php 
        }
        ?>
                                </p>
                            </fieldset>
                        </td>
                    </tr>
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
        esc_html_e('Comma-separated email addresses that will receive notifications about publish errors and status changes.', 'freespoke-widget');
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
            esc_html_e('Submission Errors', 'freespoke-widget');
            ?></h3>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php 
            esc_html_e('Title', 'freespoke-widget');
            ?></th>
                            <th><?php 
            esc_html_e('Error', 'freespoke-widget');
            ?></th>
                            <th><?php 
            esc_html_e('Actions', 'freespoke-widget');
            ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php 
            foreach ($errorPosts as $errorPost) {
                ?>
                        <tr id="freespoke-error-row-<?php 
                echo esc_attr((string) $errorPost['ID']);
                ?>">
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
                            <td>
                                <button type="button" class="button button-secondary freespoke-resubmit"
                                        data-post-id="<?php 
                echo esc_attr((string) $errorPost['ID']);
                ?>">
                                    <?php 
                esc_html_e('Resubmit', 'freespoke-widget');
                ?>
                                </button>
                                <span class="freespoke-resubmit-result" style="margin-left: 6px;"></span>
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

            if (btn && result) btn.addEventListener('click', function () {
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
            // Resubmit error posts
            document.querySelectorAll('.freespoke-resubmit').forEach(function (resubmitBtn) {
                resubmitBtn.addEventListener('click', function () {
                    var postId = this.getAttribute('data-post-id');
                    var row = document.getElementById('freespoke-error-row-' + postId);
                    var resultSpan = row ? row.querySelector('.freespoke-resubmit-result') : null;

                    this.style.display = 'none';
                    if (resultSpan) {
                        resultSpan.textContent = '<?php 
        echo esc_js(__('Submitting...', 'freespoke-widget'));
        ?>';
                        resultSpan.style.color = '';
                    }

                    var self = this;
                    var params = 'action=freespoke_resubmit'
                        + '&_ajax_nonce=<?php 
        echo esc_js(wp_create_nonce('freespoke_resubmit'));
        ?>'
                        + '&post_id=' + encodeURIComponent(postId);

                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', '<?php 
        echo esc_js(admin_url('admin-ajax.php'));
        ?>');
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    xhr.onload = function () {
                        try {
                            var data = JSON.parse(xhr.responseText);
                            if (data.success) {
                                if (resultSpan) {
                                    resultSpan.textContent = '<?php 
        echo esc_js(__('Submitted.', 'freespoke-widget'));
        ?>';
                                    resultSpan.style.color = '#00a32a';
                                }
                            } else {
                                self.style.display = '';
                                if (resultSpan) {
                                    resultSpan.textContent = data.data || '<?php 
        echo esc_js(__('Resubmit failed.', 'freespoke-widget'));
        ?>';
                                    resultSpan.style.color = '#d63638';
                                }
                            }
                        } catch (e) {
                            self.style.display = '';
                            if (resultSpan) {
                                resultSpan.textContent = '<?php 
        echo esc_js(__('Unexpected response.', 'freespoke-widget'));
        ?>';
                                resultSpan.style.color = '#d63638';
                            }
                        }
                    };
                    xhr.onerror = function () {
                        self.style.display = '';
                        if (resultSpan) {
                            resultSpan.textContent = '<?php 
        echo esc_js(__('Request failed.', 'freespoke-widget'));
        ?>';
                            resultSpan.style.color = '#d63638';
                        }
                    };
                    xhr.send(params);
                });
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
    public function handleResubmit(): void
    {
        check_ajax_referer('freespoke_resubmit');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'freespoke-widget'));
        }
        $postId = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        if ($postId <= 0) {
            wp_send_json_error(__('Invalid post ID.', 'freespoke-widget'));
        }
        $post = get_post($postId);
        if (!$post) {
            wp_send_json_error(__('Post not found.', 'freespoke-widget'));
        }
        if (!$this->publisher->shouldIndex($post)) {
            wp_send_json_error(__('This post is not eligible for indexing. It must be published, not password-protected, and an enabled content type.', 'freespoke-widget'));
        }
        $result = $this->publisher->submit($postId, $post);
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        wp_send_json_success();
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
        $this->settings->save(['auth_mode' => isset($_POST['auth_mode']) ? wp_unslash($_POST['auth_mode']) : null, 'client_id' => isset($_POST['client_id']) ? wp_unslash($_POST['client_id']) : null, 'client_secret' => isset($_POST['client_secret']) ? wp_unslash($_POST['client_secret']) : null, 'api_key' => isset($_POST['api_key']) ? wp_unslash($_POST['api_key']) : null, 'notice_emails' => isset($_POST['notice_emails']) ? wp_unslash($_POST['notice_emails']) : null, 'post_types' => isset($_POST['post_types']) ? array_map('sanitize_text_field', (array) wp_unslash($_POST['post_types'])) : []]);
        return __('Settings saved.', 'freespoke-widget');
    }
}
