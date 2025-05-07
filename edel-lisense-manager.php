<?php

/**
 * Plugin Name: Edel LIsense Manager
 * Plugin URI:
 * Description: 有料プラグインのライセンスを管理します。
 * Version: 1.0
 * Author: yabea
 * Author URI:
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) exit();

$info = get_file_data(__FILE__, array('plugin_name' => 'Plugin Name', 'version' => 'Version'));

define('EDEL_LISENSE_MANAGER_URL', plugins_url('', __FILE__));
define('EDEL_LISENSE_MANAGER_PATH', dirname(__FILE__));
define('EDEL_LISENSE_MANAGER_NAME', $info['plugin_name']);
define('EDEL_LISENSE_MANAGER_SLUG', 'edel-lisense-manager');
define('EDEL_LISENSE_MANAGER_PREFIX', 'edel_lisense_manager_'); // ★テーブル名などに使う接頭辞
define('EDEL_LISENSE_MANAGER_VERSION', $info['version']);
define('EDEL_LISENSE_MANAGER_DEVELOP', true);

class EdelLisenseManager {
    private $admin_instance; // ★ Adminインスタンスを保持するプロパティ
    private $front_instance; // ★ Frontインスタンスを保持するプロパティ (将来用)

    public function __construct() { // ★ コンストラクタを追加
        require_once EDEL_LISENSE_MANAGER_PATH . '/inc/class-admin.php';
        $this->admin_instance = new EdelLisenseManagerAdmin();

        require_once EDEL_LISENSE_MANAGER_PATH . '/inc/class-front.php'; // 今は空でも読み込んでおく
        $this->front_instance = new EdelLisenseManagerFront();
    }

    public function init() {
        // 管理画面側の処理
        add_action('admin_menu', array($this->admin_instance, 'admin_menu'));
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this->admin_instance, 'plugin_action_links'));
        add_action('admin_enqueue_scripts', array($this->admin_instance, 'admin_enqueue')); // ★コメント解除

        add_action('admin_init', array($this->admin_instance, 'handle_license_form_submissions'));
        // フロントエンドの処理 (必要に応じて)
        add_action('wp_enqueue_scripts', array($this->front_instance, 'front_enqueue')); // ★コメント解除

        // ★ REST APIエンドポイント登録フックを追加 (class-front.php にメソッドを作成する想定)
        add_action('rest_api_init', array($this->front_instance, 'register_license_api_routes'));
    }

    /**
     * ★新規追加: プラグイン有効化時の処理
     */
    public static function activate() {
        require_once EDEL_LISENSE_MANAGER_PATH . '/inc/class-admin.php'; // adminクラスを読み込み
        // 静的メソッドとして呼び出すか、インスタンスを作成して呼び出す
        // ここでは静的メソッドとして呼び出す例（Adminクラス側に静的メソッドとして作成）
        EdelLisenseManagerAdmin::create_database_tables();
        error_log(EDEL_LISENSE_MANAGER_NAME . ' plugin activated and tables checked/created.');
    }

    /**
     * ★新規追加: プラグイン無効化時の処理（任意）
     */
    public static function deactivate() {
        error_log(EDEL_LISENSE_MANAGER_NAME . ' plugin deactivated.');
        // 必要であれば無効化時の処理を記述
    }
}

$edelLisenseManagerInstance = new EdelLisenseManager(); // ★ インスタンス作成を修正
add_action('plugins_loaded', array($edelLisenseManagerInstance, 'init')); // ★ plugins_loadedフックに変更

// ★ 有効化・無効化フックの登録
register_activation_hook(__FILE__, array('EdelLisenseManager', 'activate'));
register_deactivation_hook(__FILE__, array('EdelLisenseManager', 'deactivate'));
