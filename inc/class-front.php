<?php

class EdelLisenseManagerFront {
    function front_enqueue() {
        $version  = (defined('EDEL_LISENSE_MANAGER_DEVELOP') && true === EDEL_LISENSE_MANAGER_DEVELOP) ? time() : EDEL_LISENSE_MANAGER_VERSION;
        $strategy = array('in_footer' => true, 'strategy'  => 'defer');

        wp_register_script(EDEL_LISENSE_MANAGER_SLUG . '-front', EDEL_LISENSE_MANAGER_URL . '/js/front.js', array('jquery'), $version, $strategy);
        wp_register_style(EDEL_LISENSE_MANAGER_SLUG . '-front',  EDEL_LISENSE_MANAGER_URL . '/css/front.css', array(), $version);

        // $params = array('ajaxurl' => admin_url( 'admin-ajax.php'));
        // wp_localize_script(EDEL_LISENSE_MANAGER_SLUG . '-front', 'params', $params );

        // $front = array(
        //     'ajaxurl' => admin_url('admin-ajax.php'),
        //     'nonce'   => wp_create_nonce(MY_PLUGIN_TEMPLATE_PREFIX)
        // );
        // wp_localize_script(EDEL_LISENSE_MANAGER_SLUG . '-front', 'front', $front);

        // if (is_page()) {
        //     $params = array('ajaxurl' => admin_url('admin-ajax.php'));
        //     wp_localize_script(EDEL_LISENSE_MANAGER_SLUG . '-front', 'params', $params);
        // }

    }

    public function register_license_api_routes() {
        $namespace = EDEL_LISENSE_MANAGER_PREFIX . 'v1'; // 例: edel_lisense_manager_v1

        // --- /activate Endpoint ---
        register_rest_route($namespace, '/activate', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_license_activation'),
            'permission_callback' => '__return_true', // For now, allow public access. Secure with API keys or other methods if needed.
            'args'                => array(
                'license_key' => array(
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => function ($param) {
                        return !empty(trim($param)) && strlen($param) < 200;
                    }
                ),
                'site_url' => array(
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'esc_url_raw',
                    'validate_callback' => function ($param) {
                        return filter_var($param, FILTER_VALIDATE_URL) !== false;
                    }
                ),
                'product_id' => array(
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_key',
                ),
                'instance_id' => array(
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ));

        register_rest_route($namespace, '/deactivate', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_license_deactivation'),
            'permission_callback' => '__return_true', // 同様に、本番では適切な権限チェックを推奨
            'args'                => array(
                'license_key' => array(
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => function ($param) {
                        return !empty(trim($param)) && strlen($param) < 200;
                    }
                ),
                'site_url' => array(
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'esc_url_raw',
                    'validate_callback' => function ($param) {
                        return filter_var($param, FILTER_VALIDATE_URL) !== false;
                    }
                ),
                'product_id' => array(
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_key',
                ),
                'instance_id' => array( // オプション
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ));

        register_rest_route($namespace, '/check', array(
            'methods'             => 'POST', // または GET (今回はPOSTに統一)
            'callback'            => array($this, 'handle_license_check'),
            'permission_callback' => '__return_true',
            'args'                => array(
                'license_key' => array(
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => function ($param) {
                        return !empty(trim($param)) && strlen($param) < 200;
                    }
                ),
                'site_url' => array( // このサイトで有効化されているか確認するため
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'esc_url_raw',
                    'validate_callback' => function ($param) {
                        return filter_var($param, FILTER_VALIDATE_URL) !== false;
                    }
                ),
                'product_id' => array(
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_key',
                ),
                'instance_id' => array( // オプション
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                // 'plugin_version' => array( ... ), // 必要なら
            ),
        ));

        register_rest_route($namespace, '/latest_version', array(
            'methods'             => 'GET', // PUCは通常GETを使用
            'callback'            => array($this, 'handle_latest_version_request'),
            'permission_callback' => '__return_true',
            'args'                => array( // PUCから送られてくる想定のクエリパラメータ
                'action' => array( // PUCは 'get_metadata' などを送ってくる
                    'required'          => false, // PUCが送るので実際は必須だが、APIとしては任意
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_key',
                ),
                'slug' => array( // プラグインのスラッグ
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_key',
                ),
                'license_key' => array( // クライアントプラグイン側でPUCに付加させる
                    'required'          => false, // Lite版などライセンス不要な場合もあるため
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'site_url' => array( // クライアントプラグイン側でPUCに付加させる
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'esc_url_raw',
                ),
                // 'current_version' => array( ... ) // PUCが送ってくる場合がある
            ),
        ));

        error_log('Edel LIsense Manager: /activate REST route registered.');

        // TODO: Register /deactivate, /check, /latest_version endpoints here
    }

    /**
     * ★新規追加: Handles the /activate API request.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_license_activation(WP_REST_Request $request) {
        global $wpdb;
        $licenses_table = $wpdb->prefix . EDEL_LISENSE_MANAGER_PREFIX . 'licenses';
        $activations_table = $wpdb->prefix . EDEL_LISENSE_MANAGER_PREFIX . 'license_activations';
        $error_log_prefix = '[Edel License API /activate] ';

        $license_key = $request->get_param('license_key');
        $site_url    = $request->get_param('site_url');
        $product_id  = $request->get_param('product_id');
        $instance_id = $request->get_param('instance_id');

        error_log($error_log_prefix . "Request received. Key: {$license_key}, Site: {$site_url}, Product: {$product_id}");

        // 1. Find license by key and product_id
        $license = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$licenses_table} WHERE license_key = %s AND product_id = %s",
            $license_key,
            $product_id
        ));

        if (!$license) {
            error_log($error_log_prefix . "Invalid key or product. Key: {$license_key}, Product: {$product_id}");
            return new WP_REST_Response(['activated' => false, 'error_code' => 'invalid_key_or_product', 'message' => 'ライセンスキーまたは製品IDが無効です。'], 404); // Not Found or Bad Request
        }

        // 2. Check license status
        if ($license->status !== 'active') {
            error_log($error_log_prefix . "License not active. Status: {$license->status}, Key: {$license_key}");
            return new WP_REST_Response(['activated' => false, 'error_code' => 'license_not_active', 'message' => 'ライセンスが有効ではありません (ステータス: ' . esc_html($license->status) . ')。'], 403); // Forbidden
        }

        // 3. Check expiration (if expires_at is set)
        if ($license->expires_at && strtotime($license->expires_at) < time()) {
            error_log($error_log_prefix . "License expired. Expires: {$license->expires_at}, Key: {$license_key}");
            // Optionally update status to 'expired' here
            // $wpdb->update($licenses_table, ['status' => 'expired'], ['id' => $license->id]);
            return new WP_REST_Response(['activated' => false, 'error_code' => 'license_expired', 'message' => 'ライセンスの有効期限が切れています。'], 403);
        }

        // 4. Check if already activated for this site (and is_active = 1)
        $existing_activation = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$activations_table} WHERE license_id = %d AND site_url = %s AND is_active = 1",
            $license->id,
            $site_url
        ));
        if ($existing_activation) {
            error_log($error_log_prefix . "Site already activated. Key: {$license_key}, Site: {$site_url}");
            return new WP_REST_Response(['activated' => true, 'status' => $license->status, 'message' => 'このサイトは既に有効化されています。', 'expires_at' => $license->expires_at, 'instance_id' => $existing_activation->instance_id], 200);
        }

        // 5. Check activation limit
        if ($license->activation_count >= $license->activation_limit) {
            error_log($error_log_prefix . "Activation limit reached. Key: {$license_key}, Count: {$license->activation_count}, Limit: {$license->activation_limit}");
            return new WP_REST_Response(['activated' => false, 'error_code' => 'limit_exceeded', 'message' => 'ライセンスの有効化上限数に達しました。'], 403);
        }

        // 6. Record new activation
        $activation_data = [
            'license_id'   => $license->id,
            'site_url'     => $site_url,
            'instance_id'  => $instance_id,
            'activated_at' => current_time('mysql', 1),
            'is_active'    => 1,
            'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent'   => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ];
        $activation_formats = ['%d', '%s', '%s', '%s', '%d', '%s', '%s'];
        $inserted = $wpdb->insert($activations_table, $activation_data, $activation_formats);

        if ($inserted === false) {
            error_log($error_log_prefix . "Failed to insert activation. DB Error: " . $wpdb->last_error);
            return new WP_REST_Response(['activated' => false, 'error_code' => 'db_error', 'message' => '有効化情報の保存中にエラーが発生しました。'], 500);
        }
        $new_activation_id = $wpdb->insert_id; // Get the ID of the new activation record

        // 7. Update activation count on the license
        $wpdb->update(
            $licenses_table,
            ['activation_count' => $license->activation_count + 1],
            ['id' => $license->id],
            ['%d'],
            ['%d']
        );
        error_log($error_log_prefix . "Activation successful. Key: {$license_key}, Site: {$site_url}, New count: " . ($license->activation_count + 1));

        // Success response
        return new WP_REST_Response([
            'activated'   => true,
            'status'      => $license->status,
            'message'     => 'ライセンスが正常に有効化されました。',
            'expires_at'  => $license->expires_at,
            'instance_id' => $instance_id ?: $new_activation_id, // Return instance_id or new activation DB ID
            'activations_remaining' => $license->activation_limit - ($license->activation_count + 1)
        ], 200);
    } // end handle_license_activation

    public function handle_license_deactivation(WP_REST_Request $request) {
        global $wpdb;
        $licenses_table = $wpdb->prefix . EDEL_LISENSE_MANAGER_PREFIX . 'licenses';
        $activations_table = $wpdb->prefix . EDEL_LISENSE_MANAGER_PREFIX . 'license_activations';
        $error_log_prefix = '[Edel License API /deactivate] ';

        $license_key = $request->get_param('license_key');
        $site_url    = $request->get_param('site_url');
        $product_id  = $request->get_param('product_id');
        $instance_id = $request->get_param('instance_id'); // Optional

        error_log($error_log_prefix . "Request received. Key: {$license_key}, Site: {$site_url}, Product: {$product_id}");

        // 1. Find license by key and product_id
        $license = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$licenses_table} WHERE license_key = %s AND product_id = %s",
            $license_key,
            $product_id
        ));

        if (!$license) {
            error_log($error_log_prefix . "Invalid key or product. Key: {$license_key}, Product: {$product_id}");
            return new WP_REST_Response(['deactivated' => false, 'error_code' => 'invalid_key_or_product', 'message' => 'ライセンスキーまたは製品IDが無効です。'], 404); // Or 400 Bad Request
        }

        // 2. Find active activation for this license_id and site_url (and instance_id if provided)
        // Construct WHERE clause components
        $where_clauses = ["license_id = %d", "site_url = %s", "is_active = 1"];
        $where_values = [$license->id, $site_url];

        if (!empty($instance_id)) {
            $where_clauses[] = "instance_id = %s";
            $where_values[] = $instance_id;
        }

        $activation = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$activations_table} WHERE " . implode(' AND ', $where_clauses),
            $where_values
        ));

        if (!$activation) {
            error_log($error_log_prefix . "No active activation found for Key: {$license_key}, Site: {$site_url}" . (!empty($instance_id) ? ", Instance: {$instance_id}" : ""));
            // If no active activation found, it's effectively deactivated from this site's perspective.
            // Client might send this if user deletes key and tries to deactivate.
            return new WP_REST_Response(['deactivated' => true, 'message' => 'このサイトでのライセンスは既に無効化されているか、元々有効化されていません。'], 200);
        }

        // 3. Mark activation as inactive
        $deactivated_db_result = $wpdb->update(
            $activations_table,
            ['is_active' => 0],      // Set is_active to false
            ['id' => $activation->id], // WHERE clause by activation record ID
            ['%d'],                  // Format for data
            ['%d']                   // Format for WHERE
        );

        if ($deactivated_db_result === false) {
            error_log($error_log_prefix . "Failed to update activation record to inactive. DB Error: " . $wpdb->last_error);
            return new WP_REST_Response(['deactivated' => false, 'error_code' => 'db_error', 'message' => '無効化処理中にデータベースエラーが発生しました。'], 500);
        }

        // 4. Decrement activation count on the license
        if ($license->activation_count > 0) { // Ensure count doesn't go below zero
            $new_activation_count = $license->activation_count - 1;
            $wpdb->update(
                $licenses_table,
                ['activation_count' => $new_activation_count],
                ['id' => $license->id],
                ['%d'],
                ['%d']
            );
        } else {
            $new_activation_count = 0; // Should not happen if an active activation was found
        }
        error_log($error_log_prefix . "Deactivation successful. Key: {$license_key}, Site: {$site_url}, New count: {$new_activation_count}");

        // Success response
        return new WP_REST_Response([
            'deactivated'           => true,
            'message'               => 'ライセンスが正常に無効化されました。',
            'license_status'        => $license->status, // The overall status of the license key
            'activation_count'      => $new_activation_count,
            'activations_remaining' => $license->activation_limit - $new_activation_count
        ], 200);
    } // end handle_license_deactivation

    public function handle_license_check(WP_REST_Request $request) {
        global $wpdb;
        $licenses_table = $wpdb->prefix . EDEL_LISENSE_MANAGER_PREFIX . 'licenses';
        $activations_table = $wpdb->prefix . EDEL_LISENSE_MANAGER_PREFIX . 'license_activations';
        $error_log_prefix = '[Edel License API /check] ';

        $license_key = $request->get_param('license_key');
        $site_url    = $request->get_param('site_url');
        $product_id  = $request->get_param('product_id');
        $instance_id = $request->get_param('instance_id');

        error_log($error_log_prefix . "Request received. Key: {$license_key}, Site: {$site_url}, Product: {$product_id}");

        // 1. Find license by key and product_id
        $license = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$licenses_table} WHERE license_key = %s AND product_id = %s",
            $license_key,
            $product_id
        ));

        if (!$license) {
            error_log($error_log_prefix . "Invalid key or product. Key: {$license_key}, Product: {$product_id}");
            return new WP_REST_Response(['valid' => false, 'status' => 'invalid_key_or_product', 'message' => 'ライセンスキーまたは製品IDが無効です。'], 404);
        }

        // 2. Check overall license status (e.g., active, expired, revoked)
        if ($license->status !== 'active') {
            error_log($error_log_prefix . "License not active. Status: {$license->status}, Key: {$license_key}");
            return new WP_REST_Response(['valid' => false, 'status' => $license->status, 'message' => 'ライセンスが有効ではありません (ステータス: ' . esc_html($license->status) . ')。', 'expires_at' => $license->expires_at], 403);
        }

        // 3. Check expiration (if expires_at is set and status is still active - a sanity check)
        if ($license->expires_at && strtotime($license->expires_at) < time()) {
            error_log($error_log_prefix . "License expired. Expires: {$license->expires_at}, Key: {$license_key}");
            // Optionally update status to 'expired' here in licenses table
            // $wpdb->update($licenses_table, ['status' => 'expired'], ['id' => $license->id]);
            return new WP_REST_Response(['valid' => false, 'status' => 'expired', 'message' => 'ライセンスの有効期限が切れています。', 'expires_at' => $license->expires_at], 403);
        }

        // 4. Check if this site is actively activated for this license
        $activation_conditions = array(
            'license_id' => $license->id,
            'site_url'   => $site_url,
            'is_active'  => 1
        );
        $activation_formats = array('%d', '%s', '%d');
        if (!empty($instance_id)) {
            $activation_conditions['instance_id'] = $instance_id;
            $activation_formats[] = '%s';
        }
        $activation = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$activations_table} WHERE " . implode(' AND ', array_map(function ($col) {
                return "$col = %s";
            }, array_keys($activation_conditions))),
            array_values($activation_conditions)
        ));

        if (!$activation) {
            error_log($error_log_prefix . "No active activation found for Key: {$license_key}, Site: {$site_url}");
            return new WP_REST_Response(['valid' => false, 'status' => 'not_activated_on_site', 'message' => 'このサイトではライセンスが有効化されていません。'], 403);
        }

        // All checks passed, license is valid for this site
        error_log($error_log_prefix . "License check successful. Key: {$license_key}, Site: {$site_url}");
        return new WP_REST_Response([
            'valid'       => true,
            'status'      => $license->status, // Should be 'active'
            'message'     => 'ライセンスは有効です。',
            'expires_at'  => $license->expires_at,
            'product_id'  => $license->product_id,
            'data'        => [ // Optional additional data
                'activations_remaining' => $license->activation_limit - $license->activation_count,
            ]
        ], 200);
    } // end handle_license_check

    public function handle_latest_version_request(WP_REST_Request $request) {
        global $wpdb;
        $licenses_table = $wpdb->prefix . EDEL_LISENSE_MANAGER_PREFIX . 'licenses';
        $activations_table = $wpdb->prefix . EDEL_LISENSE_MANAGER_PREFIX . 'license_activations';
        $error_log_prefix = '[Edel License API /latest_version] ';

        // PUCは 'action' => 'get_metadata', 'slug' => 'plugin-slug' を基本として送ってくる
        // $request_action = $request->get_param('action');
        $plugin_slug  = $request->get_param('slug'); // これが product_id に相当すると仮定
        $license_key  = $request->get_param('license_key');
        $site_url     = $request->get_param('site_url');

        error_log($error_log_prefix . "Request received for slug: {$plugin_slug}, Key: {$license_key}, Site: {$site_url}");

        // --- ここにプラグイン情報とアップデート情報を定義 ---
        // 本来はDBや設定ファイルから製品スラッグに応じた情報を取得する
        // 今回は Edel Stripe Payment Pro のみを想定してハードコードする例
        $product_info = null;
        if ($plugin_slug === 'edel-stripe-payment-pro') { // 仮のPro版スラッグ
            $product_info = [
                'name'           => 'Edel Stripe Payment Pro',
                'slug'           => 'edel-stripe-payment-pro', // PUCが期待するスラッグ
                'version'        => '1.1.0', // ★現在の最新バージョン
                'homepage'       => 'https://example.com/edel-stripe-payment-pro', // プラグインのホームページ
                'download_url'   => 'https://example.com/download/edel-stripe-payment-pro-v1.1.0.zip', // ★実際のZIPファイルURL
                'requires'       => '5.8', // WordPress最低バージョン
                'tested'         => '6.5', // WordPressテスト済みバージョン
                'requires_php'   => '7.4',
                'sections'       => [
                    'description' => 'Edel Stripe Payment Proの素晴らしい機能...',
                    'changelog'   => "<h4>Version 1.1.0</h4><ul><li>新機能Xを追加しました。</li><li>バグYを修正しました。</li></ul><h4>Version 1.0.0</h4><ul><li>初期リリース。</li></ul>"
                ],
                // 'banners' => [ 'low' => '...', 'high' => '...' ] // 必要なら
                'needs_license'  => true, // この製品がライセンスチェックを必要とするか
            ];
        } elseif ($plugin_slug === 'edel-stripe-payment-lite') { // 仮のLite版スラッグ
            $product_info = [
                'name'           => 'Edel Stripe Payment Lite',
                'slug'           => 'edel-stripe-payment-lite',
                'version'        => '1.0.5',
                'homepage'       => 'https://example.com/edel-stripe-payment-lite',
                'download_url'   => 'https://example.com/download/edel-stripe-payment-lite-v1.0.5.zip',
                'requires'       => '5.8',
                'tested' => '6.5',
                'requires_php' => '7.4',
                'sections'       => ['description' => 'Lite版の説明...', 'changelog' => "<h4>Version 1.0.5</h4><ul><li>軽微な修正。</li></ul>"],
                'needs_license'  => false, // Lite版はライセンス不要と仮定
            ];
        }

        if (!$product_info) {
            error_log($error_log_prefix . "No product info found for slug: {$plugin_slug}");
            return new WP_REST_Response([], 200); // 空を返すとPUCはアップデートなしと解釈
        }

        // --- ライセンス検証 (needs_license が true の製品の場合) ---
        if ($product_info['needs_license']) {
            if (empty($license_key) || empty($site_url)) {
                error_log($error_log_prefix . "License key or site_url missing for licensed product: {$plugin_slug}");
                return new WP_REST_Response([], 200); // アップデート情報なし
            }

            // /check エンドポイントと同様の検証ロジック
            $license = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$licenses_table} WHERE license_key = %s AND product_id = %s", // product_id は $plugin_slug を使うか別途定義
                $license_key,
                $product_info['slug'] // product_id として slug を使用
            ));

            $is_valid_license = false;
            if ($license && $license->status === 'active' && (!$license->expires_at || strtotime($license->expires_at) >= time())) {
                // ライセンス自体は有効。次にサイトのアクティベーションを確認
                $activation = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$activations_table} WHERE license_id = %d AND site_url = %s AND is_active = 1",
                    $license->id,
                    $site_url
                ));
                if ($activation) {
                    $is_valid_license = true;
                } else {
                    error_log($error_log_prefix . "License not activated for this site. Key: {$license_key}, Site: {$site_url}");
                }
            } else {
                error_log($error_log_prefix . "License invalid or expired. Key: {$license_key}");
            }

            if (!$is_valid_license) {
                error_log($error_log_prefix . "Update check denied due to invalid license for product: {$plugin_slug}, Key: {$license_key}");
                return new WP_REST_Response([], 200); // アップデート情報なし
            }
            error_log($error_log_prefix . "License valid for update check. Product: {$plugin_slug}, Key: {$license_key}");
        }


        // --- PUCが期待する形式でJSONを返す ---
        // download_url はライセンス検証後なので、そのまま返す（実際の運用では一時URLなどを推奨）
        $response_data = [
            'name'          => $product_info['name'],
            'slug'          => $product_info['slug'],
            'version'       => $product_info['version'],
            'homepage'      => $product_info['homepage'],
            'download_url'  => $product_info['download_url'],
            'requires'      => $product_info['requires'],
            'tested'        => $product_info['tested'],
            'requires_php'  => $product_info['requires_php'],
            'sections'      => $product_info['sections'],
        ];
        if (isset($product_info['banners'])) {
            $response_data['banners'] = $product_info['banners'];
        }

        return new WP_REST_Response($response_data, 200);
    } // end handle_latest_version_request
}
