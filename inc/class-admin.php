<?php

/**
 * Edel LIsense Manager Admin Class
 * Handles admin menu, settings page, license management UI and processing.
 */

if (!defined('ABSPATH')) exit(); // Exit if accessed directly

// Ensure List Table class is available before class definition
if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}
// Ensure our custom list table class is available
require_once EDEL_LISENSE_MANAGER_PATH . '/inc/class-license-list-table.php';


class EdelLisenseManagerAdmin {
    /**
     * Registers the top-level admin menu page for the plugin.
     */
    function admin_menu() {
        add_menu_page(
            EDEL_LISENSE_MANAGER_NAME . ' 管理', // Page title
            EDEL_LISENSE_MANAGER_NAME,        // Menu title
            'manage_options',                 // Capability
            EDEL_LISENSE_MANAGER_SLUG,        // Menu slug (used for page query arg)
            array($this, 'show_setting_page'), // Callback function
            'dashicons-vpn-key',              // Icon
            '26.7'                            // Position
        );
        // If we want submenus later, the first one often reuses the parent slug:
        // add_submenu_page(EDEL_LISENSE_MANAGER_SLUG, EDEL_LISENSE_MANAGER_NAME . ' - ライセンス一覧', 'ライセンス一覧', 'manage_options', EDEL_LISENSE_MANAGER_SLUG, array($this, 'show_setting_page'));
        // add_submenu_page(EDEL_LISENSE_MANAGER_SLUG, EDEL_LISENSE_MANAGER_NAME . ' - 設定', 'プラグイン設定', 'manage_options', EDEL_LISENSE_MANAGER_SLUG . '-options', array($this, 'show_plugin_options_page_callback'));
    }

    /**
     * Enqueues admin scripts and styles.
     *
     * @param string $hook The current admin page hook.
     */
    function admin_enqueue($hook) {
        // Only enqueue on our plugin's page
        // Note: $hook for top-level page is 'toplevel_page_YOUR_MENU_SLUG'
        // If using submenus, it would be 'YOUR_PARENT_SLUG_page_YOUR_SUBMENU_SLUG'
        if ($hook === 'toplevel_page_' . EDEL_LISENSE_MANAGER_SLUG || (isset($_GET['page']) && $_GET['page'] === EDEL_LISENSE_MANAGER_SLUG)) {
            $version = (defined('EDEL_LISENSE_MANAGER_DEVELOP') && true === EDEL_LISENSE_MANAGER_DEVELOP) ? time() : EDEL_LISENSE_MANAGER_VERSION;

            wp_enqueue_style(EDEL_LISENSE_MANAGER_SLUG . '-admin', EDEL_LISENSE_MANAGER_URL . '/css/admin.css', array(), $version);
            wp_enqueue_script(EDEL_LISENSE_MANAGER_SLUG . '-admin', EDEL_LISENSE_MANAGER_URL . '/js/admin.js', array('jquery'), $version, true);

            // Localize script for AJAX
            $params = array(
                'ajax_url' => admin_url('admin-ajax.php'),
                // Add any nonces or other data needed by admin.js here
                // 'example_nonce' => wp_create_nonce('example_ajax_nonce_action')
            );
            wp_localize_script(EDEL_LISENSE_MANAGER_SLUG . '-admin', 'edelLicenseAdminParams', $params);
        }
    }

    /**
     * Adds a "Settings" link to the plugin action links.
     *
     * @param array $links Existing plugin action links.
     * @return array Modified links.
     */
    function plugin_action_links($links) {
        $settings_url = '<a href="' . esc_url(admin_url("admin.php?page=" . EDEL_LISENSE_MANAGER_SLUG)) . '">ライセンス管理</a>';
        array_unshift($links, $settings_url);
        return $links;
    }

    public function handle_license_form_submissions() {
        // どのページで処理を行うか、より厳密にするならここでチェック
        // menu_page_url() は admin_menu フック以降でないと使えない場合があるので、
        // admin_init で使う場合は $_GET['page'] の直接比較が確実な場合があります。
        if (!isset($_GET['page']) || $_GET['page'] !== EDEL_LISENSE_MANAGER_SLUG) {
            // EDEL_LISENSE_MANAGER_SLUG はメインプラグインファイルで定義したメニューページのスラッグ
            return;
        }

        global $wpdb;
        $licenses_table = $wpdb->prefix . EDEL_LISENSE_MANAGER_PREFIX . 'licenses';
        $error_log_prefix = '[Edel License Admin Form] ';
        $base_page_url = admin_url('admin.php?page=' . EDEL_LISENSE_MANAGER_SLUG); // 基本のページURL

        // --- ライセンス更新処理 ---
        if (isset($_POST['action']) && $_POST['action'] === 'update_license' && isset($_POST['license_id'])) {
            // Nonce検証 (アクション名にライセンスIDを含める)
            if (!isset($_POST['_wpnonce_edel_update_license']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce_edel_update_license'])), 'edel_update_license_nonce_' . $_POST['license_id'])) {
                wp_die('Nonce verification failed for license update.');
            }

            $license_id     = intval($_POST['license_id']);
            $product_id     = isset($_POST['product_id']) ? sanitize_key($_POST['product_id']) : '';
            $customer_email = isset($_POST['customer_email']) ? sanitize_email($_POST['customer_email']) : '';
            $status         = isset($_POST['status']) ? sanitize_key($_POST['status']) : 'pending'; // Default to pending if not set
            $activation_limit = isset($_POST['activation_limit']) ? intval($_POST['activation_limit']) : 1;
            $expires_at_str   = isset($_POST['expires_at']) ? sanitize_text_field(trim($_POST['expires_at'])) : '';
            $notes            = isset($_POST['notes']) ? sanitize_textarea_field($_POST['notes']) : '';

            $errors = [];
            if (empty($product_id)) {
                $errors[] = '製品IDは必須です。';
            }
            if (!empty($customer_email) && !is_email($customer_email) && $customer_email !== '') {
                $errors[] = '顧客メールアドレスの形式が正しくありません。';
            } // Allow empty email
            if ($activation_limit < 0) {
                $activation_limit = 0;
            } // 0 for unlimited might be an option, or 1 as minimum. For now, allow 0.

            $expires_at = null; // Default to NULL for DATETIME field if empty
            if (!empty($expires_at_str)) {
                $dt = DateTime::createFromFormat('Y-m-d', $expires_at_str);
                if ($dt && $dt->format('Y-m-d') === $expires_at_str) {
                    $expires_at = $dt->format('Y-m-d H:i:s'); // Store as full DATETIME
                } else {
                    $errors[] = '有効期限の日付形式が正しくありません (YYYY-MM-DD)。';
                }
            }

            if (empty($errors)) {
                $update_data = [
                    'product_id'        => $product_id,
                    'customer_email'    => $customer_email,
                    'status'            => $status,
                    'activation_limit'  => $activation_limit,
                    'expires_at'        => $expires_at,
                    'notes'             => $notes,
                    'updated_at'        => current_time('mysql', 1),
                ];
                $update_where = ['id' => $license_id];
                // Data formats: %s for strings (including dates/emails), %d for integers
                $update_data_formats = ['%s', '%s', '%s', '%d', '%s', '%s', '%s'];
                $update_where_formats = ['%d'];

                $updated = $wpdb->update($licenses_table, $update_data, $update_where, $update_data_formats, $update_where_formats);

                if ($updated !== false) {
                    add_settings_error('edel_license_manager_messages', 'license_updated', 'ライセンス情報 (ID: ' . $license_id . ') が更新されました。', 'success');
                } else {
                    add_settings_error('edel_license_manager_messages', 'license_update_failed', 'ライセンス情報の更新に失敗しました。データベースエラー: ' . esc_html($wpdb->last_error), 'error');
                }
            } else {
                // Store validation errors to be displayed
                foreach ($errors as $error) {
                    add_settings_error('edel_license_manager_messages', 'license_update_validation_error', $error, 'error');
                }
            }
            // Redirect back to the main license page after processing
            wp_safe_redirect($base_page_url);
            exit;
        }

        // --- 新規ライセンス追加処理 ---
        if (isset($_POST['action']) && $_POST['action'] === 'add_new_license') {
            if (!isset($_POST['_wpnonce_edel_add_license']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce_edel_add_license'])), 'edel_add_new_license_nonce')) {
                wp_die('Nonce verification failed for adding license.');
            }

            $product_id     = isset($_POST['product_id']) ? sanitize_key($_POST['product_id']) : '';
            $customer_email = isset($_POST['customer_email']) ? sanitize_email($_POST['customer_email']) : '';
            $activation_limit = isset($_POST['activation_limit']) ? intval($_POST['activation_limit']) : 1;
            $expires_at_str   = isset($_POST['expires_at']) ? sanitize_text_field(trim($_POST['expires_at'])) : '';
            $notes            = isset($_POST['notes']) ? sanitize_textarea_field($_POST['notes']) : '';

            $errors = [];
            if (empty($product_id)) {
                $errors[] = '製品IDは必須です。';
            }
            if (!empty($customer_email) && !is_email($customer_email) && $customer_email !== '') {
                $errors[] = '顧客メールアドレスの形式が正しくありません。';
            }
            if ($activation_limit <= 0) {
                $activation_limit = 1;
            }

            $expires_at = null;
            if (!empty($expires_at_str)) {
                $dt = DateTime::createFromFormat('Y-m-d', $expires_at_str);
                if ($dt && $dt->format('Y-m-d') === $expires_at_str) {
                    $expires_at = $dt->format('Y-m-d H:i:s');
                } else {
                    $errors[] = '有効期限の日付形式が正しくありません (YYYY-MM-DD)。';
                }
            }

            if (empty($errors)) {
                // Generate a license key
                $license_key_prefix = strtoupper(str_replace('_', '-', sanitize_key($product_id)));
                $license_key = $license_key_prefix . '-' . strtoupper(bin2hex(random_bytes(8))); // Example: MY-PRODUCT-XXXXXXXXXXXXXX

                // Ensure key is unique (very unlikely to collide, but good practice)
                // while ($wpdb->get_var($wpdb->prepare("SELECT id FROM {$licenses_table} WHERE license_key = %s", $license_key))) {
                //    $license_key = $license_key_prefix . '-' . strtoupper(bin2hex(random_bytes(8)));
                // }

                $insert_data = [
                    'license_key'       => $license_key,
                    'product_id'        => $product_id,
                    'customer_email'    => $customer_email,
                    'status'            => 'active', // Default to active
                    'activation_limit'  => $activation_limit,
                    'activation_count'  => 0,
                    'expires_at'        => $expires_at,
                    'notes'             => $notes,
                    'created_at'        => current_time('mysql', 1),
                    'updated_at'        => current_time('mysql', 1),
                ];
                $insert_formats = ['%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s'];

                $inserted = $wpdb->insert($licenses_table, $insert_data, $insert_formats);

                if ($inserted) {
                    add_settings_error('edel_license_manager_messages', 'license_added', '新しいライセンス (<code>' . esc_html($license_key) . '</code>) が追加されました。', 'success');
                } else {
                    add_settings_error('edel_license_manager_messages', 'license_add_failed', 'ライセンスの追加に失敗しました。データベースエラー: ' . esc_html($wpdb->last_error), 'error');
                }
            } else {
                foreach ($errors as $error) {
                    add_settings_error('edel_license_manager_messages', 'license_add_validation_error', $error, 'error');
                }
            }
            wp_safe_redirect($base_page_url);
            exit;
        }

        if (isset($_GET['action']) && $_GET['action'] === 'revoke_license' && isset($_GET['license_id'])) {
            $license_id_to_revoke = intval($_GET['license_id']);
            // Nonce検証
            if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), EDEL_LISENSE_MANAGER_PREFIX . 'revoke_license_' . $license_id_to_revoke)) {
                wp_die('Nonce verification failed for revoking license.');
            }

            // 権限チェック
            if (!current_user_can('manage_options')) {
                wp_die('権限がありません。');
            }

            error_log($error_log_prefix . 'Revoke requested for License ID: ' . $license_id_to_revoke);

            // 1. ライセンスのステータスを 'revoked' に更新
            $updated_license = $wpdb->update(
                $licenses_table,
                ['status' => 'revoked', 'updated_at' => current_time('mysql', 1)],
                ['id' => $license_id_to_revoke],
                ['%s', '%s'], // data formats
                ['%d']        // where format
            );

            if ($updated_license !== false) {
                // 2. 関連するアクティベーションを全て無効化 (is_active = 0 にする)
                $activations_table = $wpdb->prefix . EDEL_LISENSE_MANAGER_PREFIX . 'license_activations';
                $wpdb->update(
                    $activations_table,
                    ['is_active' => 0],
                    ['license_id' => $license_id_to_revoke],
                    ['%d'], // data format
                    ['%d']  // where format
                );

                // 3. (任意) licensesテーブルのactivation_countを0にするか検討
                // $wpdb->update($licenses_table, ['activation_count' => 0], ['id' => $license_id_to_revoke], ['%d'], ['%d']);

                add_settings_error('edel_license_manager_messages', 'license_revoked', 'ライセンス (ID: ' . $license_id_to_revoke . ') を失効させました。関連する全てのアクティベーションも無効化されました。', 'success');
                error_log($error_log_prefix . "License ID {$license_id_to_revoke} revoked successfully.");
            } else {
                add_settings_error('edel_license_manager_messages', 'license_revoke_failed', 'ライセンスの失効に失敗しました。データベースエラー: ' . esc_html($wpdb->last_error), 'error');
                error_log($error_log_prefix . "Failed to revoke license ID {$license_id_to_revoke}. DB Error: " . $wpdb->last_error);
            }
            wp_safe_redirect($base_page_url);
            exit;
        }
    } // end handle_license_form_submissions


    /**
     * Displays the main admin page content: Add New License Form and License List Table.
     * Also handles form submissions for adding and updating licenses.
     */
    function show_setting_page() {
        global $wpdb;
        $licenses_table = $wpdb->prefix . EDEL_LISENSE_MANAGER_PREFIX . 'licenses';
        $error_log_prefix = '[Edel License Admin Page] ';
        $base_page_url = menu_page_url(EDEL_LISENSE_MANAGER_SLUG, false); // URL for redirects and links

?>
        <div class="wrap">
            <h1><?php echo esc_html(EDEL_LISENSE_MANAGER_NAME); ?> 管理</h1>
            <p>発行済みライセンスの管理と、新しいライセンスの発行を行います。</p>

            <?php settings_errors('edel_license_manager_messages'); // Display notices
            ?>

            <?php
            // --- Display Edit Form OR Add New/List View ---
            if (isset($_GET['action']) && $_GET['action'] === 'edit_license' && isset($_GET['license_id'])) :
                $license_id_to_edit = intval($_GET['license_id']);
                $license = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$licenses_table} WHERE id = %d", $license_id_to_edit));

                if ($license) :
            ?>
                    <h2>ライセンス編集 (ID: <?php echo esc_html($license_id_to_edit); ?>)</h2>
                    <p><a href="<?php echo esc_url($base_page_url); ?>">&laquo; ライセンス一覧に戻る</a></p>

                    <form method="post" action="<?php echo esc_url($base_page_url); ?>">
                        <input type="hidden" name="action" value="update_license">
                        <input type="hidden" name="license_id" value="<?php echo esc_attr($license->id); ?>">
                        <?php wp_nonce_field('edel_update_license_nonce_' . $license->id, '_wpnonce_edel_update_license'); ?>

                        <table class="form-table">
                            <tbody>
                                <tr>
                                    <th scope="row"><label for="license_key_display">ライセンスキー</label></th>
                                    <td>
                                        <input type="text" id="license_key_display" value="<?php echo esc_attr($license->license_key); ?>" class="regular-text" readonly disabled>
                                        <p class="description">ライセンスキーは変更できません。</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="edit_product_id">製品ID</label></th>
                                    <td><input name="product_id" type="text" id="edit_product_id" value="<?php echo esc_attr($license->product_id); ?>" class="regular-text" required></td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="edit_customer_email">顧客メールアドレス</label></th>
                                    <td><input name="customer_email" type="email" id="edit_customer_email" value="<?php echo esc_attr($license->customer_email); ?>" class="regular-text" placeholder="例: user@example.com"></td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="edit_status">ステータス</label></th>
                                    <td>
                                        <select name="status" id="edit_status">
                                            <?php
                                            $statuses = ['active' => '有効 (active)', 'pending' => '保留 (pending)', 'inactive' => '無効 (inactive)', 'expired' => '期限切れ (expired)', 'revoked' => '失効 (revoked)'];
                                            foreach ($statuses as $status_val => $status_label) {
                                                echo '<option value="' . esc_attr($status_val) . '" ' . selected($license->status, $status_val, false) . '>' . esc_html($status_label) . '</option>';
                                            }
                                            ?>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="edit_activation_limit">有効化上限数</label></th>
                                    <td><input name="activation_limit" type="number" step="1" min="0" id="edit_activation_limit" value="<?php echo esc_attr($license->activation_limit); ?>" class="small-text">
                                        <p class="description">現在の有効化数: <?php echo esc_html($license->activation_count); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="edit_expires_at">有効期限</label></th>
                                    <td><input name="expires_at" type="date" id="edit_expires_at" value="<?php echo esc_attr($license->expires_at ? date('Y-m-d', strtotime($license->expires_at)) : ''); ?>" class="regular-text" placeholder="YYYY-MM-DD">
                                        <p class="description">空欄の場合は無期限。</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="edit_notes">メモ</label></th>
                                    <td><textarea name="notes" id="edit_notes" rows="3" class="large-text"><?php echo esc_textarea($license->notes); ?></textarea></td>
                                </tr>
                            </tbody>
                        </table>
                        <p class="submit">
                            <input type="submit" name="submit_update_license" id="submit_update_license" class="button button-primary" value="ライセンスを更新">
                            <a href="<?php echo esc_url($base_page_url); ?>" class="button">キャンセル</a>
                        </p>
                    </form>
        </div>
    <?php
                else : // License not found for editing
    ?>
        <div class="wrap">
            <h1>ライセンス編集</h1>
            <div class="notice notice-error">
                <p>編集対象のライセンスが見つかりませんでした。</p>
            </div>
            <p><a href="<?php echo esc_url($base_page_url); ?>">&laquo; ライセンス一覧に戻る</a></p>
        </div>
    <?php
                endif; // end if $license

            else : // Not an edit action, show Add New form and List Table
    ?>
    <hr style="margin-top: 30px;">
    <h2>新規ライセンス発行</h2>
    <form method="post" action="<?php echo esc_url($base_page_url); ?>">
        <input type="hidden" name="action" value="add_new_license">
        <?php wp_nonce_field('edel_add_new_license_nonce', '_wpnonce_edel_add_license'); ?>
        <table class="form-table">
            <tbody>
                <tr>
                    <th scope="row"><label for="add_product_id">製品ID</label></th>
                    <td><input name="product_id" type="text" id="add_product_id" value="" class="regular-text" placeholder="例: edel-stripe-payment-pro" required>
                        <p class="description">ライセンスを発行する製品のスラッグまたはID。</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="add_customer_email">顧客メールアドレス (任意)</label></th>
                    <td><input name="customer_email" type="email" id="add_customer_email" value="" class="regular-text" placeholder="例: user@example.com"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="add_activation_limit">有効化上限数</label></th>
                    <td><input name="activation_limit" type="number" step="1" min="1" id="add_activation_limit" value="1" class="small-text">
                        <p class="description">このライセンスキーで有効化できるサイト数の上限。</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="add_expires_at">有効期限 (任意)</label></th>
                    <td><input name="expires_at" type="date" id="add_expires_at" value="" class="regular-text" placeholder="YYYY-MM-DD">
                        <p class="description">空欄の場合は無期限となります。</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="add_notes">メモ (任意)</label></th>
                    <td><textarea name="notes" id="add_notes" rows="3" class="large-text"></textarea></td>
                </tr>
            </tbody>
        </table>
        <p class="submit"><input type="submit" name="submit_new_license" id="submit_new_license" class="button button-primary" value="新規ライセンスを発行"></p>
    </form>
    <hr style="margin-top: 30px;">

    <h2>発行済みライセンス一覧</h2>
    <?php
                // Instantiate and display the License List Table
                $license_list_table = new Edel_License_List_Table();
                $license_list_table->prepare_items();
    ?>
    <form method="get" id="license-list-main-form"> <?php // Changed to GET for filtering/sorting by WP_List_Table defaults
                                                    ?>
        <input type="hidden" name="page" value="<?php echo esc_attr($_REQUEST['page'] ?? EDEL_LISENSE_MANAGER_SLUG); ?>" />
        <?php
                // $license_list_table->search_box('ライセンス検索', 'license_search'); // Search box can be added later
                $license_list_table->display();
        ?>
    </form>
<?php endif; // end if action=edit_license
?>
</div> <?php // end .wrap
        ?>
<?php
    } // end show_setting_page


    /**
     * Creates the custom database tables on plugin activation.
     * (Static method, as it was before)
     */
    public static function create_database_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        // --- licenses テーブル ---
        $table_name_licenses = $wpdb->prefix . EDEL_LISENSE_MANAGER_PREFIX . 'licenses';
        $sql_licenses = "CREATE TABLE $table_name_licenses (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            license_key varchar(255) NOT NULL,
            product_id varchar(100) NOT NULL,
            customer_email varchar(255) DEFAULT NULL,
            order_id varchar(100) DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            activation_limit int(11) NOT NULL DEFAULT 1,
            activation_count int(11) NOT NULL DEFAULT 0,
            expires_at datetime DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            notes text DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY license_key (license_key),
            KEY product_id (product_id),
            KEY status (status),
            KEY customer_email (customer_email)
        ) $charset_collate;";
        dbDelta($sql_licenses);
        error_log(EDEL_LISENSE_MANAGER_NAME . " DB Check: Table " . $table_name_licenses);


        // --- license_activations テーブル ---
        $table_name_activations = $wpdb->prefix . EDEL_LISENSE_MANAGER_PREFIX . 'license_activations';
        $sql_activations = "CREATE TABLE $table_name_activations (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            license_id mediumint(9) NOT NULL,
            site_url varchar(255) NOT NULL,
            instance_id varchar(255) DEFAULT NULL,
            activated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            user_agent varchar(255) DEFAULT NULL,
            ip_address varchar(45) DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY license_id (license_id),
            KEY site_url (site_url)
        ) $charset_collate;";
        dbDelta($sql_activations);
        error_log(EDEL_LISENSE_MANAGER_NAME . " DB Check: Table " . $table_name_activations);
    } // end create_database_tables

} // End Class EdelLisenseManagerAdmin