<?php
// inc/class-license-list-table.php

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Class Edel_License_List_Table
 * Renders the list table for licenses.
 */
class Edel_License_List_Table extends WP_List_Table {

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct([
            'singular' => 'License',
            'plural'   => 'Licenses',
            'ajax'     => false // No AJAX for this table initially
        ]);
    }

    /**
     * Get the list of columns.
     */
    public function get_columns() {
        $columns = [
            'cb'                => '<input type="checkbox" />', // For bulk actions
            'license_key'       => 'ライセンスキー',
            'product_id'        => '製品ID',
            'customer_email'    => '顧客メールアドレス',
            'status'            => 'ステータス',
            'activation_count'  => '有効化数',
            'activation_limit'  => '上限',
            'expires_at'        => '有効期限',
            'created_at'        => '発行日',
            'actions'           => '操作',
        ];
        return $columns;
    }

    /**
     * Get the list of sortable columns.
     */
    protected function get_sortable_columns() {
        $sortable_columns = array(
            'license_key'       => array('license_key', false),
            'product_id'        => array('product_id', false),
            'customer_email'    => array('customer_email', false),
            'status'            => array('status', false),
            'expires_at'        => array('expires_at', false),
            'created_at'        => array('created_at', true), // Default sort by created_at desc
        );
        return $sortable_columns;
    }

    /**
     * Prepare the items for the table to process.
     * Fetches data from the custom 'licenses' database table.
     */
    public function prepare_items() {
        global $wpdb;
        $table_name = $wpdb->prefix . EDEL_LISENSE_MANAGER_PREFIX . 'licenses';

        $this->_column_headers = array($this->get_columns(), array(), $this->get_sortable_columns());

        // Pagination parameters
        $per_page = $this->get_items_per_page('licenses_per_page', 20);
        $current_page = $this->get_pagenum();
        $offset = ($current_page - 1) * $per_page;

        // Sorting parameters
        $orderby = 'created_at'; // Default orderby column
        if (isset($_REQUEST['orderby']) && array_key_exists($_REQUEST['orderby'], $this->get_sortable_columns())) {
            $orderby = sanitize_key($_REQUEST['orderby']);
        }
        $order = 'DESC'; // Default order direction
        if (isset($_REQUEST['order']) && in_array(strtoupper($_REQUEST['order']), ['ASC', 'DESC'], true)) {
            $order = strtoupper($_REQUEST['order']);
        }

        // Get Total Items Count (consider search/filtering later)
        $sql_total = "SELECT COUNT(id) FROM {$table_name}";
        $total_items = $wpdb->get_var($sql_total);

        // Get Data for Current Page
        $sql_data = $wpdb->prepare(
            "SELECT * FROM {$table_name}
             ORDER BY {$orderby} {$order}
             LIMIT %d OFFSET %d",
            $per_page,
            $offset
        );
        $this->items = $wpdb->get_results($sql_data, ARRAY_A);

        // Set Pagination Arguments
        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil($total_items / $per_page)
        ]);
    }

    /**
     * Handles the default column output.
     */
    protected function column_default($item, $column_name) {
        switch ($column_name) {
            case 'license_key':
            case 'product_id':
            case 'customer_email':
            case 'status':
                return esc_html($item[$column_name] ?? '');
            case 'activation_count':
            case 'activation_limit':
                return intval($item[$column_name]);
            case 'expires_at':
            case 'created_at':
                return !empty($item[$column_name]) ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($item[$column_name])) : '---';
            default:
                return print_r($item, true); // Show the whole array for troubleshooting
        }
    }

    protected function column_actions($item) {
        $license_id = intval($item['id']); // Ensure it's an integer
        $page_slug = $_REQUEST['page'] ?? EDEL_LISENSE_MANAGER_SLUG; // Current admin page slug
        $base_admin_url = admin_url('admin.php?page=' . $page_slug);

        $actions = array(); // Initialize an empty array for actions

        // --- Edit Action Link ---
        $edit_nonce_action = 'edel_edit_license_nonce_action_' . $license_id; // More specific nonce action
        $edit_nonce = wp_create_nonce($edit_nonce_action);
        $edit_url = add_query_arg(
            array(
                'action'     => 'edit_license',
                'license_id' => $license_id,
                '_wpnonce'   => $edit_nonce // Add nonce to edit link for security
            ),
            $base_admin_url
        );
        $actions['edit'] = sprintf('<a href="%s">編集</a>', esc_url($edit_url));


        // --- Revoke Action Link ---
        $current_status = strtolower($item['status'] ?? '');
        $non_revokable_statuses = ['revoked', 'expired', 'inactive']; // Statuses where 'revoke' might not make sense

        if (!in_array($current_status, $non_revokable_statuses, true)) {
            $revoke_nonce_action = EDEL_LISENSE_MANAGER_PREFIX . 'revoke_license_' . $license_id;
            $revoke_nonce = wp_create_nonce($revoke_nonce_action);
            $revoke_url = add_query_arg(
                array(
                    'action'     => 'revoke_license',
                    'license_id' => $license_id,
                    '_wpnonce'   => $revoke_nonce,
                ),
                $base_admin_url
            );
            $actions['revoke'] = sprintf(
                '<a href="%s" style="color:red;" onclick="return confirm(\'本当にこのライセンスを失効させますか？関連する全てのアクティベーションも無効化されます。\');">失効</a>',
                esc_url($revoke_url)
            );
        }

        // Use $this->row_actions() to generate the hover links
        return $this->row_actions($actions);
    } // end column_actions

    /**
     * Renders the checkbox column.
     */
    protected function column_cb($item) {
        return sprintf(
            '<input type="checkbox" name="license[]" value="%s" />',
            $item['id']
        );
    }

    /**
     * Optional: Message to be displayed when there are no items
     */
    public function no_items() {
        _e('ライセンス情報は見つかりませんでした。', EDEL_LISENSE_MANAGER_SLUG); // Text domain for translation
    }

    // TODO: Add bulk actions handling if needed
    // TODO: Add actions column (edit, delete, revoke license) later

} // End class