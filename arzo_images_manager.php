<?php

/**
 * Plugin Name: Arzo Images Manager
 * Plugin URI: https://yasirshabbir.com
 * Description: Register missing images from custom folder with advanced duplicate detection and detailed history tracking
 * Version: 2.0
 * Author: Yasir Shabbir
 * Author URI: https://yasirshabbir.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: arzo-images-manager
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// ============================================================================
// CONSTANTS
// ============================================================================
define('AIM_VERSION', '2.0');
define('AIM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AIM_PLUGIN_URL', plugin_dir_url(__FILE__));

// ============================================================================
// DATABASE TABLE CREATION
// ============================================================================

register_activation_hook(__FILE__, 'aim_create_history_table');

function aim_create_history_table()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'image_registration_history';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        filename varchar(255) NOT NULL,
        file_path text NOT NULL,
        file_size bigint(20) NOT NULL,
        file_type varchar(100) NOT NULL,
        status varchar(50) NOT NULL,
        attachment_id bigint(20) DEFAULT NULL,
        reason text DEFAULT NULL,
        reason_detail varchar(255) DEFAULT NULL,
        registered_date datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY filename (filename),
        KEY status (status),
        KEY registered_date (registered_date)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

add_action('admin_init', 'aim_ensure_reason_detail_column');

function aim_ensure_reason_detail_column()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'image_registration_history';
    $column = $wpdb->get_var($wpdb->prepare(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
        $wpdb->dbname,
        $table_name,
        'reason_detail'
    ));
    if (!$column) {
        $wpdb->query("ALTER TABLE $table_name ADD COLUMN reason_detail varchar(255) DEFAULT NULL AFTER reason");
    }
}

// ============================================================================
// ADMIN MENU
// ============================================================================

add_action('admin_menu', 'aim_add_admin_menu');

function aim_add_admin_menu()
{
    add_menu_page(
        'Arzo Images Manager',
        'Arzo Images',
        'manage_options',
        'arzo-images-manager',
        'aim_admin_page',
        'dashicons-images-alt2',
        30
    );
}

// ============================================================================
// OPTIONS
// ============================================================================

function aim_get_image_directory()
{
    $custom_dir = get_option('aim_image_directory', '');
    $upload_dir = wp_upload_dir();
    $path = trailingslashit($upload_dir['basedir']);

    if (!empty($custom_dir)) {
        $path .= trim($custom_dir, '/') . '/';
    }

    return $path;
}

function aim_save_settings()
{
    if (isset($_POST['aim_save_settings']) && check_admin_referer('aim_settings_nonce')) {
        $directory = sanitize_text_field($_POST['aim_image_directory']);
        update_option('aim_image_directory', $directory);

        // Auto-Registration Settings
        $auto_register = isset($_POST['aim_auto_register_enabled']) ? 1 : 0;
        $limit = intval($_POST['aim_auto_register_limit']);

        update_option('aim_auto_register_enabled', $auto_register);
        update_option('aim_auto_register_limit', $limit);

        // Handle Cron Scheduling
        if ($auto_register) {
            if (!wp_next_scheduled('aim_cron_auto_register')) {
                wp_schedule_event(time(), 'hourly', 'aim_cron_auto_register');
            }
        } else {
            $timestamp = wp_next_scheduled('aim_cron_auto_register');
            if ($timestamp) {
                wp_unschedule_event($timestamp, 'aim_cron_auto_register');
            }
        }

        return '<div class="aim-notice aim-notice-success">Settings saved successfully!</div>';
    }
    return '';
}

// ============================================================================
// ADMIN PAGE UI
// ============================================================================

function aim_admin_page()
{
    global $wpdb;
    $history_table = $wpdb->prefix . 'image_registration_history';

    $notice = aim_save_settings();
    $stats = aim_get_statistics();
    $current_dir = get_option('aim_image_directory', '');

?>
<div class="aim-wrap">
    <link href="https://fonts.googleapis.com/css2?family=Lato:wght@300;400;700;900&display=swap" rel="stylesheet">

    <style>
    :root {
        --accent-color: #16e791;
        --primary-text: #ffffff;
        --secondary-text: #e0e0e0;
        --background-dark: #121212;
        --background-medium: #1e1e1e;
        --background-light: #2a2a2a;
        --border-color: #333333;
        --border-light: #444444;
        --success-color: #28a745;
        --warning-color: #ffc107;
        --danger-color: #dc3545;
        --info-color: #17a2b8;
        --secondary-color: #6c757d;
    }

    /* Hide WordPress admin footer */
    #wpfooter,
    #footer-thankyou,
    #footer-upgrade {
        display: none !important;
    }

    /* Override WordPress admin background */
    body.wp-admin {
        background: var(--background-dark) !important;
    }

    #wpcontent,
    #wpbody,
    #wpbody-content {
        background: var(--background-dark) !important;
    }

    .aim-wrap {
        font-family: 'Lato', sans-serif;
        background: var(--background-dark);
        color: var(--primary-text);
        padding: 20px;
        margin-left: -20px;
        min-height: 100vh;
    }

    .aim-header {
        background: var(--background-medium);
        padding: 30px;
        border-radius: 3px;
        border-left: 4px solid var(--accent-color);
        margin-bottom: 30px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .aim-header h1 {
        color: var(--primary-text);
        font-size: 28px;
        font-weight: 700;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .aim-header h1 .dashicons {
        color: var(--accent-color);
        font-size: 32px;
        width: 32px;
        height: 32px;
    }

    .aim-version {
        background: var(--background-light);
        padding: 6px 12px;
        border-radius: 3px;
        font-size: 12px;
        color: var(--secondary-text);
        border: 1px solid var(--border-color);
    }

    .aim-stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }

    .aim-stat-card {
        background: var(--background-medium);
        padding: 24px;
        border-radius: 3px;
        border-left: 4px solid var(--accent-color);
        transition: transform 0.2s;
    }

    .aim-stat-card:hover {
        transform: translateY(-2px);
    }

    .aim-stat-card.success {
        border-left-color: var(--success-color);
    }

    .aim-stat-card.warning {
        border-left-color: var(--warning-color);
    }

    .aim-stat-card.danger {
        border-left-color: var(--danger-color);
    }

    .aim-stat-card.info {
        border-left-color: var(--info-color);
    }

    .aim-stat-title {
        font-size: 13px;
        color: var(--secondary-text);
        margin-bottom: 12px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        font-weight: 700;
    }

    .aim-stat-value {
        font-size: 36px;
        font-weight: 900;
        color: var(--primary-text);
        margin-bottom: 8px;
    }

    .aim-stat-label {
        font-size: 12px;
        color: var(--secondary-text);
    }

    .aim-panel {
        background: var(--background-medium);
        padding: 30px;
        border-radius: 3px;
        margin-bottom: 30px;
        border: 1px solid var(--border-color);
    }

    .aim-panel h2 {
        color: var(--primary-text);
        font-size: 20px;
        font-weight: 700;
        margin-top: 0;
        margin-bottom: 20px;
        padding-bottom: 15px;
        border-bottom: 2px solid var(--border-color);
    }

    .aim-form-group {
        margin-bottom: 20px;
    }

    .aim-form-label {
        display: block;
        color: var(--primary-text);
        font-weight: 700;
        margin-bottom: 8px;
        font-size: 14px;
    }

    .aim-form-input {
        width: 100%;
        max-width: 520px;
        padding: 12px 16px;
        height: 46px;
        background: var(--background-light) !important;
        border: 1px solid var(--border-color) !important;
        border-radius: 6px;
        color: var(--primary-text) !important;
        font-family: 'Lato', sans-serif;
        font-size: 15px;
        transition: border-color 0.2s, box-shadow 0.2s;
    }

    .aim-form-input:focus {
        outline: none;
        border-color: var(--accent-color) !important;
        box-shadow: 0 0 0 2px rgba(22, 231, 145, 0.15);
    }

    .aim-form-input::placeholder {
        color: var(--secondary-text);
        opacity: 0.8;
    }

    select.aim-form-input {
        height: 46px;
        padding: 10px 12px;
        background: var(--background-light);
        color: var(--primary-text);
        border: 1px solid var(--border-color);
    }

    input[type="date"].aim-form-input,
    input[type="number"].aim-form-input {
        height: 46px;
    }

    .aim-form-inline {
        background: var(--background-medium);
        border: 1px solid var(--border-color);
        border-radius: 4px;
        padding: 12px;
    }

    .aim-form-inline .aim-form-input {
        background: var(--background-light);
        color: var(--primary-text);
        border: 1px solid var(--border-color);
        height: 46px;
        font-size: 15px;
    }

    .aim-panel .aim-form-input {
        background: var(--background-light) !important;
        color: var(--primary-text) !important;
        border: 1px solid var(--border-color) !important;
    }

    .aim-form-inline .aim-btn {
        background: var(--background-light);
        color: var(--primary-text);
        border: 1px solid var(--border-color);
    }

    #history-clear-filters.aim-btn {
        background: var(--danger-color);
        color: var(--primary-text);
        border: 1px solid var(--danger-color);
    }

    #history-clear-filters.aim-btn:hover {
        filter: brightness(1.1);
    }

    #history-search.aim-form-input {
        height: 48px;
        font-size: 15px;
    }

    .aim-form-help {
        font-size: 12px;
        color: var(--secondary-text);
        margin-top: 6px;
    }

    .aim-btn {
        padding: 12px 24px;
        border-radius: 3px;
        border: none;
        font-family: 'Lato', sans-serif;
        font-size: 14px;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    .aim-btn:hover {
        transform: translateY(-1px);
    }

    .aim-btn:disabled {
        opacity: 0.5;
        cursor: not-allowed;
        transform: none;
    }

    .aim-btn-primary {
        background: var(--accent-color);
        color: var(--background-dark);
    }

    .aim-btn-secondary {
        background: var(--background-light);
        color: var(--primary-text);
        border: 1px solid var(--border-color);
    }

    .aim-btn-danger {
        background: var(--danger-color);
        color: var(--primary-text);
    }

    .aim-btn-group {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
    }

    .aim-btn-info {
        background: var(--info-color);
        color: var(--primary-text);
    }

    .aim-btn-warning {
        background: var(--warning-color);
        color: var(--background-dark);
    }

    .aim-progress-section {
        margin-top: 24px;
        padding-top: 24px;
        border-top: 1px solid var(--border-color);
    }

    .aim-progress-info {
        color: var(--secondary-text);
        margin-bottom: 12px;
        font-size: 14px;
    }

    .aim-progress-info strong {
        color: var(--primary-text);
    }

    .aim-progress-bar {
        height: 32px;
        background: var(--background-light);
        border-radius: 3px;
        overflow: hidden;
        border: 1px solid var(--border-color);
    }

    .aim-progress-fill {
        height: 100%;
        background: linear-gradient(90deg, var(--accent-color), var(--success-color));
        transition: width 0.3s;
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--background-dark);
        font-weight: 700;
        font-size: 12px;
        min-width: 50px;
        /* Ensure text is visible even at low percentages */
        white-space: nowrap;
    }

    .aim-status-message {
        margin-top: 16px;
        font-weight: 700;
        font-size: 14px;
    }

    .aim-status-counts {
        margin-top: 12px;
        display: flex;
        gap: 24px;
        font-size: 14px;
    }

    .aim-table-wrapper {
        overflow-x: auto;
        border-radius: 3px;
        border: 1px solid var(--border-color);
    }

    .aim-table {
        width: 100%;
        border-collapse: collapse;
        background: var(--background-medium);
    }

    .aim-table th {
        background: var(--background-light);
        color: var(--primary-text);
        padding: 12px 16px;
        text-align: left;
        font-weight: 700;
        font-size: 13px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        border-bottom: 2px solid var(--border-color);
    }

    .aim-table td {
        padding: 12px 16px;
        color: var(--secondary-text);
        border-bottom: 1px solid var(--border-color);
        font-size: 13px;
    }

    .aim-table tbody tr:hover {
        background: var(--background-light);
    }

    .aim-badge {
        padding: 4px 10px;
        border-radius: 3px;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        display: inline-block;
    }

    .aim-badge-registered {
        background: rgba(40, 167, 69, 0.2);
        color: var(--success-color);
        border: 1px solid var(--success-color);
    }

    .aim-badge-skipped {
        background: rgba(255, 193, 7, 0.2);
        color: var(--warning-color);
        border: 1px solid var(--warning-color);
    }

    .aim-badge-error {
        background: rgba(220, 53, 69, 0.2);
        color: var(--danger-color);
        border: 1px solid var(--danger-color);
    }

    .aim-notice {
        padding: 12px 16px;
        border-radius: 3px;
        margin-bottom: 20px;
        font-weight: 700;
        font-size: 14px;
    }

    .aim-notice-success {
        background: rgba(40, 167, 69, 0.2);
        color: var(--success-color);
        border: 1px solid var(--success-color);
    }

    @keyframes spin {
        to {
            transform: rotate(360deg);
        }
    }

    .spin {
        animation: spin 1s linear infinite;
    }

    .aim-empty-state {
        text-align: center;
        padding: 40px;
        color: var(--secondary-text);
    }

    .aim-code {
        background: var(--background-dark);
        padding: 4px 8px;
        border-radius: 3px;
        font-family: 'Courier New', monospace;
        font-size: 11px;
        color: var(--accent-color);
    }

    .aim-pagination {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 20px 0;
        margin-top: 20px;
        border-top: 1px solid var(--border-color);
    }

    .aim-pagination-info {
        color: var(--secondary-text);
        font-size: 14px;
    }

    .aim-pagination-info strong {
        color: var(--primary-text);
    }

    .aim-pagination-controls {
        display: flex;
        gap: 12px;
    }

    .aim-pagination-btn {
        padding: 8px 16px;
        background: var(--background-light);
        border: 1px solid var(--border-color);
        border-radius: 3px;
        color: var(--primary-text);
        font-family: 'Lato', sans-serif;
        font-size: 13px;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    .aim-pagination-btn:hover:not(:disabled) {
        background: var(--background-medium);
        border-color: var(--accent-color);
        transform: translateY(-1px);
    }

    .aim-pagination-btn:disabled {
        opacity: 0.3;
        cursor: not-allowed;
    }

    .aim-loading {
        opacity: 0.5;
        pointer-events: none;
    }

    /* Collapsible Panel Styles */
    .aim-panel-collapsible {
        border: 1px solid var(--border-color);
    }

    .aim-panel-header {
        background: var(--background-medium);
        padding: 20px 30px;
        cursor: pointer;
        display: flex;
        justify-content: space-between;
        align-items: center;
        transition: all 0.3s;
        border-radius: 3px;
        user-select: none;
    }

    .aim-panel-header:hover {
        background: var(--background-light);
    }

    .aim-panel-header h2 {
        margin: 0;
        padding: 0;
        border: none;
        font-size: 20px;
        font-weight: 700;
        color: var(--primary-text);
    }

    .aim-panel-toggle {
        font-size: 24px;
        transition: transform 0.3s;
        color: var(--accent-color);
    }

    .aim-panel-toggle.expanded {
        transform: rotate(180deg);
    }

    .aim-panel-content {
        max-height: 0;
        overflow: hidden;
        transition: max-height 0.3s ease-out;
        background: var(--background-medium);
    }

    .aim-panel-content.expanded {
        max-height: 500px;
        transition: max-height 0.4s ease-in;
    }

    .aim-panel-content-inner {
        padding: 30px;
    }

    /* Toggle Switch */
    .aim-switch-group {
        display: flex;
        align-items: center;
        gap: 15px;
        margin-bottom: 20px;
    }

    .aim-switch {
        position: relative;
        display: inline-block;
        width: 50px;
        height: 24px;
    }

    .aim-switch input {
        opacity: 0;
        width: 0;
        height: 0;
    }

    .aim-slider {
        position: absolute;
        cursor: pointer;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-color: var(--background-light);
        transition: .4s;
        border-radius: 34px;
        border: 1px solid var(--border-color);
    }

    .aim-slider:before {
        position: absolute;
        content: "";
        height: 16px;
        width: 16px;
        left: 3px;
        bottom: 3px;
        background-color: var(--secondary-text);
        transition: .4s;
        border-radius: 50%;
    }

    input:checked+.aim-slider {
        background-color: var(--accent-color);
        border-color: var(--accent-color);
    }

    input:checked+.aim-slider:before {
        transform: translateX(26px);
        background-color: #fff;
    }

    .aim-modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.6);
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 100000;
    }

    .aim-modal-overlay.aim-modal-show {
        display: flex;
    }

    .aim-modal {
        background: var(--background-medium);
        border: 1px solid var(--border-color);
        border-radius: 4px;
        max-width: 480px;
        width: 90%;
        padding: 20px;
        color: var(--primary-text);
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.4);
    }

    .aim-modal-title {
        font-size: 18px;
        font-weight: 700;
        margin-bottom: 10px;
        color: var(--primary-text);
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .aim-modal-body {
        color: var(--secondary-text);
        margin-bottom: 16px;
        font-size: 14px;
    }

    .aim-modal-actions {
        display: flex;
        gap: 10px;
        justify-content: flex-end;
    }

    .aim-modal-actions .aim-btn {
        padding: 10px 18px;
    }
    </style>

    <?php echo $notice; ?>

    <div class="aim-header">
        <h1>
            <span class="dashicons dashicons-images-alt2"></span>
            Arzo Images Manager
        </h1>
        <div class="aim-version">v<?php echo AIM_VERSION; ?></div>
    </div>

    <!-- Statistics Dashboard -->
    <div class="aim-stats-grid">
        <div class="aim-stat-card info">
            <div class="aim-stat-title">📁 Total Images</div>
            <div class="aim-stat-value"><?php echo number_format($stats['total_files']); ?></div>
            <div class="aim-stat-label">In images directory</div>
        </div>

        <div class="aim-stat-card success">
            <div class="aim-stat-title">✅ Registered</div>
            <div class="aim-stat-value"><?php echo number_format($stats['registered']); ?></div>
            <div class="aim-stat-label">Successfully added</div>
        </div>

        <div class="aim-stat-card warning">
            <div class="aim-stat-title">⏭️ Skipped</div>
            <div class="aim-stat-value"><?php echo number_format($stats['skipped']); ?></div>
            <div class="aim-stat-label">Already exist</div>
        </div>

        <div class="aim-stat-card danger">
            <div class="aim-stat-title">⏳ Unprocessed</div>
            <div class="aim-stat-value"><?php echo number_format($stats['unprocessed']); ?></div>
            <div class="aim-stat-label">Not yet processed</div>
        </div>
    </div>


    <!-- Control Panel -->
    <div class="aim-panel">
        <h2>🎮 Control Panel</h2>

        <div class="aim-form-group">
            <label class="aim-form-label">Register Specific Image (Optional)</label>
            <input type="text" id="specific-filename" class="aim-form-input"
                placeholder="image-1 or image-1.jpg or image-1.png">
            <div class="aim-form-help">
                Leave empty to process all images. Enter filename with or without extension to target specific files.
            </div>
        </div>

        <div class="aim-btn-group">
            <button id="start-registering" class="aim-btn aim-btn-primary">
                <span class="dashicons dashicons-update"></span> Start Bulk Registration
            </button>
            <button id="pause-operation" class="aim-btn aim-btn-warning" style="display: none;">
                <span class="dashicons dashicons-controls-pause"></span> Pause
            </button>
            <button id="resume-operation" class="aim-btn aim-btn-info" style="display: none;">
                <span class="dashicons dashicons-controls-play"></span> Resume
            </button>
            <button id="cancel-operation" class="aim-btn aim-btn-danger" style="display: none;">
                <span class="dashicons dashicons-no"></span> Cancel
            </button>
            <button id="register-specific" class="aim-btn aim-btn-secondary">
                <span class="dashicons dashicons-search"></span> Register Specific File(s)
            </button>
            <button id="register-skipped" class="aim-btn aim-btn-success">
                <span class="dashicons dashicons-controls-repeat"></span> Register Skipped
            </button>
        </div>

        <div class="aim-progress-section">
            <div class="aim-progress-info">
                <strong>Progress:</strong> <span id="progress-count">0</span> / <span
                    id="total-count"><?php echo $stats['total_files']; ?></span>
            </div>
            <div class="aim-progress-bar">
                <div id="progress-fill" class="aim-progress-fill" style="width: 0;">0%</div>
            </div>
            <div id="status-message" class="aim-status-message"></div>
            <div class="aim-status-counts">
                <span style="color: var(--success-color);">✅ Registered: <strong id="registered-count">0</strong></span>
                <span style="color: var(--warning-color);">⏭️ Skipped: <strong id="skipped-count">0</strong></span>
            </div>
        </div>
    </div>

    <!-- History Table -->
    <div class="aim-panel">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
            <h2 style="margin-bottom:0;">📜 Registration History</h2>
            <button id="clear-history" class="aim-btn aim-btn-danger">
                <span class="dashicons dashicons-trash"></span> Clear History
            </button>
        </div>
        <div class="aim-form-inline" style="display:flex; gap:12px; align-items:center; margin-bottom:12px;">
            <input type="text" id="history-search" class="aim-form-input"
                placeholder="Search by ID, Filename, Path, Size, Type, Status, Attachment, Date">
            <select id="history-status" class="aim-form-input" style="width:180px;">
                <option value="">All statuses</option>
                <option value="registered">Registered</option>
                <option value="skipped">Skipped</option>
                <option value="error">Error</option>
            </select>
            <input type="date" id="history-date-from" class="aim-form-input" style="width:160px;"
                placeholder="From date">
            <input type="date" id="history-date-to" class="aim-form-input" style="width:160px;" placeholder="To date">
            <input type="number" id="history-attachment" class="aim-form-input" style="width:160px;"
                placeholder="Attachment ID">
            <button id="history-clear-filters" class="aim-btn aim-btn-secondary" style="display:none;"><span
                    class="dashicons dashicons-no"></span> Clear</button>
        </div>
        <div class="aim-table-wrapper">
            <table class="aim-table" id="history-table">
                <thead>
                    <tr>
                        <th style="width: 5%;">ID</th>
                        <th style="width: 20%;">Filename</th>
                        <th style="width: 25%;">Path</th>
                        <th style="width: 10%;">Size</th>
                        <th style="width: 10%;">Type</th>
                        <th style="width: 10%;">Status</th>
                        <th style="width: 15%;">Reason</th>
                        <th style="width: 10%;">Attachment</th>
                        <th style="width: 10%;">Date</th>
                    </tr>
                </thead>
                <tbody id="history-body">
                    <?php aim_display_history(20); ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination Controls -->
        <div class="aim-pagination">
            <div class="aim-pagination-info">
                Page <strong id="current-page">1</strong> of <strong
                    id="total-pages"><?php echo aim_get_total_pages(20); ?></strong>
                <span style="margin-left: 16px;">Total Records: <strong
                        id="total-records"><?php echo aim_get_total_records(); ?></strong></span>
            </div>
            <div class="aim-pagination-controls">
                <button id="prev-page" class="aim-pagination-btn" disabled>
                    <span class="dashicons dashicons-arrow-left-alt2"></span> Previous
                </button>
                <button id="next-page" class="aim-pagination-btn">
                    Next <span class="dashicons dashicons-arrow-right-alt2"></span>
                </button>
            </div>
        </div>
    </div>

    <!-- Settings Panel (Collapsible) -->
    <div class="aim-panel aim-panel-collapsible">
        <div class="aim-panel-header" id="settings-header">
            <h2>⚙️ Settings</h2>
            <span class="aim-panel-toggle dashicons dashicons-arrow-down"></span>
        </div>
        <div class="aim-panel-content" id="settings-content">
            <div class="aim-panel-content-inner">
                <form method="post">
                    <?php wp_nonce_field('aim_settings_nonce'); ?>

                    <div class="aim-settings-section-title">Directory Configuration</div>
                    <div class="aim-form-group">
                        <label class="aim-form-label">Images Directory Path</label>
                        <input type="text" name="aim_image_directory" class="aim-form-input"
                            value="<?php echo esc_attr($current_dir); ?>"
                            placeholder="Leave empty for root uploads folder">
                        <div class="aim-form-help">
                            Relative to WordPress uploads folder. Current full path: <code
                                class="aim-code"><?php echo aim_get_image_directory(); ?></code>
                        </div>
                    </div>

                    <hr style="margin: 30px 0; border: 0; border-top: 1px solid var(--border-color);">

                    <div class="aim-settings-section-title">Background Auto-Registration</div>
                    <div class="aim-switch-group">
                        <label class="aim-switch">
                            <input type="checkbox" id="aim_auto_register_enabled" name="aim_auto_register_enabled"
                                value="1" <?php checked(get_option('aim_auto_register_enabled'), 1); ?>>
                            <span class="aim-slider"></span>
                        </label>
                        <label for="aim_auto_register_enabled" class="aim-form-label"
                            style="margin: 0; cursor: pointer;">Enable Background Auto-Registration</label>
                    </div>

                    <div class="aim-form-group">
                        <label class="aim-form-label">Auto-Registration Batch Limit</label>
                        <input type="number" name="aim_auto_register_limit" class="aim-form-input" style="width: 150px;"
                            value="<?php echo esc_attr(get_option('aim_auto_register_limit', 50)); ?>" min="1"
                            max="500">
                        <div class="aim-form-help">
                            Maximum number of <strong>new</strong> images to process per hourly schedule check. Set
                            lower if you experience server timeouts.
                        </div>
                    </div>

                    <button type="submit" name="aim_save_settings" class="aim-btn aim-btn-primary">
                        <span class="dashicons dashicons-saved"></span> Save Settings
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div style="text-align: center; padding: 20px; color: var(--secondary-text); font-size: 12px;">
        Developed by <a href="https://yasirshabbir.com" target="_blank"
            style="color: var(--accent-color); text-decoration: none; font-weight: 700;">Yasir Shabbir</a>
    </div>

    <div id="registerSkippedModal" class="aim-modal-overlay" aria-modal="true" role="dialog">
        <div class="aim-modal">
            <div class="aim-modal-title">
                <span class="dashicons dashicons-controls-repeat" style="color: var(--accent-color);"></span>
                Confirm Register Skipped
            </div>
            <div class="aim-modal-body">
                This will process all skipped files using the batch system. Continue?
            </div>
            <div class="aim-modal-actions">
                <button id="confirm-register-skipped" class="aim-btn aim-btn-success">
                    <span class="dashicons dashicons-yes"></span> Confirm
                </button>
                <button id="cancel-register-skipped" class="aim-btn aim-btn-secondary">
                    <span class="dashicons dashicons-no-alt"></span> Cancel
                </button>
            </div>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {

    // Operation state management with localStorage
    let operationState = {
        isRunning: false,
        isPaused: false,
        offset: 0,
        totalRegistered: 0,
        totalSkipped: 0,
        specificFile: false,
        totalCount: parseInt($('#total-count').text())
    };

    // Load saved state from localStorage
    function loadState() {
        const saved = localStorage.getItem('aim_operation_state');
        if (saved) {
            try {
                const parsed = JSON.parse(saved);
                if (parsed.isRunning && !parsed.isPaused) {
                    operationState = parsed;
                    restoreUI();
                }
            } catch (e) {
                console.error('Failed to load state:', e);
            }
        }
    }

    // Save state to localStorage
    function saveState() {
        localStorage.setItem('aim_operation_state', JSON.stringify(operationState));
    }

    // Clear state from localStorage
    function clearState() {
        localStorage.removeItem('aim_operation_state');
    }

    // Restore UI based on saved state
    function restoreUI() {
        updateUI();
        $('#progress-count').text(operationState.offset);
        $('#registered-count').text(operationState.totalRegistered);
        $('#skipped-count').text(operationState.totalSkipped);

        let percentage = Math.min((operationState.offset / operationState.totalCount * 100), 100);
        $('#progress-fill').css('width', percentage + '%').text(Math.round(percentage) + '%');

        if (operationState.isRunning && !operationState.isPaused) {
            $('#status-message').html(
                '<span style="color: var(--info-color);">▶️ Resuming operation...</span>');
            setTimeout(() => processRegistration(operationState.specificFile, true), 1000);
        } else if (operationState.isPaused) {
            $('#status-message').html('<span style="color: var(--warning-color);">⏸️ Operation paused</span>');
            updateUI();
        }
    }

    // Update button visibility based on state
    function updateUI() {
        if (operationState.isRunning) {
            $('#start-registering').hide();
            $('#register-specific').hide();

            if (operationState.isPaused) {
                $('#pause-operation').hide();
                $('#resume-operation').show();
                $('#cancel-operation').show();
            } else {
                $('#pause-operation').show();
                $('#resume-operation').hide();
                $('#cancel-operation').show();
            }
        } else {
            $('#start-registering').show();
            $('#register-specific').show();
            $('#pause-operation').hide();
            $('#resume-operation').hide();
            $('#cancel-operation').hide();
        }
    }

    // Bulk Registration
    $('#start-registering').on('click', function() {
        operationState = {
            isRunning: true,
            isPaused: false,
            offset: 0,
            totalRegistered: 0,
            totalSkipped: 0,
            specificFile: false,
            totalCount: parseInt($('#total-count').text())
        };
        saveState();
        processRegistration(false);
    });

    // Specific File Registration
    $('#register-specific').on('click', function() {
        const filename = $('#specific-filename').val().trim();
        if (!filename) {
            alert('Please enter a filename to search for.');
            return;
        }
        operationState = {
            isRunning: true,
            isPaused: false,
            offset: 0,
            totalRegistered: 0,
            totalSkipped: 0,
            specificFile: filename,
            processSkipped: false,
            totalCount: parseInt($('#total-count').text())
        };
        saveState();
        processRegistration(filename);
    });

    $('#register-skipped').on('click', function() {
        $('#registerSkippedModal').addClass('aim-modal-show');
    });
    $('#confirm-register-skipped').on('click', function() {
        operationState = {
            isRunning: true,
            isPaused: false,
            offset: 0,
            totalRegistered: 0,
            totalSkipped: 0,
            specificFile: false,
            processSkipped: true,
            totalCount: 0
        };
        saveState();
        $('#registerSkippedModal').removeClass('aim-modal-show');
        processRegistration(false);
    });
    $('#cancel-register-skipped').on('click', function() {
        $('#registerSkippedModal').removeClass('aim-modal-show');
    });
    $('#registerSkippedModal').on('click', function(e) {
        if (e.target === this) {
            $(this).removeClass('aim-modal-show');
        }
    });

    // Pause Operation
    $('#pause-operation').on('click', function() {
        operationState.isPaused = true;
        saveState();
        updateUI();
        $('#status-message').html(
            '<span style="color: var(--warning-color);">⏸️ Operation paused</span>');
    });

    // Resume Operation
    $('#resume-operation').on('click', function() {
        operationState.isPaused = false;
        saveState();
        updateUI();
        $('#status-message').html(
            '<span style="color: var(--info-color);">▶️ Resuming operation...</span>');
        processRegistration(operationState.specificFile, true);
    });

    // Cancel Operation
    $('#cancel-operation').on('click', function() {
        if (confirm('Are you sure you want to cancel the current operation?')) {
            operationState.isRunning = false;
            operationState.isPaused = false;
            clearState();
            updateUI();
            $('#status-message').html(
                '<span style="color: var(--danger-color);">❌ Operation cancelled</span>');
            setTimeout(function() {
                location.reload();
            }, 1500);
        }
    });

    function processRegistration(specificFile, isResume = false) {
        if (!isResume) {
            updateUI();
        }

        let batchSize = 10;
        let progressCount = $('#progress-count');
        let progressFill = $('#progress-fill');
        let statusMessage = $('#status-message');
        let registeredCount = $('#registered-count');
        let skippedCount = $('#skipped-count');

        function registerBatch() {
            // Check if paused
            if (operationState.isPaused) {
                return;
            }

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'aim_register_batch',
                    offset: operationState.offset,
                    batch_size: batchSize,
                    specific_file: specificFile || '',
                    process_skipped: operationState.processSkipped ? 1 : 0
                },
                success: function(response) {
                    if (response.success) {
                        if (response.data.total && !operationState.totalCount) {
                            operationState.totalCount = response.data.total;
                            if (operationState.processSkipped) {
                                $('#total-count').text(operationState.totalCount);
                            }
                        }
                        operationState.offset += (response.data.processed || batchSize);
                        operationState.totalRegistered += response.data.registered;
                        operationState.totalSkipped += response.data.skipped;
                        saveState();

                        progressCount.text(operationState.offset);
                        registeredCount.text(operationState.totalRegistered);
                        skippedCount.text(operationState.totalSkipped);

                        let percentage = Math.min((operationState.offset / operationState
                            .totalCount * 100), 100);
                        progressFill.css('width', percentage + '%').text(Math.round(percentage) +
                            '%');

                        // Update history table if on page 1
                        if (response.data.history_html && currentPage === 1) {
                            $('#history-body').prepend(response.data.history_html);

                            // Limit to 20 rows to keep page size consistent
                            const maxRows = 20;
                            const $rows = $('#history-body tr');
                            if ($rows.length > maxRows) {
                                $rows.slice(maxRows).remove();
                            }
                        }

                        // Update total records and pages counter
                        const currentTotal = parseInt($('#total-records').text()) || 0;
                        const newTotal = currentTotal + response.data.registered + response.data
                            .skipped;
                        $('#total-records').text(newTotal);

                        const newTotalPages = Math.ceil(newTotal / 20);
                        totalPages = newTotalPages; // Update global variable
                        $('#total-pages').text(newTotalPages);
                        updatePaginationButtons();

                        if (specificFile || operationState.offset >= operationState.totalCount) {
                            statusMessage.html(
                                '<span style="color: var(--success-color);">✅ Complete! Registered: ' +
                                operationState.totalRegistered + ', Skipped: ' + operationState
                                .totalSkipped + '</span>');
                            operationState.isRunning = false;
                            clearState();
                            updateUI();
                            setTimeout(function() {
                                location.reload();
                            }, 2000);
                        } else if (!operationState.isPaused) {
                            statusMessage.html(
                                '<span style="color: var(--info-color);">⚙️ Processing batch...</span>'
                            );
                            setTimeout(registerBatch, 500);
                        }
                    } else {
                        statusMessage.html('<span style="color: var(--danger-color);">❌ Error: ' +
                            response.data.message + '</span>');
                        operationState.isRunning = false;
                        clearState();
                        updateUI();
                    }
                },
                error: function() {
                    statusMessage.html(
                        '<span style="color: var(--danger-color);">❌ Connection error. Please try again.</span>'
                    );
                    operationState.isRunning = false;
                    clearState();
                    updateUI();
                }
            });
        }

        registerBatch();
    }

    // Clear History
    $('#clear-history').on('click', function() {
        if (confirm('Are you sure you want to clear all history records? This cannot be undone.')) {
            $.post(ajaxurl, {
                action: 'aim_clear_history'
            }, function(response) {
                if (response.success) {
                    clearState();
                    location.reload();
                }
            });
        }
    });

    // Initialize: Load state on page load
    loadState();

    // ====================================================================
    // PAGINATION FUNCTIONALITY
    // ====================================================================

    let currentPage = 1;
    let totalPages = parseInt($('#total-pages').text());

    // Update pagination buttons state
    function updatePaginationButtons() {
        $('#prev-page').prop('disabled', currentPage <= 1);
        $('#next-page').prop('disabled', currentPage >= totalPages);
    }

    // Load history page via AJAX
    function loadHistoryPage(page) {
        const $historyBody = $('#history-body');
        const $tableWrapper = $('.aim-table-wrapper');

        // Add loading state
        $tableWrapper.addClass('aim-loading');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'aim_filter_history',
                page: page,
                per_page: 20,
                query: $('#history-search').val().trim(),
                status: $('#history-status').val(),
                date_from: $('#history-date-from').val(),
                date_to: $('#history-date-to').val(),
                attachment_id: $('#history-attachment').val()
            },
            success: function(response) {
                if (response.success) {
                    $historyBody.html(response.data.html);
                    currentPage = response.data.current_page;
                    totalPages = response.data.total_pages;

                    $('#current-page').text(currentPage);
                    $('#total-pages').text(totalPages);
                    $('#total-records').text(response.data.total_records);

                    updatePaginationButtons();
                }
                $tableWrapper.removeClass('aim-loading');
            },
            error: function() {
                alert('Error loading history page. Please try again.');
                $tableWrapper.removeClass('aim-loading');
            }
        });
    }

    // Previous page button
    $('#prev-page').on('click', function() {
        if (currentPage > 1) {
            loadHistoryPage(currentPage - 1);
        }
    });

    // Next page button
    $('#next-page').on('click', function() {
        if (currentPage < totalPages) {
            loadHistoryPage(currentPage + 1);
        }
    });

    // Initialize pagination buttons
    updatePaginationButtons();

    // ====================================================================
    // HISTORY FILTERS (AJAX)
    // ====================================================================
    function debounce(fn, ms) {
        let t;
        return function() {
            clearTimeout(t);
            const args = arguments;
            const self = this;
            t = setTimeout(function() {
                fn.apply(self, args);
            }, ms);
        };
    }

    function applyHistoryFilters(page = 1) {
        loadHistoryPage(page);
    }

    function hasActiveFilters() {
        const q = $('#history-search').val().trim();
        const st = $('#history-status').val();
        const df = $('#history-date-from').val();
        const dt = $('#history-date-to').val();
        const aid = $('#history-attachment').val();
        return !!(q || st || df || dt || aid);
    }

    function updateClearButtonVisibility() {
        if (hasActiveFilters()) {
            $('#history-clear-filters').show();
        } else {
            $('#history-clear-filters').hide();
        }
    }

    $('#history-search').on('input', debounce(function() {
        applyHistoryFilters(1);
        updateClearButtonVisibility();
    }, 300));
    $('#history-status, #history-date-from, #history-date-to, #history-attachment').on('change', function() {
        applyHistoryFilters(1);
        updateClearButtonVisibility();
    });
    $('#history-clear-filters').on('click', function() {
        $('#history-search').val('');
        $('#history-status').val('');
        $('#history-date-from').val('');
        $('#history-date-to').val('');
        $('#history-attachment').val('');
        applyHistoryFilters(1);
        updateClearButtonVisibility();
    });

    // Initial filtered load
    applyHistoryFilters(1);
    updateClearButtonVisibility();

    // ====================================================================
    // COLLAPSIBLE SETTINGS PANEL
    // ====================================================================

    $('#settings-header').on('click', function() {
        const $content = $('#settings-content');
        const $toggle = $(this).find('.aim-panel-toggle');

        if ($content.hasClass('expanded')) {
            $content.removeClass('expanded');
            $toggle.removeClass('expanded');
        } else {
            $content.addClass('expanded');
            $toggle.addClass('expanded');
        }
    });
});
</script>
<?php
}

// ============================================================================
// STATISTICS FUNCTIONS
// ============================================================================

function aim_get_statistics()
{
    global $wpdb;
    $history_table = $wpdb->prefix . 'image_registration_history';

    $image_dir = aim_get_image_directory();
    $total_files = 0;

    if (is_dir($image_dir)) {
        $files = array_diff(scandir($image_dir), array('.', '..'));
        $total_files = count(array_filter($files, function ($file) use ($image_dir) {
            return is_file($image_dir . $file);
        }));
    }

    $registered = $wpdb->get_var("SELECT COUNT(*) FROM $history_table WHERE status = 'registered'");
    $skipped = $wpdb->get_var("SELECT COUNT(*) FROM $history_table WHERE status = 'skipped'");
    $unprocessed = max(0, $total_files - ($registered + $skipped));

    return array(
        'total_files' => $total_files,
        'registered' => (int)$registered,
        'skipped' => (int)$skipped,
        'unprocessed' => $unprocessed
    );
}

// ============================================================================
// HISTORY DISPLAY
// ============================================================================

function aim_get_total_records()
{
    global $wpdb;
    $history_table = $wpdb->prefix . 'image_registration_history';
    return (int)$wpdb->get_var("SELECT COUNT(*) FROM $history_table");
}

function aim_get_total_pages($per_page = 20)
{
    $total = aim_get_total_records();
    return max(1, ceil($total / $per_page));
}

function aim_display_history($limit = 20, $page = 1)
{
    global $wpdb;
    $history_table = $wpdb->prefix . 'image_registration_history';

    $offset = ($page - 1) * $limit;

    $results = $wpdb->get_results(
        $wpdb->prepare("SELECT * FROM $history_table ORDER BY registered_date DESC LIMIT %d OFFSET %d", $limit, $offset)
    );

    if (empty($results)) {
        echo '<tr><td colspan="9" class="aim-empty-state">No history records yet. Start registration to see results here.</td></tr>';
        return;
    }

    foreach ($results as $row) {
        aim_render_history_row($row);
    }
}

function aim_render_history_row($row)
{
    $status_class = 'aim-badge-' . $row->status;
    $status_label = ucfirst($row->status);
    $file_size = size_format($row->file_size, 2);
    $date = date('Y-m-d H:i:s', strtotime($row->registered_date));
    $attachment_link = $row->attachment_id ?
        '<a href="' . admin_url('post.php?post=' . $row->attachment_id . '&action=edit') . '" target="_blank" style="color: var(--accent-color); text-decoration: none;">#' . $row->attachment_id . '</a>' :
        '<span style="color: var(--secondary-color);">N/A</span>';

    echo '<tr>';
    echo '<td>' . $row->id . '</td>';
    echo '<td><strong style="color: var(--primary-text);">' . esc_html($row->filename) . '</strong></td>';
    echo '<td><code class="aim-code">' . esc_html($row->file_path) . '</code></td>';
    echo '<td>' . $file_size . '</td>';
    echo '<td>' . esc_html($row->file_type) . '</td>';
    echo '<td><span class="aim-badge ' . $status_class . '">' . $status_label . '</span></td>';
    $reason_display = esc_html($row->reason);
    if (!empty($row->reason_detail)) {
        $reason_display .= ' — ' . esc_html($row->reason_detail);
    }
    echo '<td style="color: var(--secondary-text);">' . $reason_display . '</td>';
    echo '<td>' . $attachment_link . '</td>';
    echo '<td style="color: var(--secondary-text);">' . $date . '</td>';
    echo '</tr>';
}

// ============================================================================
// AJAX HANDLERS
// ============================================================================

add_action('wp_ajax_aim_register_batch', 'aim_process_batch');

function aim_process_batch()
{
    $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 10;
    $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
    $specific_file = isset($_POST['specific_file']) ? sanitize_text_field($_POST['specific_file']) : '';
    $process_skipped = isset($_POST['process_skipped']) ? intval($_POST['process_skipped']) === 1 : false;

    $image_dir = aim_get_image_directory();

    // ... (directory checks kept same but shortened for diff) ...
    if (!is_dir($image_dir)) {
        wp_send_json_error(array('message' => 'Directory not found: ' . $image_dir));
        return;
    }

    if ($process_skipped) {
        global $wpdb;
        $history_table = $wpdb->prefix . 'image_registration_history';
        $skipped_files = $wpdb->get_col("SELECT DISTINCT filename FROM $history_table WHERE status = 'skipped'");
        $skipped_files = array_values(array_filter($skipped_files, function ($file) use ($image_dir) {
            return !empty($file) && is_file($image_dir . $file);
        }));
        $all_files = $skipped_files;
    } else {
        $all_files = array_diff(scandir($image_dir), array('.', '..'));
        $all_files = array_values(array_filter($all_files, function ($file) use ($image_dir) {
            return is_file($image_dir . $file);
        }));
    }

    // Filter for specific file if provided
    if (!empty($specific_file)) {
        $all_files = array_filter($all_files, function ($file) use ($specific_file) {
            $file_no_ext = pathinfo($file, PATHINFO_FILENAME);
            $search_no_ext = pathinfo($specific_file, PATHINFO_FILENAME);
            if (strpos($specific_file, '.') !== false) {
                return $file === $specific_file;
            }
            return $file_no_ext === $search_no_ext;
        });
        $all_files = array_values($all_files);
    }

    $total = count($all_files);
    $files = array_slice($all_files, $offset, $batch_size);

    $registered = 0;
    $skipped = 0;
    $history_records = array();

    foreach ($files as $file) {
        $result = aim_register_single_image($file, $image_dir, $process_skipped ? 'force' : 'normal');

        if ($result['status'] === 'registered') {
            $registered++;
        } elseif ($result['status'] === 'skipped') {
            $skipped++;
        }

        if (isset($result['history_id'])) {
            $history_records[] = $result['history_id'];
        }
    }

    // Fetch HTML for new history records
    global $wpdb;
    $history_table = $wpdb->prefix . 'image_registration_history';
    $history_html = '';

    foreach ($history_records as $record_id) {
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $history_table WHERE id = %d", $record_id));
        if ($row) {
            ob_start();
            aim_render_history_row($row);
            $history_html .= ob_get_clean();
        }
    }

    wp_send_json_success(array(
        'total' => $total,
        'registered' => $registered,
        'skipped' => $skipped,
        'processed' => count($files),
        'history_html' => $history_html
    ));
}

add_action('wp_ajax_aim_filter_history', 'aim_filter_history');

function aim_filter_history()
{
    global $wpdb;
    $history_table = $wpdb->prefix . 'image_registration_history';
    $per_page = isset($_POST['per_page']) ? max(1, intval($_POST['per_page'])) : 20;
    $page = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;
    $offset = ($page - 1) * $per_page;
    $query = isset($_POST['query']) ? sanitize_text_field($_POST['query']) : '';
    $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : '';
    $date_from = isset($_POST['date_from']) ? sanitize_text_field($_POST['date_from']) : '';
    $date_to = isset($_POST['date_to']) ? sanitize_text_field($_POST['date_to']) : '';
    $attachment_id = isset($_POST['attachment_id']) ? intval($_POST['attachment_id']) : 0;

    $where = array();
    $params = array();

    if (!empty($status)) {
        $where[] = "status = %s";
        $params[] = $status;
    }
    if (!empty($date_from)) {
        $where[] = "registered_date >= %s";
        $params[] = $date_from . ' 00:00:00';
    }
    if (!empty($date_to)) {
        $where[] = "registered_date <= %s";
        $params[] = $date_to . ' 23:59:59';
    }
    if (!empty($attachment_id)) {
        $where[] = "attachment_id = %d";
        $params[] = $attachment_id;
    }
    if (!empty($query)) {
        $like = '%' . $wpdb->esc_like($query) . '%';
        $where[] = "(CAST(id AS CHAR) LIKE %s OR filename LIKE %s OR file_path LIKE %s OR CAST(file_size AS CHAR) LIKE %s OR file_type LIKE %s OR status LIKE %s OR CAST(attachment_id AS CHAR) LIKE %s OR DATE_FORMAT(registered_date, '%%Y-%%m-%%d %%H:%%i:%%s') LIKE %s OR reason LIKE %s OR reason_detail LIKE %s)";
        array_push($params, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like);
    }

    $sql_where = '';
    if (!empty($where)) {
        $sql_where = ' WHERE ' . implode(' AND ', $where);
    }

    $sql = "SELECT * FROM $history_table" . $sql_where . " ORDER BY registered_date DESC LIMIT %d OFFSET %d";
    $count_sql = "SELECT COUNT(*) FROM $history_table" . $sql_where;

    $results = $wpdb->get_results($wpdb->prepare($sql, array_merge($params, array($per_page, $offset))));
    $total_records = (int)$wpdb->get_var($wpdb->prepare($count_sql, $params));

    ob_start();
    if (!empty($results)) {
        foreach ($results as $row) {
            aim_render_history_row($row);
        }
    } else {
        echo '<tr><td colspan="9" class="aim-empty-state">No matching records.</td></tr>';
    }
    $html = ob_get_clean();

    wp_send_json_success(array(
        'html' => $html,
        'total_pages' => max(1, ceil($total_records / $per_page)),
        'total_records' => $total_records,
        'current_page' => $page
    ));
}

/**
 * Helper function to register a single image
 * Used by both AJAX batch process and Cron background process
 */
function aim_register_single_image($filename, $directory, $mode = 'normal')
{
    global $wpdb;
    $history_table = $wpdb->prefix . 'image_registration_history';
    $file_path = $directory . $filename;

    // Basic file checks
    if (!file_exists($file_path)) {
        return array('status' => 'error', 'message' => 'File not found');
    }

    $file_size = filesize($file_path);
    $filetype = wp_check_filetype($file_path);

    // Check for duplicate
    $exists = aim_check_duplicate($file_path, $filename);

    if ($exists) {
        $existing_id = is_array($exists) ? (int)$exists['id'] : (int)$exists;
        $existing_method = is_array($exists) && isset($exists['method']) ? $exists['method'] : '';
        $current_hash = md5_file($file_path);
        $relative_path = str_replace(wp_upload_dir()['basedir'] . '/', '', $file_path);
        if ($existing_method === 'exact_path' || $existing_method === 'url' || $mode === 'force') {
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            $attach_data = wp_generate_attachment_metadata($existing_id, $file_path);
            if (!is_wp_error($attach_data)) {
                wp_update_attachment_metadata($existing_id, $attach_data);
            }
            wp_update_post(array(
                'ID' => $existing_id,
                'post_mime_type' => $filetype['type'],
                'post_title' => sanitize_file_name(pathinfo($filename, PATHINFO_FILENAME))
            ));
            update_post_meta($existing_id, '_wp_attached_file', $relative_path);
            if ($current_hash) update_post_meta($existing_id, '_wp_attachment_file_hash', $current_hash);

            $wpdb->insert(
                $history_table,
                array(
                    'filename' => $filename,
                    'file_path' => $file_path,
                    'file_size' => $file_size,
                    'file_type' => $filetype['type'],
                    'status' => 'registered',
                    'reason' => 'Updated existing attachment',
                    'reason_detail' => ($mode === 'force' ? 'skipped_recovery_' : '') . $existing_method,
                    'attachment_id' => $existing_id
                ),
                array('%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d')
            );

            return array(
                'status' => 'registered',
                'history_id' => $wpdb->insert_id
            );
        } else {
            $wpdb->insert(
                $history_table,
                array(
                    'filename' => $filename,
                    'file_path' => $file_path,
                    'file_size' => $file_size,
                    'file_type' => $filetype['type'],
                    'status' => 'skipped',
                    'reason' => 'Duplicate detected',
                    'reason_detail' => $existing_method,
                    'attachment_id' => $existing_id
                ),
                array('%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d')
            );

            return array(
                'status' => 'skipped',
                'history_id' => $wpdb->insert_id
            );
        }
    } else {
        $attachment = array(
            'post_mime_type' => $filetype['type'],
            'post_title' => sanitize_file_name(pathinfo($filename, PATHINFO_FILENAME)),
            'post_content' => '',
            'post_status' => 'inherit'
        );

        $attach_id = wp_insert_attachment($attachment, $file_path);

        if (!is_wp_error($attach_id)) {
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            $attach_data = wp_generate_attachment_metadata($attach_id, $file_path);
            wp_update_attachment_metadata($attach_id, $attach_data);

            $wpdb->insert(
                $history_table,
                array(
                    'filename' => $filename,
                    'file_path' => $file_path,
                    'file_size' => $file_size,
                    'file_type' => $filetype['type'],
                    'status' => 'registered',
                    'reason' => 'Successfully registered',
                    'reason_detail' => 'registration',
                    'attachment_id' => $attach_id
                ),
                array('%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d')
            );

            return array(
                'status' => 'registered',
                'history_id' => $wpdb->insert_id
            );
        } else {
            return array('status' => 'error', 'message' => $attach_id->get_error_message());
        }
    }
}

add_action('wp_ajax_aim_clear_history', 'aim_clear_history');

function aim_clear_history()
{
    global $wpdb;
    $history_table = $wpdb->prefix . 'image_registration_history';

    $wpdb->query("TRUNCATE TABLE $history_table");

    wp_send_json_success(array('message' => 'History cleared successfully'));
}

add_action('wp_ajax_aim_load_history_page', 'aim_load_history_page');

function aim_load_history_page()
{
    $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
    $per_page = 20;

    ob_start();
    aim_display_history($per_page, $page);
    $html = ob_get_clean();

    wp_send_json_success(array(
        'html' => $html,
        'total_pages' => aim_get_total_pages($per_page),
        'total_records' => aim_get_total_records(),
        'current_page' => $page
    ));
}

// ============================================================================
// DUPLICATE DETECTION ENGINE
// ============================================================================

function aim_check_duplicate($file_path, $filename)
{
    global $wpdb;
    $upload_dir = wp_upload_dir();
    $relative_path = str_replace($upload_dir['basedir'] . '/', '', $file_path);
    $file_url = $upload_dir['baseurl'] . '/' . $relative_path;
    $basename = $filename;

    $exact_path_check = $wpdb->get_var($wpdb->prepare(
        "SELECT pm.post_id
         FROM {$wpdb->postmeta} pm
         INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
         WHERE pm.meta_key = '_wp_attached_file'
           AND pm.meta_value = %s
           AND p.post_type = 'attachment'
           AND p.post_status NOT IN ('trash','auto-draft')",
        $relative_path
    ));
    if ($exact_path_check) {
        return array('id' => (int)$exact_path_check, 'method' => 'exact_path');
    }

    $url_check = attachment_url_to_postid($file_url);
    if ($url_check) {
        $status = get_post_status($url_check);
        $ptype = get_post_type($url_check);
        if ($status && !in_array($status, array('trash', 'auto-draft'), true) && $ptype === 'attachment') {
            return array('id' => (int)$url_check, 'method' => 'url');
        }
    }

    $file_hash = md5_file($file_path);
    if ($file_hash) {
        $hash_check = $wpdb->get_var($wpdb->prepare(
            "SELECT pm.post_id
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE pm.meta_key = '_wp_attachment_file_hash'
               AND pm.meta_value = %s
               AND p.post_type = 'attachment'
               AND p.post_status NOT IN ('trash','auto-draft')",
            $file_hash
        ));
        if ($hash_check) {
            return array('id' => (int)$hash_check, 'method' => 'hash');
        }
    }

    $guid_exact = $wpdb->get_var($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} 
        WHERE post_type = 'attachment' 
        AND guid = %s",
        $file_url
    ));
    if ($guid_exact) {
        $status = get_post_status($guid_exact);
        if ($status && !in_array($status, array('trash', 'auto-draft'), true)) {
            return array('id' => (int)$guid_exact, 'method' => 'guid');
        }
    }

    $candidate_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT pm.post_id
         FROM {$wpdb->postmeta} pm
         INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
         WHERE pm.meta_key = '_wp_attached_file'
           AND pm.meta_value LIKE %s
           AND p.post_type = 'attachment'
           AND p.post_status NOT IN ('trash','auto-draft')",
        '%' . $wpdb->esc_like($basename)
    ));
    if (!empty($candidate_ids)) {
        foreach ($candidate_ids as $cid) {
            $attached_file = get_post_meta($cid, '_wp_attached_file', true);
            if ($attached_file && basename($attached_file) === $basename) {
                return array('id' => (int)$cid, 'method' => 'basename_meta_exact');
            }
        }
    }

    return false;
}

// ============================================================================
// ATTACHMENT HOOKS
// ============================================================================

add_action('add_attachment', 'aim_store_file_hash');

function aim_store_file_hash($attachment_id)
{
    $file = get_attached_file($attachment_id);
    if ($file && file_exists($file)) {
        $file_hash = md5_file($file);
        if ($file_hash) {
            update_post_meta($attachment_id, '_wp_attachment_file_hash', $file_hash);
        }
    }
}

// ============================================================================
// BACKGROUND PROCESS (CRON)
// ============================================================================

add_action('aim_cron_auto_register', 'aim_handle_auto_registration');

function aim_handle_auto_registration()
{
    // Check if enabled
    if (!get_option('aim_auto_register_enabled')) {
        return;
    }

    $image_dir = aim_get_image_directory();
    if (!is_dir($image_dir)) {
        return;
    }

    // Get all files in directory
    $all_files = array_diff(scandir($image_dir), array('.', '..'));
    $all_files = array_filter($all_files, function ($file) use ($image_dir) {
        return is_file($image_dir . $file);
    });

    if (empty($all_files)) {
        return;
    }

    // OPTIMIZATION: Get array of already processed filenames from history
    // This avoids running heavy WP/DB duplicate checks on thousands of existing files
    global $wpdb;
    $history_table = $wpdb->prefix . 'image_registration_history';

    // Fetch only filenames to minimize memory usage
    $processed_files = $wpdb->get_col("SELECT filename FROM $history_table");

    // Find files that are NOT in history
    // These are the "potential" new files we need to check properly
    $new_files = array_diff($all_files, $processed_files);

    if (empty($new_files)) {
        return;
    }

    // Reset keys
    $new_files = array_values($new_files);

    // Limit processing to avoid timeout
    $limit = get_option('aim_auto_register_limit', 50);
    $files_to_process = array_slice($new_files, 0, $limit);

    // Process the batch
    foreach ($files_to_process as $file) {
        aim_register_single_image($file, $image_dir);
    }
}