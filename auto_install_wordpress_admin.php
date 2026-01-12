<?php
/**
 * Plugin Name: auto_install_wordpress_admin
 * Plugin URI: https://ai47.us/
 * Description: PMS Secupay自动化配置的管理面板，提供系统状态监控、手动管理和调试功能
 * Version: 1.5.1 (修复数据库表错误)
 * Author: AI47 Support
 * Author URI: https://ai47.us/
 * Text Domain: pms-secupay-automation-admin
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * License: GPL v2 or later
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

// 定义插件常量
define('PMS_AUTOMATION_ADMIN_VERSION', '1.5.1');
define('PMS_AUTOMATION_ADMIN_PATH', plugin_dir_path(__FILE__));
define('PMS_AUTOMATION_ADMIN_URL', plugin_dir_url(__FILE__));
define('PMS_AUTOMATION_ADMIN_FILE', __FILE__);

// ==================== 数据库表创建 ====================

/**
 * 创建数据库表
 */
function pms_automation_admin_create_tables() {
    global $wpdb;
    
    $charset_collate = $wpdb->get_charset_collate();
    
    // 用户自动化状态表
    $user_status_table = $wpdb->prefix . 'pms_user_automation_status';
    
    $sql = "CREATE TABLE IF NOT EXISTS $user_status_table (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT(20) UNSIGNED NOT NULL,
        domain VARCHAR(255),
        status ENUM('pending', 'domain_required', 'queued', 'running', 'completed', 'failed') DEFAULT 'pending',
        progress INT(11) DEFAULT 0,
        subscription_id BIGINT(20) UNSIGNED,
        last_updated DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY user_id (user_id),
        KEY status (status),
        KEY subscription_id (subscription_id)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    
    // 队列表
    $queue_table = $wpdb->prefix . 'pms_automation_queue';
    
    $sql2 = "CREATE TABLE IF NOT EXISTS $queue_table (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT(20) UNSIGNED NOT NULL,
        domain VARCHAR(255) NOT NULL,
        wp_password VARCHAR(255) NOT NULL,
        status ENUM('pending', 'queued', 'running', 'completed', 'failed', 'cancelled') DEFAULT 'pending',
        queue_position INT(11) DEFAULT 0,
        estimated_wait_time INT(11) DEFAULT 0,
        started_at DATETIME DEFAULT NULL,
        completed_at DATETIME DEFAULT NULL,
        pid INT(11) DEFAULT NULL,
        output_file VARCHAR(500) DEFAULT NULL,
        progress INT(11) DEFAULT 0,
        error_message TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY user_id (user_id),
        KEY status (status),
        KEY queue_position (queue_position),
        KEY started_at (started_at)
    ) $charset_collate;";
    
    dbDelta($sql2);
    
    // 进程表
    $process_table = $wpdb->prefix . 'pms_automation_processes';
    
    $sql3 = "CREATE TABLE IF NOT EXISTS $process_table (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        queue_id BIGINT(20) UNSIGNED NOT NULL,
        pid INT(11) NOT NULL,
        command TEXT NOT NULL,
        started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        last_check_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        status ENUM('running', 'completed', 'failed', 'stalled') DEFAULT 'running',
        PRIMARY KEY (id),
        KEY queue_id (queue_id),
        KEY pid (pid),
        KEY status (status)
    ) $charset_collate;";
    
    dbDelta($sql3);
    
    return true;
}

// 注册激活钩子
register_activation_hook(__FILE__, 'pms_automation_admin_activation');

function pms_automation_admin_activation() {
    pms_automation_admin_create_tables();
    
    // 创建必要的目录
    $dirs = array(
        PMS_AUTOMATION_ADMIN_PATH . 'logs',
        PMS_AUTOMATION_ADMIN_PATH . 'tmp'
    );
    
    foreach ($dirs as $dir) {
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }
    }
}

/**
 * 检查并创建表
 */
function pms_automation_admin_check_and_create_tables() {
    global $wpdb;
    
    $user_status_table = $wpdb->prefix . 'pms_user_automation_status';
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$user_status_table'");
    
    if (!$table_exists) {
        pms_automation_admin_create_tables();
    }
}

// ==================== 延迟初始化 ====================

add_action('init', 'pms_automation_admin_init_plugin', 5);

function pms_automation_admin_init_plugin() {
    // 加载文本域
    load_plugin_textdomain('pms-secupay-automation-admin', false, dirname(plugin_basename(__FILE__)) . '/languages');
    
    // 检查表是否存在，如果不存在则创建
    pms_automation_admin_check_and_create_tables();
    
    // 初始化管理面板类
    PMS_Secupay_Automation_Admin::get_instance();
}

// ==================== 管理面板类 ====================

class PMS_Secupay_Automation_Admin {
    
    private static $instance = null;
    private $debug_mode = false;
    private $admin_only_debug = true;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->init_hooks();
        $this->debug_mode = defined('WP_DEBUG') && WP_DEBUG;
        $this->admin_only_debug = true;
        
        // 确保表存在
        pms_automation_admin_check_and_create_tables();
    }
    
    private function init_hooks() {
        // 初始化
        add_action('init', array($this, 'init'), 10);
        
        // 确保表存在
        add_action('admin_init', array($this, 'check_tables'), 1);
        
        // AJAX处理（管理功能）
        add_action('wp_ajax_pms_test_script_execution', array($this, 'ajax_test_script_execution'));
        add_action('wp_ajax_pms_test_sudo_access', array($this, 'ajax_test_sudo_access'));
        add_action('wp_ajax_pms_debug_system', array($this, 'ajax_debug_system'));
        
        // 新增：实时日志监控AJAX
        add_action('wp_ajax_pms_get_realtime_log', array($this, 'ajax_get_realtime_log'));
        add_action('wp_ajax_pms_execute_ssh_like_command', array($this, 'ajax_execute_ssh_like_command'));
        
        // 管理员菜单
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        
        // 添加手动标记用户钩子（仅管理员）
        add_action('admin_init', array($this, 'handle_admin_actions'));
        
        // PMS订阅页面列显示
        add_filter('pms_account_subscriptions_table_columns', array($this, 'add_automation_column_to_subscriptions'), 20);
        add_filter('pms_account_subscriptions_table_column_automation', array($this, 'render_automation_column'), 10, 2);
    }
    
    /**
     * 检查表是否存在
     */
    public function check_tables() {
        pms_automation_admin_check_and_create_tables();
    }
    
    public function init() {
        // 检查核心插件是否激活
        if (!class_exists('PMS_Secupay_Automation_Core')) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-warning"><p>PMS Secupay 自动化配置管理面板需要核心插件激活。</p></div>';
            });
        }
    }
    
    /**
     * 调试日志
     */
    private function log_debug($message) {
        if ($this->debug_mode) {
            error_log("[PMS Automation Admin DEBUG] " . $message);
        }
    }
    
    /**
     * 在PMS订阅页面添加自动化列
     */
    public function add_automation_column_to_subscriptions($columns) {
        $columns['automation'] = __('自动化配置', 'pms-secupay-automation-admin');
        return $columns;
    }
    
    /**
     * 渲染自动化列
     */
    public function render_automation_column($value, $subscription) {
        $user_id = $subscription->user_id;
        
        // 获取用户自动化状态
        $domain = get_user_meta($user_id, '_pms_automation_domain', true);
        $status = get_user_meta($user_id, '_pms_automation_status', true);
        $progress = get_user_meta($user_id, '_pms_automation_progress', true);
        
        if (empty($status) || $status === 'pending' || $status === 'domain_required') {
            $has_paid = get_user_meta($user_id, '_pms_has_paid', true) === 'yes';
            if ($has_paid) {
                return '<button type="button" class="button button-small" onclick="pmsAdminSetupAutomation(' . $user_id . ', ' . $subscription->id . ')">开始配置</button>';
            } else {
                return '<span class="dashicons dashicons-no" title="未支付"></span>';
            }
        } elseif ($status === 'queued') {
            return '<span class="dashicons dashicons-clock" title="排队中"></span> 排队';
        } elseif ($status === 'running') {
            return '<span class="dashicons dashicons-update" style="animation:spin 1s linear infinite;"></span> ' . ($progress ? $progress . '%' : '部署中');
        } elseif ($status === 'completed' && $domain) {
            return '<span class="dashicons dashicons-yes" style="color:#46b450;"></span> <a href="https://' . esc_attr($domain) . '" target="_blank">' . esc_html($domain) . '</a>';
        } elseif ($status === 'failed') {
            return '<span class="dashicons dashicons-no" style="color:#dc3232;"></span> 失败';
        } elseif ($status === 'cancelled') {
            return '<span class="dashicons dashicons-dismiss" style="color:#aaa;"></span> 已取消';
        }
        
        return $value;
    }
    
    /**
     * 处理管理员操作
     */
    public function handle_admin_actions() {
        if (!current_user_can('manage_options') || !isset($_GET['page']) || $_GET['page'] !== 'pms-automation-admin-settings') {
            return;
        }
        
        // 手动标记用户需要自动化
        if (isset($_GET['action']) && $_GET['action'] === 'mark_user_automation' && isset($_GET['user_id'])) {
            $user_id = intval($_GET['user_id']);
            $this->manually_mark_user_as_paid($user_id);
            wp_redirect(admin_url('admin.php?page=pms-automation-admin-settings&marked=1'));
            exit;
        }
        
        // 重置用户自动化状态
        if (isset($_GET['action']) && $_GET['action'] === 'reset_user_automation' && isset($_GET['user_id'])) {
            $user_id = intval($_GET['user_id']);
            delete_user_meta($user_id, '_pms_needs_automation');
            delete_user_meta($user_id, '_pms_automation_status');
            delete_user_meta($user_id, '_pms_automation_domain');
            delete_user_meta($user_id, '_pms_automation_progress');
            delete_user_meta($user_id, '_pms_has_paid');
            delete_user_meta($user_id, '_pms_wordpress_password');
            delete_user_meta($user_id, '_pms_automation_error');
            delete_user_meta($user_id, '_pms_automation_completed_time');
            delete_user_meta($user_id, '_pms_automation_output_file');
            
            // 从队列中删除任务
            global $wpdb;
            $queue_table = $wpdb->prefix . 'pms_automation_queue';
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$queue_table'");
            
            if ($table_exists) {
                $wpdb->delete($queue_table, array('user_id' => $user_id), array('%d'));
            }
            
            // 从用户状态表中删除
            $user_status_table = $wpdb->prefix . 'pms_user_automation_status';
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$user_status_table'");
            
            if ($table_exists) {
                $wpdb->delete($user_status_table, array('user_id' => $user_id), array('%d'));
            }
            
            wp_redirect(admin_url('admin.php?page=pms-automation-admin-settings&reset=1'));
            exit;
        }
        
        // 处理手动标记为已付费
        if (isset($_POST['action']) && $_POST['action'] === 'mark_as_paid' && isset($_POST['user_id'])) {
            $user_id = intval($_POST['user_id']);
            $this->manually_mark_user_as_paid($user_id);
            wp_redirect(admin_url('admin.php?page=pms-automation-admin-settings&marked_paid=1'));
            exit;
        }
        
        // 清理队列
        if (isset($_GET['action']) && $_GET['action'] === 'cleanup_queue') {
            $this->cleanup_stalled_queue_items();
            wp_redirect(admin_url('admin.php?page=pms-automation-admin-settings&cleaned=1'));
            exit;
        }
        
        // 重试失败的任务
        if (isset($_GET['action']) && $_GET['action'] === 'retry_task' && isset($_GET['task_id'])) {
            $task_id = intval($_GET['task_id']);
            $this->retry_queue_task($task_id);
            wp_redirect(admin_url('admin.php?page=pms-automation-admin-settings&retried=1'));
            exit;
        }
        
        // 强制启动任务
        if (isset($_GET['action']) && $_GET['action'] === 'force_start_task' && isset($_GET['task_id'])) {
            $task_id = intval($_GET['task_id']);
            $this->force_start_queue_task($task_id);
            wp_redirect(admin_url('admin.php?page=pms-automation-admin-settings&force_started=1'));
            exit;
        }
    }
    
    /**
     * 手动标记用户为已付费
     */
    public function manually_mark_user_as_paid($user_id) {
        update_user_meta($user_id, '_pms_has_paid', 'yes');
        update_user_meta($user_id, '_pms_needs_automation', '1');
        update_user_meta($user_id, '_pms_automation_status', 'domain_required');
        
        $this->log_debug("手动标记用户 {$user_id} 为已付费");
        
        // 发送通知
        $this->send_automation_notification($user_id, 0);
        
        return true;
    }
    
    /**
     * 发送自动化配置通知
     */
    private function send_automation_notification($user_id, $payment_id) {
        $user = get_userdata($user_id);
        if (!$user) return;
        
        $payment = function_exists('pms_get_payment') ? pms_get_payment($payment_id) : null;
        
        $to = $user->user_email;
        $subject = __('您的WordPress自动化配置已准备就绪', 'pms-secupay-automation-admin');
        
        $account_url = function_exists('pms_get_page_url') ? pms_get_page_url('account') : site_url('/account/');
        
        $message = sprintf(
            __('亲爱的 %s，<br><br>感谢您的支付！您的会员订阅已激活。<br><br>现在您可以开始自动化配置您的WordPress网站了。<br><br>请登录您的账户页面开始配置：<br><a href="%s">%s</a><br><br>此致，<br>%s', 'pms-secupay-automation-admin'),
            $user->display_name,
            $account_url,
            $account_url,
            get_bloginfo('name')
        );
        
        $headers = array('Content-Type: text/html; charset=UTF-8');
        
        wp_mail($to, $subject, $message, $headers);
        
        $this->log_debug("已发送通知邮件给用户 {$user_id}");
    }
    
    /**
     * 清理卡住的任务
     */
    private function cleanup_stalled_queue_items() {
        global $wpdb;
        
        $queue_table = $wpdb->prefix . 'pms_automation_queue';
        $process_table = $wpdb->prefix . 'pms_automation_processes';
        
        // 检查表是否存在
        $queue_table_exists = $wpdb->get_var("SHOW TABLES LIKE '$queue_table'");
        $process_table_exists = $wpdb->get_var("SHOW TABLES LIKE '$process_table'");
        
        if (!$queue_table_exists) {
            return 0;
        }
        
        // 查找运行超过30分钟的任务
        $thirty_minutes_ago = date('Y-m-d H:i:s', strtotime('-30 minutes'));
        $stalled_tasks = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $queue_table WHERE status = 'running' AND started_at < %s",
            $thirty_minutes_ago
        ));
        
        $count = 0;
        foreach ($stalled_tasks as $task) {
            $this->log_debug("清理卡住的任务 - 队列ID: {$task->id}, 用户: {$task->user_id}");
            
            // 尝试终止进程
            if ($task->pid) {
                $this->kill_process_with_sudo($task->pid);
            }
            
            // 标记为失败
            $this->update_queue_status($task->id, 'failed', 0, '任务执行超时（超过30分钟）');
            
            // 更新进程表
            if ($process_table_exists) {
                $wpdb->update(
                    $process_table,
                    array(
                        'status' => 'stalled',
                        'last_check_at' => current_time('mysql')
                    ),
                    array('queue_id' => $task->id),
                    array('%s', '%s'),
                    array('%d')
                );
            }
            
            $count++;
        }
        
        return $count;
    }
    
    /**
     * 重试队列任务
     */
    private function retry_queue_task($task_id) {
        global $wpdb;
        $queue_table = $wpdb->prefix . 'pms_automation_queue';
        
        // 检查表是否存在
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$queue_table'");
        if (!$table_exists) {
            return false;
        }
        
        $task = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $queue_table WHERE id = %d",
            $task_id
        ));
        
        if ($task && $task->status === 'failed') {
            $wpdb->update(
                $queue_table,
                array(
                    'status' => 'queued',
                    'progress' => 0,
                    'error_message' => null,
                    'started_at' => null,
                    'completed_at' => null,
                    'pid' => null,
                    'updated_at' => current_time('mysql')
                ),
                array('id' => $task_id),
                array('%s', '%d', '%s', '%s', '%s', '%d', '%s'),
                array('%d')
            );
            
            // 更新用户元数据
            update_user_meta($task->user_id, '_pms_automation_status', 'queued');
            update_user_meta($task->user_id, '_pms_automation_progress', 0);
            delete_user_meta($task->user_id, '_pms_automation_error');
            
            $this->log_debug("任务 {$task_id} 已重新加入队列");
            return true;
        }
        
        return false;
    }
    
    /**
     * 强制启动队列任务
     */
    private function force_start_queue_task($task_id) {
        global $wpdb;
        $queue_table = $wpdb->prefix . 'pms_automation_queue';
        
        // 检查表是否存在
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$queue_table'");
        if (!$table_exists) {
            return false;
        }
        
        $task = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $queue_table WHERE id = %d",
            $task_id
        ));
        
        if ($task && ($task->status === 'queued' || $task->status === 'failed')) {
            // 更新任务状态为运行中
            $wpdb->update(
                $queue_table,
                array(
                    'status' => 'running',
                    'started_at' => current_time('mysql'),
                    'progress' => 5,
                    'updated_at' => current_time('mysql')
                ),
                array('id' => $task_id),
                array('%s', '%s', '%d', '%s'),
                array('%d')
            );
            
            // 更新用户元数据
            update_user_meta($task->user_id, '_pms_automation_status', 'running');
            update_user_meta($task->user_id, '_pms_automation_progress', 5);
            
            $this->log_debug("任务 {$task_id} 已被强制启动");
            return true;
        }
        
        return false;
    }
    
    /**
     * 杀死进程
     */
    private function kill_process_with_sudo($pid) {
        if (!$pid) {
            return false;
        }
        
        $command = "kill -9 {$pid} 2>/dev/null";
        $result = shell_exec($command);
        
        $this->log_debug("杀死进程 - PID: {$pid}, 结果: " . ($result === null ? '成功' : '可能失败'));
        
        return $result === null;
    }
    
    /**
     * 更新队列状态
     */
    private function update_queue_status($queue_id, $status, $progress, $error_message = null) {
        global $wpdb;
        
        $queue_table = $wpdb->prefix . 'pms_automation_queue';
        $process_table = $wpdb->prefix . 'pms_automation_processes';
        
        // 检查表是否存在
        $queue_table_exists = $wpdb->get_var("SHOW TABLES LIKE '$queue_table'");
        $process_table_exists = $wpdb->get_var("SHOW TABLES LIKE '$process_table'");
        
        if (!$queue_table_exists) {
            return;
        }
        
        $update_data = array(
            'status' => $status,
            'progress' => $progress,
            'updated_at' => current_time('mysql')
        );
        
        if ($status === 'completed') {
            $update_data['completed_at'] = current_time('mysql');
            $update_data['estimated_wait_time'] = 0;
        } elseif ($status === 'failed' && $error_message) {
            $update_data['error_message'] = substr($error_message, 0, 1000);
        }
        
        $wpdb->update(
            $queue_table,
            $update_data,
            array('id' => $queue_id),
            array('%s', '%d', '%s', '%s', '%d', '%s'),
            array('%d')
        );
        
        // 获取任务信息
        $task = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $queue_table WHERE id = %d",
            $queue_id
        ));
        
        if ($task) {
            // 更新用户元数据
            update_user_meta($task->user_id, '_pms_automation_status', $status);
            update_user_meta($task->user_id, '_pms_automation_progress', $progress);
            
            if ($status === 'completed') {
                update_user_meta($task->user_id, '_pms_automation_completed_time', current_time('mysql'));
                update_user_meta($task->user_id, '_pms_automation_domain', $task->domain);
                delete_user_meta($task->user_id, '_pms_needs_automation');
                
                // 发送完成邮件
                $this->send_completion_email($task->user_id);
                
                $this->log_debug("队列任务完成 - 队列ID: {$queue_id}, 用户: {$task->user_id}, 域名: {$task->domain}");
            } elseif ($status === 'failed') {
                update_user_meta($task->user_id, '_pms_automation_error', $error_message);
                
                $this->log_debug("队列任务失败 - 队列ID: {$queue_id}, 用户: {$task->user_id}");
            }
            
            // 更新进程表
            if ($process_table_exists && ($status === 'completed' || $status === 'failed')) {
                $wpdb->update(
                    $process_table,
                    array(
                        'status' => $status === 'completed' ? 'completed' : 'failed',
                        'last_check_at' => current_time('mysql')
                    ),
                    array('queue_id' => $queue_id),
                    array('%s', '%s'),
                    array('%d')
                );
            }
        }
    }
    
    /**
     * 发送完成邮件
     */
    private function send_completion_email($user_id) {
        $user = get_userdata($user_id);
        if (!$user) return;
        
        $domain = get_user_meta($user_id, '_pms_automation_domain', true);
        $wp_password = get_user_meta($user_id, '_pms_wordpress_password', true);
        
        $to = $user->user_email;
        $subject = __('您的WordPress自动化配置已完成', 'pms-secupay-automation-admin');
        
        $message = sprintf(
            __('亲爱的 %s，<br><br>您的WordPress自动化配置已成功完成！<br><br>您的网站详情：<br>网站地址: https://%s<br>WordPress管理员密码: %s<br><br>现在您可以访问您的网站并开始使用了。<br>请使用以下信息登录WordPress后台：<br>用户名: admin<br>密码: %s<br><br>此致，<br>%s', 'pms-secupay-automation-admin'),
            $user->display_name,
            esc_html($domain),
            esc_html($wp_password),
            esc_html($wp_password),
            get_bloginfo('name')
        );
        
        $headers = array('Content-Type: text/html; charset=UTF-8');
        
        wp_mail($to, $subject, $message, $headers);
        
        $this->log_debug("已发送完成邮件给用户 {$user_id}");
    }
    
    // ==================== AJAX方法 ====================
    
    /**
     * AJAX：测试脚本执行
     */
    public function ajax_test_script_execution() {
        if (!wp_verify_nonce($_POST['nonce'], 'pms_test_script')) {
            wp_send_json_error('安全验证失败');
        }
        
        // 检查是否为管理员
        if ($this->admin_only_debug && !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => '权限不足'));
        }
        
        $script_path = defined('PMS_AUTOMATION_SCRIPT_PATH') ? PMS_AUTOMATION_SCRIPT_PATH : '';
        
        $test_commands = array(
            'whoami' => shell_exec('whoami'),
            'pwd' => shell_exec('pwd'),
            'script_exists' => file_exists($script_path) ? '是' : '否',
            'script_permissions' => file_exists($script_path) ? substr(sprintf('%o', fileperms($script_path)), -4) : '文件不存在',
            'is_executable' => is_executable($script_path) ? '是' : '否',
            'test_command' => shell_exec('echo "test"'),
            'php_version' => phpversion(),
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? '未知',
            'sudo_whoami' => trim(shell_exec('sudo -n whoami 2>&1')),
            'sudo_test_command' => trim(shell_exec('sudo -n echo "test" 2>&1'))
        );
        
        wp_send_json_success($test_commands);
    }
    
    /**
     * AJAX：测试sudo访问
     */
    public function ajax_test_sudo_access() {
        if (!wp_verify_nonce($_POST['nonce'], 'pms_automation_nonce')) {
            wp_send_json_error(array('message' => '安全验证失败'));
        }
        
        // 检查是否为管理员
        if ($this->admin_only_debug && !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => '权限不足'));
        }
        
        // 测试sudo
        $sudo_enabled = false;
        $current_user = trim(shell_exec('whoami'));
        
        if ($current_user === 'root') {
            $sudo_enabled = true;
            $message = __('✅ 当前以root身份运行，无需sudo。', 'pms-secupay-automation-admin');
        } else {
            // 测试sudo命令
            $test_sudo = shell_exec('sudo -n echo "test" 2>&1');
            if ($test_sudo && trim($test_sudo) === 'test') {
                $sudo_enabled = true;
                $message = __('✅ Sudo访问已配置。', 'pms-secupay-automation-admin');
            } else {
                $message = __('⚠️ Sudo访问未配置，将使用当前用户权限执行。', 'pms-secupay-automation-admin');
            }
        }
        
        wp_send_json_success(array(
            'message' => $message,
            'sudo_enabled' => $sudo_enabled,
            'current_user' => $current_user
        ));
    }
    
    /**
     * AJAX：系统诊断
     */
    public function ajax_debug_system() {
        if (!wp_verify_nonce($_POST['nonce'], 'pms_automation_nonce')) {
            wp_send_json_error(array('message' => '安全验证失败'));
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => '权限不足'));
        }
        
        $script_path = defined('PMS_AUTOMATION_SCRIPT_PATH') ? PMS_AUTOMATION_SCRIPT_PATH : '';
        
        $debug_info = array(
            'server' => array(
                'php_version' => phpversion(),
                'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? '未知',
                'server_name' => $_SERVER['SERVER_NAME'] ?? '未知',
                'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? '未知',
                'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? '未知',
            ),
            'wordpress' => array(
                'wp_version' => get_bloginfo('version'),
                'wp_url' => get_bloginfo('url'),
                'wp_directory' => ABSPATH,
            ),
            'plugin' => array(
                'admin_plugin_path' => PMS_AUTOMATION_ADMIN_PATH,
                'script_path' => $script_path,
                'script_exists' => file_exists($script_path) ? '是' : '否',
                'script_executable' => is_executable($script_path) ? '是' : '否',
                'debug_mode' => $this->debug_mode ? '是' : '否',
            ),
            'permissions' => array(
                'plugin_dir_writable' => is_writable(PMS_AUTOMATION_ADMIN_PATH) ? '是' : '否',
            ),
            'functions' => array(
                'shell_exec' => function_exists('shell_exec') ? '可用' : '禁用',
                'exec' => function_exists('exec') ? '可用' : '禁用',
                'system' => function_exists('system') ? '可用' : '禁用',
                'passthru' => function_exists('passthru') ? '可用' : '禁用',
                'proc_open' => function_exists('proc_open') ? '可用' : '禁用',
            ),
            'queue' => $this->get_queue_debug_info(),
            'payment_detection' => $this->get_payment_detection_debug_info(),
            'user_status' => $this->get_user_status_debug_info(),
        );
        
        wp_send_json_success($debug_info);
    }
    
    /**
     * AJAX：获取实时日志
     */
    public function ajax_get_realtime_log() {
        if (!wp_verify_nonce($_POST['nonce'], 'pms_automation_nonce')) {
            wp_send_json_error(array('message' => '安全验证失败'));
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => '权限不足'));
        }
        
        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        $task_id = isset($_POST['task_id']) ? intval($_POST['task_id']) : 0;
        
        if (!$user_id && !$task_id) {
            wp_send_json_error(array('message' => '缺少必要的参数'));
        }
        
        // 尝试获取日志文件路径
        $output_file = '';
        
        if ($task_id) {
            // 通过任务ID获取日志文件
            global $wpdb;
            $queue_table = $wpdb->prefix . 'pms_automation_queue';
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$queue_table'");
            
            if ($table_exists) {
                $task = $wpdb->get_row($wpdb->prepare(
                    "SELECT output_file, user_id FROM $queue_table WHERE id = %d",
                    $task_id
                ));
                
                if ($task) {
                    $output_file = $task->output_file;
                    $user_id = $task->user_id;
                }
            }
        } else if ($user_id) {
            // 通过用户ID获取日志文件
            global $wpdb;
            $queue_table = $wpdb->prefix . 'pms_automation_queue';
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$queue_table'");
            
            if ($table_exists) {
                $task = $wpdb->get_row($wpdb->prepare(
                    "SELECT output_file, id FROM $queue_table WHERE user_id = %d ORDER BY id DESC LIMIT 1",
                    $user_id
                ));
                
                if ($task) {
                    $output_file = $task->output_file;
                    $task_id = $task->id;
                } else {
                    // 尝试从用户元数据获取
                    $output_file = get_user_meta($user_id, '_pms_automation_output_file', true);
                }
            } else {
                // 尝试从用户元数据获取
                $output_file = get_user_meta($user_id, '_pms_automation_output_file', true);
            }
        }
        
        // 如果找不到日志文件，尝试直接读取脚本输出
        if (!$output_file || !file_exists($output_file)) {
            // 尝试直接执行脚本并获取实时输出
            $result = shell_exec("tail -n 50 /var/log/syslog 2>/dev/null | grep -i 'wordpress.*deploy\\|pms\\|automation'");
            if ($result) {
                wp_send_json_success(array(
                    'log' => $result,
                    'file_exists' => false,
                    'real_time' => true,
                    'task_id' => $task_id,
                    'user_id' => $user_id
                ));
            }
            
            wp_send_json_error(array('message' => '未找到日志文件'));
        }
        
        // 读取日志文件内容（最后100行）
        $lines = array();
        if (file_exists($output_file)) {
            $file_content = file_get_contents($output_file);
            if ($file_content) {
                $lines = explode("\n", $file_content);
                $lines = array_slice($lines, -100); // 获取最后100行
            }
        }
        
        // 检查进程状态
        $status = 'unknown';
        $pid = null;
        $is_running = false;
        
        global $wpdb;
        $queue_table = $wpdb->prefix . 'pms_automation_queue';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$queue_table'");
        
        if ($table_exists && $task_id) {
            $task = $wpdb->get_row($wpdb->prepare(
                "SELECT status, pid FROM $queue_table WHERE id = %d",
                $task_id
            ));
            
            if ($task) {
                $status = $task->status;
                $pid = $task->pid;
                
                // 检查进程是否仍在运行
                if ($pid) {
                    $is_running = $this->check_process_with_sudo($pid);
                }
            }
        }
        
        wp_send_json_success(array(
            'log' => implode("\n", $lines),
            'file_exists' => true,
            'status' => $status,
            'pid' => $pid,
            'is_running' => $is_running,
            'task_id' => $task_id,
            'user_id' => $user_id,
            'output_file' => $output_file
        ));
    }
    
    /**
     * AJAX：执行SSH式命令
     */
    public function ajax_execute_ssh_like_command() {
        if (!wp_verify_nonce($_POST['nonce'], 'pms_automation_nonce')) {
            wp_send_json_error(array('message' => '安全验证失败'));
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => '权限不足'));
        }
        
        $command = isset($_POST['command']) ? sanitize_text_field($_POST['command']) : '';
        $task_id = isset($_POST['task_id']) ? intval($_POST['task_id']) : 0;
        
        if (!$command) {
            wp_send_json_error(array('message' => '请输入命令'));
        }
        
        // 安全过滤：只允许特定的监控命令
        $allowed_commands = array(
            'ps aux',
            'tail ',
            'cat ',
            'grep ',
            'docker ps',
            'docker logs',
            'sudo ',
            'whoami',
            'pwd',
            'ls ',
            'df ',
            'free ',
            'top ',
            'htop',
            'netstat'
        );
        
        $is_allowed = false;
        foreach ($allowed_commands as $allowed) {
            if (strpos($command, $allowed) === 0) {
                $is_allowed = true;
                break;
            }
        }
        
        // 允许以特定前缀开头的命令
        if (!$is_allowed && (strpos($command, 'cd ') === 0 || strpos($command, 'echo ') === 0)) {
            $is_allowed = true;
        }
        
        if (!$is_allowed) {
            wp_send_json_error(array('message' => '命令不被允许: ' . substr($command, 0, 50)));
        }
        
        // 如果是任务相关的命令，添加任务特定信息
        if ($task_id) {
            global $wpdb;
            $queue_table = $wpdb->prefix . 'pms_automation_queue';
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$queue_table'");
            
            if ($table_exists) {
                $task = $wpdb->get_row($wpdb->prepare(
                    "SELECT pid, domain FROM $queue_table WHERE id = %d",
                    $task_id
                ));
                
                if ($task && $task->pid && $task->domain) {
                    // 如果是docker日志命令，自动添加容器名
                    if (strpos($command, 'docker logs') !== false) {
                        $container_name = 'wordpress_' . str_replace('.', '_', $task->domain);
                        $command = "docker logs --tail=50 " . escapeshellarg($container_name);
                    }
                }
            }
        }
        
        // 执行命令
        $result = shell_exec($command . " 2>&1");
        
        wp_send_json_success(array(
            'output' => $result ?: '(无输出)',
            'command' => $command
        ));
    }
    
    /**
     * 检查进程是否在运行
     */
    private function check_process_with_sudo($pid) {
        if (!$pid) return false;
        $command = "ps -p {$pid} 2>/dev/null | grep -v PID";
        $result = shell_exec($command);
        return !empty(trim($result));
    }
    
    /**
     * 获取支付检测调试信息
     */
    private function get_payment_detection_debug_info() {
        global $wpdb;
        
        // 获取已标记为已付费的用户数量
        $paid_users = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s",
                '_pms_has_paid',
                'yes'
            )
        );
        
        // 获取需要自动化的用户数量
        $automation_users = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s",
                '_pms_needs_automation',
                '1'
            )
        );
        
        // 获取最近支付记录
        $payment_table = $wpdb->prefix . 'pms_payments';
        $recent_payments = array();
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$payment_table'") == $payment_table) {
            $recent_payments_result = $wpdb->get_results(
                "SELECT user_id, status, payment_gateway, amount, date 
                 FROM {$payment_table} 
                 WHERE status = 'completed' 
                 ORDER BY id DESC 
                 LIMIT 5"
            );
            
            foreach ($recent_payments_result as $payment) {
                $recent_payments[] = array(
                    'user_id' => $payment->user_id,
                    'status' => $payment->status,
                    'gateway' => $payment->payment_gateway,
                    'amount' => $payment->amount,
                    'date' => $payment->date
                );
            }
        }
        
        return array(
            'paid_users_count' => intval($paid_users),
            'automation_users_count' => intval($automation_users),
            'recent_payments' => $recent_payments,
            'payment_table_exists' => $wpdb->get_var("SHOW TABLES LIKE '$payment_table'") ? '是' : '否',
            'subscriptions_table_exists' => $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}pms_member_subscriptions'") ? '是' : '否'
        );
    }
    
    /**
     * 获取用户状态调试信息 - 修复版
     */
    private function get_user_status_debug_info() {
        global $wpdb;
        
        $user_status_table = $wpdb->prefix . 'pms_user_automation_status';
        $user_status = array();
        
        // 先检查表是否存在
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$user_status_table'");
        
        if (!$table_exists) {
            return array(
                'table_exists' => '否',
                'total_records' => 0,
                'recent_status' => array()
            );
        }
        
        $recent_status = $wpdb->get_results(
            "SELECT u.user_id, u.domain, u.status, u.progress, u.last_updated, 
                    usr.user_login, usr.user_email
             FROM $user_status_table u
             LEFT JOIN {$wpdb->users} usr ON u.user_id = usr.ID
             ORDER BY u.last_updated DESC 
             LIMIT 10"
        );
        
        foreach ($recent_status as $status) {
            $user_status[] = array(
                'user_id' => isset($status->user_id) ? $status->user_id : 0,
                'user_login' => isset($status->user_login) ? $status->user_login : '',
                'user_email' => isset($status->user_email) ? $status->user_email : '',
                'domain' => isset($status->domain) ? $status->domain : '',
                'status' => isset($status->status) ? $status->status : '',
                'progress' => isset($status->progress) ? $status->progress : 0,
                'last_updated' => isset($status->last_updated) ? $status->last_updated : ''
            );
        }
        
        return array(
            'table_exists' => '是',
            'total_records' => $wpdb->get_var("SELECT COUNT(*) FROM $user_status_table"),
            'recent_status' => $user_status
        );
    }
    
    /**
     * 获取队列调试信息
     */
    private function get_queue_debug_info() {
        global $wpdb;
        
        $queue_table = $wpdb->prefix . 'pms_automation_queue';
        $process_table = $wpdb->prefix . 'pms_automation_processes';
        
        // 检查表是否存在
        $queue_table_exists = $wpdb->get_var("SHOW TABLES LIKE '$queue_table'") ? '是' : '否';
        
        if ($queue_table_exists !== '是') {
            return array('enabled' => false, 'message' => '队列表不存在');
        }
        
        $waiting = $wpdb->get_var("SELECT COUNT(*) FROM $queue_table WHERE status = 'queued'");
        $running = $wpdb->get_var("SELECT COUNT(*) FROM $queue_table WHERE status = 'running'");
        $completed = $wpdb->get_var("SELECT COUNT(*) FROM $queue_table WHERE status = 'completed'");
        $failed = $wpdb->get_var("SELECT COUNT(*) FROM $queue_table WHERE status = 'failed'");
        $cancelled = $wpdb->get_var("SELECT COUNT(*) FROM $queue_table WHERE status = 'cancelled'");
        $total = $waiting + $running + $completed + $failed + $cancelled;
        
        $recent_tasks = $wpdb->get_results(
            "SELECT q.*, u.user_login, u.user_email 
             FROM $queue_table q
             LEFT JOIN {$wpdb->users} u ON q.user_id = u.ID
             ORDER BY q.created_at DESC 
             LIMIT 10"
        );
        
        $tasks = array();
        foreach ($recent_tasks as $task) {
            $tasks[] = array(
                'id' => $task->id,
                'user_id' => $task->user_id,
                'user_login' => $task->user_login,
                'user_email' => $task->user_email,
                'domain' => $task->domain,
                'status' => $task->status,
                'queue_position' => $task->queue_position,
                'progress' => $task->progress,
                'created_at' => $task->created_at,
                'started_at' => $task->started_at,
                'completed_at' => $task->completed_at,
                'pid' => $task->pid,
                'output_file' => $task->output_file
            );
        }
        
        return array(
            'enabled' => true,
            'waiting' => intval($waiting),
            'running' => intval($running),
            'completed' => intval($completed),
            'failed' => intval($failed),
            'cancelled' => intval($cancelled),
            'total' => intval($total),
            'recent_tasks' => $tasks,
            'table_exists' => $queue_table_exists,
            'process_table_exists' => $wpdb->get_var("SHOW TABLES LIKE '$process_table'") ? '是' : '否'
        );
    }
    
    // ==================== 管理员界面方法 ====================
    
    /**
     * 添加管理员菜单
     */
    public function add_admin_menu() {
        add_submenu_page(
            'paid-member-subscriptions',
            '自动化配置管理',
            '自动化配置',
            'manage_options',
            'pms-automation-admin-settings',
            array($this, 'render_admin_page')
        );
    }
    
    /**
     * 注册设置
     */
    public function register_settings() {
        register_setting('pms_automation_admin_settings_group', 'pms_automation_admin_settings');
        register_setting('pms_automation_admin_settings_group', 'pms_automation_debug_mode');
    }
    
    /**
     * 渲染管理页面
     */
    public function render_admin_page() {
        $settings = get_option('pms_automation_admin_settings', array());
        $debug_mode = get_option('pms_automation_debug_mode', false);
        
        // 显示操作结果消息
        if (isset($_GET['marked'])) {
            echo '<div class="notice notice-success"><p>用户已成功标记为需要自动化配置。</p></div>';
        }
        if (isset($_GET['reset'])) {
            echo '<div class="notice notice-success"><p>用户自动化状态已重置。</p></div>';
        }
        if (isset($_GET['marked_paid'])) {
            echo '<div class="notice notice-success"><p>用户已成功标记为已付费。</p></div>';
        }
        if (isset($_GET['cleaned'])) {
            echo '<div class="notice notice-success"><p>队列已清理，卡住的任务已重置。</p></div>';
        }
        if (isset($_GET['retried'])) {
            echo '<div class="notice notice-success"><p>失败任务已重新加入队列。</p></div>';
        }
        if (isset($_GET['force_started'])) {
            echo '<div class="notice notice-success"><p>任务已被强制启动。</p></div>';
        }
        ?>
        <div class="wrap">
            <h1>PMS Secupay 自动化配置管理面板</h1>
            
            <div class="Auto_wp_Backend_card" style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin: 20px 0;">
                <h2>系统设置</h2>
                <form method="post" action="options.php">
                    <?php settings_fields('pms_automation_admin_settings_group'); ?>
                    <table class="form-table">
                        <tr>
                            <th><label for="pms_automation_debug_mode">调试模式</label></th>
                            <td>
                                <input type="checkbox" id="pms_automation_debug_mode" name="pms_automation_debug_mode" value="1" <?php checked($debug_mode, 1); ?> />
                                <p class="description">启用调试模式，将在error_log中记录详细信息。</p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button('保存设置'); ?>
                </form>
            </div>
            
            <div class="Auto_wp_Backend_card" style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin: 20px 0;">
                <h2>系统状态</h2>
                <table class="form-table">
                    <tr>
                        <th>插件版本:</th>
                        <td><?php echo PMS_AUTOMATION_ADMIN_VERSION; ?></td>
                    </tr>
                    <tr>
                        <th>脚本路径:</th>
                        <td>
                            <?php 
                            $script_path = defined('PMS_AUTOMATION_SCRIPT_PATH') ? PMS_AUTOMATION_SCRIPT_PATH : '未定义';
                            if (file_exists($script_path)): ?>
                                <span style="color: green;">✅ <?php echo $script_path; ?></span>
                                <p>权限: <?php echo substr(sprintf('%o', fileperms($script_path)), -4); ?></p>
                            <?php else: ?>
                                <span style="color: red;">❌ 脚本文件不存在</span>
                                <p>请确保宿主机脚本存在且可访问</p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>脚本可执行:</th>
                        <td>
                            <?php 
                            $script_path = defined('PMS_AUTOMATION_SCRIPT_PATH') ? PMS_AUTOMATION_SCRIPT_PATH : '';
                            if (is_executable($script_path)): ?>
                                <span style="color: green;">✅ 是</span>
                            <?php else: ?>
                                <span style="color: red;">❌ 否</span>
                                <p>需要在宿主机上设置执行权限: sudo chmod 755 <?php echo $script_path; ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>shell_exec 可用:</th>
                        <td>
                            <?php echo function_exists('shell_exec') ? '<span style="color: green;">✅ 是</span>' : '<span style="color: red;">❌ 否</span>'; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>proc_open 可用:</th>
                        <td>
                            <?php echo function_exists('proc_open') ? '<span style="color: green;">✅ 是</span>' : '<span style="color: red;">❌ 否</span>'; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Sudo 支持:</th>
                        <td>
                            <?php 
                            $current_user = trim(shell_exec('whoami'));
                            if ($current_user === 'root'): ?>
                                <span style="color: green;">✅ 当前以root身份运行，无需sudo</span>
                            <?php else: ?>
                                <?php 
                                $test_sudo = shell_exec('sudo -n echo "test" 2>&1');
                                if ($test_sudo && trim($test_sudo) === 'test'): ?>
                                    <span style="color: green;">✅ 已启用 (当前用户: <?php echo $current_user; ?>)</span>
                                <?php else: ?>
                                    <span style="color: orange;">⚠️ 未启用 (将使用当前用户权限)</span>
                                    <p>当前用户: <?php echo $current_user; ?></p>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>队列系统:</th>
                        <td>
                            <?php 
                            $queue_info = $this->get_queue_debug_info();
                            if ($queue_info['enabled']): ?>
                                <span style="color: green;">✅ 已启用</span>
                                <p>等待中: <?php echo $queue_info['waiting']; ?> | 运行中: <?php echo $queue_info['running']; ?> | 已完成: <?php echo $queue_info['completed']; ?> | 失败: <?php echo $queue_info['failed']; ?></p>
                            <?php else: ?>
                                <span style="color: orange;">⚠️ 未启用或表不存在</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>用户状态表:</th>
                        <td>
                            <?php 
                            $user_status_info = $this->get_user_status_debug_info();
                            if ($user_status_info['table_exists'] === '是'): ?>
                                <span style="color: green;">✅ 已启用 (记录数: <?php echo $user_status_info['total_records']; ?>)</span>
                            <?php else: ?>
                                <span style="color: orange;">⚠️ 表不存在</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>测试脚本执行:</th>
                        <td>
                            <button id="test-script-execution" class="button button-primary">测试脚本执行</button>
                            <div id="test-result" style="margin-top: 10px;"></div>
                        </td>
                    </tr>
                    <tr>
                        <th>测试sudo访问:</th>
                        <td>
                            <button id="test-sudo-access" class="button button-secondary">测试sudo访问</button>
                            <div id="sudo-test-result" style="margin-top: 10px;"></div>
                        </td>
                    </tr>
                    <tr>
                        <th>系统诊断:</th>
                        <td>
                            <button id="debug-system" class="button button-primary">运行系统诊断</button>
                            <div id="debug-result" style="margin-top: 10px;"></div>
                        </td>
                    </tr>
                </table>
            </div>
            
            <div class="Auto_wp_Backend_card" style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin: 20px 0;">
                <h2>队列管理</h2>
                <?php 
                $queue_info = $this->get_queue_debug_info();
                if ($queue_info['enabled']): 
                    global $wpdb;
                    $queue_table = $wpdb->prefix . 'pms_automation_queue';
                    
                    $waiting = $queue_info['waiting'];
                    $running = $queue_info['running'];
                    $completed = $queue_info['completed'];
                    $failed = $queue_info['failed'];
                    $cancelled = $queue_info['cancelled'];
                    $total = $queue_info['total'];
                    
                    // 获取队列中的任务
                    $queue_tasks = $wpdb->get_results(
                        "SELECT q.*, u.user_login, u.user_email 
                         FROM $queue_table q
                         LEFT JOIN {$wpdb->users} u ON q.user_id = u.ID
                         WHERE q.status IN ('queued', 'running', 'failed') 
                         ORDER BY 
                           CASE q.status 
                             WHEN 'running' THEN 1
                             WHEN 'queued' THEN 2
                             WHEN 'failed' THEN 3
                             ELSE 4
                           END,
                           q.queue_position ASC 
                         LIMIT 20"
                    );
                    ?>
                    
                    <div class="queue-stats" style="margin-bottom: 20px;">
                        <h3>队列统计</h3>
                        <table class="widefat striped">
                            <thead>
                                <tr>
                                    <th>状态</th>
                                    <th>数量</th>
                                    <th>百分比</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>等待中</td>
                                    <td><?php echo $waiting; ?></td>
                                    <td><?php echo $total > 0 ? round($waiting/$total*100, 2) : 0; ?>%</td>
                                </tr>
                                <tr>
                                    <td>运行中</td>
                                    <td><?php echo $running; ?></td>
                                    <td><?php echo $total > 0 ? round($running/$total*100, 2) : 0; ?>%</td>
                                </tr>
                                <tr>
                                    <td>已完成</td>
                                    <td><?php echo $completed; ?></td>
                                    <td><?php echo $total > 0 ? round($completed/$total*100, 2) : 0; ?>%</td>
                                </tr>
                                <tr>
                                    <td>失败</td>
                                    <td><?php echo $failed; ?></td>
                                    <td><?php echo $total > 0 ? round($failed/$total*100, 2) : 0; ?>%</td>
                                </tr>
                                <tr>
                                    <td>已取消</td>
                                    <td><?php echo $cancelled; ?></td>
                                    <td><?php echo $total > 0 ? round($cancelled/$total*100, 2) : 0; ?>%</td>
                                </tr>
                                <tr>
                                    <th>总计</th>
                                    <th><?php echo $total; ?></th>
                                    <th>100%</th>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="queue-actions" style="margin: 20px 0;">
                        <a href="<?php echo admin_url('admin.php?page=pms-automation-admin-settings&action=cleanup_queue'); ?>" class="button button-secondary" onclick="return confirm('确定要清理队列吗？这将重置所有卡住的任务。')">清理卡住的任务</a>
                        <button id="refresh-queue" class="button">刷新队列状态</button>
                    </div>
                    
                    <div class="queue-list">
                        <h3>当前队列 (<?php echo count($queue_tasks); ?> 个任务)</h3>
                        <?php if (!empty($queue_tasks)): ?>
                        <table class="widefat striped">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>用户</th>
                                    <th>域名</th>
                                    <th>状态</th>
                                    <th>进度</th>
                                    <th>队列位置</th>
                                    <th>创建时间</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($queue_tasks as $task): 
                                    $status_class = '';
                                    switch ($task->status) {
                                        case 'running': $status_class = 'status-running'; break;
                                        case 'queued': $status_class = 'status-queued'; break;
                                        case 'failed': $status_class = 'status-failed'; break;
                                        default: $status_class = '';
                                    }
                                ?>
                                <tr>
                                    <td><?php echo $task->id; ?></td>
                                    <td>
                                        <a href="<?php echo admin_url('user-edit.php?user_id=' . $task->user_id); ?>" target="_blank">
                                            <?php echo esc_html($task->user_login ?: '用户#' . $task->user_id); ?>
                                        </a>
                                        <br>
                                        <small><?php echo esc_html($task->user_email); ?></small>
                                    </td>
                                    <td><?php echo esc_html($task->domain); ?></td>
                                    <td>
                                        <span class="<?php echo $status_class; ?>">
                                            <?php if ($task->status === 'queued'): ?>
                                                等待中
                                            <?php elseif ($task->status === 'running'): ?>
                                                运行中
                                            <?php elseif ($task->status === 'failed'): ?>
                                                失败
                                            <?php else: ?>
                                                <?php echo $task->status; ?>
                                            <?php endif; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($task->status === 'running'): ?>
                                            <div style="width: 60px; background: #e0e0e0; border-radius: 3px; height: 20px;">
                                                <div style="width: <?php echo $task->progress; ?>%; background: #4CAF50; height: 100%; border-radius: 3px; font-size: 11px; color: #fff; text-align: center; line-height: 20px;">
                                                    <?php echo $task->progress; ?>%
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <?php echo $task->progress; ?>%
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $task->queue_position; ?></td>
                                    <td><?php echo $task->created_at; ?></td>
                                    <td>
                                        <?php if ($task->status === 'failed'): ?>
                                            <a href="<?php echo admin_url('admin.php?page=pms-automation-admin-settings&action=retry_task&task_id=' . $task->id); ?>" class="button button-small" onclick="return confirm('确定要重试此任务吗？')">重试</a>
                                        <?php endif; ?>
                                        
                                        <?php if ($task->status === 'queued'): ?>
                                            <a href="<?php echo admin_url('admin.php?page=pms-automation-admin-settings&action=force_start_task&task_id=' . $task->id); ?>" class="button button-small" onclick="return confirm('确定要强制启动此任务吗？')">强制启动</a>
                                        <?php endif; ?>
                                        
                                        <?php if ($task->status === 'running'): ?>
                                            <button class="button button-small view-task-log" data-task-id="<?php echo $task->id; ?>">查看日志</button>
                                        <?php endif; ?>
                                        
                                        <a href="<?php echo admin_url('admin.php?page=pms-automation-admin-settings&action=reset_user_automation&user_id=' . $task->user_id); ?>" class="button button-small" onclick="return confirm('确定要重置此用户的自动化状态吗？')">重置用户</a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php else: ?>
                        <p>队列中没有任务。</p>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <p>队列系统未启用或表不存在。</p>
                <?php endif; ?>
            </div>
            
            <div class="Auto_wp_Backend_card" style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin: 20px 0;">
                <h2>用户配置状态</h2>
                <?php $this->render_user_status_table(); ?>
            </div>
            
            <div class="Auto_wp_Backend_card" style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin: 20px 0;">
                <h2>手动管理</h2>
                <h3>手动标记用户为已付费</h3>
                <form method="post" action="<?php echo admin_url('admin.php'); ?>">
                    <input type="hidden" name="page" value="pms-automation-admin-settings">
                    <input type="hidden" name="action" value="mark_as_paid">
                    <p>
                        <label for="mark_user_id">用户ID:</label>
                        <input type="text" id="mark_user_id" name="user_id" value="" placeholder="输入用户ID">
                    </p>
                    <p>
                        <button type="submit" class="button button-primary">标记用户为已付费</button>
                    </p>
                    <p class="description">此操作会将用户标记为已付费，并发送自动化配置通知邮件。</p>
                </form>
                
                <h3>手动标记用户需要自动化</h3>
                <form method="get" action="<?php echo admin_url('admin.php'); ?>">
                    <input type="hidden" name="page" value="pms-automation-admin-settings">
                    <input type="hidden" name="action" value="mark_user_automation">
                    <p>
                        <label for="user_id">用户ID:</label>
                        <input type="text" id="user_id" name="user_id" value="" placeholder="输入用户ID">
                    </p>
                    <p>
                        <button type="submit" class="button button-primary">标记用户需要自动化</button>
                    </p>
                </form>
                
                <h3>查看所有用户</h3>
                <?php $this->render_all_users_table(); ?>
            </div>
            
            <div class="Auto_wp_Backend_card" style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin: 20px 0;">
                <h2>🔍 SSH式实时日志监控</h2>
                
                <div class="ssh-controls" style="margin-bottom: 20px;">
                    <div style="display: flex; gap: 10px; margin-bottom: 15px;">
                        <select id="ssh-user-select" class="ssh-select">
                            <option value="">选择用户</option>
                            <?php
                            global $wpdb;
                            $queue_table = $wpdb->prefix . 'pms_automation_queue';
                            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$queue_table'");
                            
                            if ($table_exists) {
                                $users_with_tasks = $wpdb->get_results(
                                    "SELECT DISTINCT q.user_id, u.user_login, u.display_name 
                                     FROM $queue_table q
                                     LEFT JOIN {$wpdb->users} u ON q.user_id = u.ID
                                     ORDER BY q.created_at DESC LIMIT 20"
                                );
                                
                                foreach ($users_with_tasks as $user):
                            ?>
                            <option value="<?php echo $user->user_id; ?>">
                                <?php echo esc_html($user->display_name ?: $user->user_login) . ' (ID: ' . $user->user_id . ')'; ?>
                            </option>
                            <?php endforeach; 
                            } ?>
                        </select>
                        
                        <select id="ssh-task-select" class="ssh-select">
                            <option value="">选择任务</option>
                            <?php
                            global $wpdb;
                            $queue_table = $wpdb->prefix . 'pms_automation_queue';
                            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$queue_table'");
                            
                            if ($table_exists) {
                                $recent_tasks = $wpdb->get_results(
                                    "SELECT q.id, q.user_id, q.domain, q.status, u.user_login 
                                     FROM $queue_table q
                                     LEFT JOIN {$wpdb->users} u ON q.user_id = u.ID
                                     ORDER BY q.id DESC LIMIT 20"
                                );
                                
                                foreach ($recent_tasks as $task):
                                    $status_text = $this->get_status_text($task->status);
                            ?>
                            <option value="<?php echo $task->id; ?>" data-user-id="<?php echo $task->user_id; ?>">
                                #<?php echo $task->id; ?> - <?php echo esc_html($task->domain); ?> 
                                (<?php echo esc_html($task->user_login); ?>, <?php echo $status_text; ?>)
                            </option>
                            <?php endforeach; 
                            } ?>
                        </select>
                        
                        <button id="ssh-start-monitoring" class="button button-primary">开始监控</button>
                        <button id="ssh-stop-monitoring" class="button button-secondary" disabled>停止监控</button>
                    </div>
                    
                    <div style="display: flex; gap: 10px; margin-bottom: 15px;">
                        <input type="text" id="ssh-command-input" placeholder="输入命令 (如: tail -f /path/to/log)" style="flex: 1; padding: 8px;">
                        <button id="ssh-execute-command" class="button">执行命令</button>
                    </div>
                    
                    <div class="ssh-quick-commands" style="display: flex; flex-wrap: wrap; gap: 5px; margin-bottom: 15px;">
                        <button class="button ssh-quick-command" data-command="tail -f -n 50 /var/log/syslog | grep -i wordpress">系统日志(WordPress相关)</button>
                        <button class="button ssh-quick-command" data-command="docker ps -a">Docker容器状态</button>
                        <button class="button ssh-quick-command" data-command="ps aux | grep -i deploy">部署进程</button>
                        <button class="button ssh-quick-command" data-command="df -h">磁盘空间</button>
                        <button class="button ssh-quick-command" data-command="free -m">内存使用</button>
                        <button class="button ssh-quick-command" data-command="ls -la /www/docker/wordpress/">脚本目录</button>
                    </div>
                </div>
                
                <div class="ssh-terminal-container" style="background: #000; color: #0f0; font-family: 'Courier New', monospace; padding: 15px; border-radius: 5px; max-height: 500px; overflow-y: auto;">
                    <div id="ssh-terminal-output" style="white-space: pre-wrap; word-break: break-all; font-size: 13px; line-height: 1.4;">
                        <div style="color: #0af;">🔍 SSH式实时日志监控终端 v1.0</div>
                        <div style="color: #aaa;">请选择一个用户或任务，然后点击"开始监控"</div>
                        <div style="color: #888;">可用命令示例:</div>
                        <div style="color: #888;">  • tail -f /path/to/log   - 实时跟踪日志</div>
                        <div style="color: #888;">  • docker ps              - 查看容器状态</div>
                        <div style="color: #888;">  • ps aux | grep deploy   - 查看部署进程</div>
                    </div>
                    
                    <div class="ssh-terminal-input" style="margin-top: 10px; display: none;">
                        <div style="display: flex;">
                            <span style="color: #0af;">$</span>
                            <input type="text" id="ssh-terminal-cmd" style="background: transparent; border: none; color: #0f0; flex: 1; margin-left: 5px; outline: none;" autocomplete="off">
                        </div>
                    </div>
                </div>
                
                <div style="margin-top: 15px; color: #666; font-size: 12px;">
                    <div>状态: <span id="ssh-status">空闲</span> | 最后更新: <span id="ssh-last-update">-</span></div>
                    <div>当前任务: <span id="ssh-current-task">无</span> | PID: <span id="ssh-current-pid">无</span></div>
                </div>
            </div>
            
            <div class="Auto_wp_Backend_card" style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin: 20px 0;">
                <h2>Docker环境配置说明</h2>
                <p>由于WordPress运行在Docker容器内，自动化脚本在宿主机上执行，需要特别注意：</p>
                <ol>
                    <li>确保宿主机脚本存在且可执行: <code><?php echo defined('PMS_AUTOMATION_SCRIPT_PATH') ? PMS_AUTOMATION_SCRIPT_PATH : '脚本路径未定义'; ?></code></li>
                    <li>设置宿主机脚本权限: <code>sudo chmod 755 <?php echo defined('PMS_AUTOMATION_SCRIPT_PATH') ? PMS_AUTOMATION_SCRIPT_PATH : '脚本路径'; ?></code></li>
                    <li>Docker容器可能需要root权限执行宿主机脚本</li>
                    <li>检查Docker容器与宿主机的文件权限映射</li>
                </ol>
                <p><strong>注意:</strong> 如果脚本无法执行，请检查Docker容器的用户权限和宿主机脚本权限。</p>
            </div>
            
            <div class="Auto_wp_Backend_card" style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin: 20px 0;">
                <h2>系统日志</h2>
                <?php $this->render_system_logs(); ?>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#test-script-execution').on('click', function() {
                var $button = $(this);
                $button.prop('disabled', true).text('测试中...');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'pms_test_script_execution',
                        nonce: '<?php echo wp_create_nonce('pms_test_script'); ?>'
                    },
                    success: function(response) {
                        $('#test-result').html('<pre>' + JSON.stringify(response.data, null, 2) + '</pre>');
                        $button.prop('disabled', false).text('测试脚本执行');
                    },
                    error: function() {
                        $('#test-result').html('<p style="color: red;">测试请求失败</p>');
                        $button.prop('disabled', false).text('测试脚本执行');
                    }
                });
            });
            
            $('#test-sudo-access').on('click', function() {
                var $button = $(this);
                $button.prop('disabled', true).text('测试中...');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'pms_test_sudo_access',
                        nonce: '<?php echo wp_create_nonce('pms_automation_nonce'); ?>'
                    },
                    success: function(response) {
                        var message = response.success ? 
                            '<p style="color: green;">' + response.data.message + '</p>' :
                            '<p style="color: orange;">' + response.data.message + '</p>';
                        $('#sudo-test-result').html(message);
                        $button.prop('disabled', false).text('测试sudo访问');
                    },
                    error: function() {
                        $('#sudo-test-result').html('<p style="color: red;">测试请求失败</p>');
                        $button.prop('disabled', false).text('测试sudo访问');
                    }
                });
            });
            
            $('#debug-system').on('click', function() {
                var $button = $(this);
                $button.prop('disabled', true).text('诊断中...');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'pms_debug_system',
                        nonce: '<?php echo wp_create_nonce('pms_automation_nonce'); ?>'
                    },
                    success: function(response) {
                        $('#debug-result').html('<pre>' + JSON.stringify(response.data, null, 2) + '</pre>');
                        $button.prop('disabled', false).text('运行系统诊断');
                    },
                    error: function() {
                        $('#debug-result').html('<p style="color: red;">诊断请求失败</p>');
                        $button.prop('disabled', false).text('运行系统诊断');
                    }
                });
            });
            
            $('#refresh-queue').on('click', function() {
                location.reload();
            });
            
            // 查看任务日志
            $('.view-task-log').on('click', function() {
                var taskId = $(this).data('task-id');
                var $modal = $('<div>').addClass('task-log-modal').css({
                    'position': 'fixed',
                    'top': '0',
                    'left': '0',
                    'width': '100%',
                    'height': '100%',
                    'background': 'rgba(0,0,0,0.8)',
                    'z-index': '9999',
                    'display': 'flex',
                    'align-items': 'center',
                    'justify-content': 'center'
                });
                
                var $content = $('<div>').css({
                    'background': '#000',
                    'color': '#0f0',
                    'padding': '20px',
                    'border-radius': '5px',
                    'max-width': '80%',
                    'max-height': '80%',
                    'overflow': 'auto',
                    'font-family': 'Courier New, monospace',
                    'font-size': '12px'
                }).html('加载中...');
                
                $modal.append($content);
                $('body').append($modal);
                
                // 加载日志
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'pms_get_realtime_log',
                        task_id: taskId,
                        nonce: '<?php echo wp_create_nonce('pms_automation_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $content.html('<pre>' + response.data.log + '</pre>');
                        } else {
                            $content.html('加载失败: ' + response.data.message);
                        }
                    }
                });
                
                // 点击关闭
                $modal.on('click', function(e) {
                    if (e.target === this) {
                        $(this).remove();
                    }
                });
            });
            
            // SSH式监控功能
            var monitoringInterval = null;
            var currentTaskId = null;
            var currentUserId = null;
            
            $('#ssh-start-monitoring').on('click', function() {
                var taskId = $('#ssh-task-select').val();
                var userId = $('#ssh-user-select').val();
                
                if (!taskId && !userId) {
                    alert('请选择要监控的用户或任务');
                    return;
                }
                
                if (taskId) {
                    var selectedOption = $('#ssh-task-select option:selected');
                    currentUserId = selectedOption.data('user-id');
                    currentTaskId = taskId;
                } else {
                    currentUserId = userId;
                    currentTaskId = null;
                }
                
                $('#ssh-start-monitoring').prop('disabled', true);
                $('#ssh-stop-monitoring').prop('disabled', false);
                
                // 更新状态显示
                $('#ssh-current-task').text(currentTaskId || currentUserId);
                
                // 开始轮询
                startSSHMonitoring();
                monitoringInterval = setInterval(startSSHMonitoring, 2000); // 每2秒更新一次
            });
            
            $('#ssh-stop-monitoring').on('click', function() {
                stopSSHMonitoring();
            });
            
            $('#ssh-execute-command').on('click', function() {
                var command = $('#ssh-command-input').val().trim();
                if (!command) return;
                
                executeSSHCommand(command);
            });
            
            $('.ssh-quick-command').on('click', function() {
                var command = $(this).data('command');
                $('#ssh-command-input').val(command);
                executeSSHCommand(command);
            });
            
            function startSSHMonitoring() {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'pms_get_realtime_log',
                        nonce: '<?php echo wp_create_nonce('pms_automation_nonce'); ?>',
                        task_id: currentTaskId,
                        user_id: currentUserId
                    },
                    success: function(response) {
                        if (response.success) {
                            var logContent = response.data.log;
                            var status = response.data.status || 'unknown';
                            var pid = response.data.pid || '无';
                            var isRunning = response.data.is_running;
                            
                            // 更新终端输出
                            var outputLines = logContent.split('\n').slice(-30); // 显示最后30行
                            $('#ssh-terminal-output').html(
                                '<div style="color: #0af;">📊 实时日志监控 - 任务ID: ' + (currentTaskId || 'N/A') + 
                                ' | 状态: <span style="color:' + getStatusColor(status) + '">' + status + '</span>' +
                                ' | PID: ' + pid + '</div><hr style="border-color: #333;">' +
                                outputLines.join('\n')
                            );
                            
                            // 滚动到底部
                            var container = $('.ssh-terminal-container');
                            container.scrollTop(container[0].scrollHeight);
                            
                            // 更新状态
                            $('#ssh-status').html(isRunning ? '<span style="color: green;">运行中</span>' : '<span style="color: orange;">已停止</span>');
                            $('#ssh-current-pid').text(pid);
                            $('#ssh-last-update').text(new Date().toLocaleTimeString());
                            
                            // 如果进程停止，自动停止监控
                            if (!isRunning && status !== 'running') {
                                $('#ssh-status').html('<span style="color: red;">已结束</span>');
                            }
                        } else {
                            $('#ssh-terminal-output').append('\n' + response.data.message);
                        }
                    },
                    error: function() {
                        $('#ssh-terminal-output').append('\n⚠️ 监控请求失败');
                    }
                });
            }
            
            function stopSSHMonitoring() {
                if (monitoringInterval) {
                    clearInterval(monitoringInterval);
                    monitoringInterval = null;
                }
                
                $('#ssh-start-monitoring').prop('disabled', false);
                $('#ssh-stop-monitoring').prop('disabled', true);
                $('#ssh-status').text('已停止');
                
                $('#ssh-terminal-output').append('\n\n🔴 监控已停止');
            }
            
            function executeSSHCommand(command) {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'pms_execute_ssh_like_command',
                        nonce: '<?php echo wp_create_nonce('pms_automation_nonce'); ?>',
                        command: command,
                        task_id: currentTaskId
                    },
                    success: function(response) {
                        if (response.success) {
                            var output = response.data.output;
                            var error = response.data.error;
                            
                            $('#ssh-terminal-output').append(
                                '\n\n<span style="color: #0af;">$ ' + command + '</span>\n' +
                                output + (error ? '\n<span style="color: red;">' + error + '</span>' : '')
                            );
                            
                            // 滚动到底部
                            var container = $('.ssh-terminal-container');
                            container.scrollTop(container[0].scrollHeight);
                        }
                    },
                    error: function() {
                        $('#ssh-terminal-output').append('\n⚠️ 命令执行失败');
                    }
                });
            }
            
            function getStatusColor(status) {
                switch(status) {
                    case 'running': return '#4CAF50';
                    case 'completed': return '#2196F3';
                    case 'failed': return '#F44336';
                    case 'queued': return '#FF9800';
                    default: return '#9E9E9E';
                }
            }
            
            // 任务选择变化时，自动填充用户选择
            $('#ssh-task-select').on('change', function() {
                var selectedOption = $(this).find('option:selected');
                var userId = selectedOption.data('user-id');
                if (userId) {
                    $('#ssh-user-select').val(userId);
                }
            });
            
            // PMS订阅页面自动化设置
            window.pmsAdminSetupAutomation = function(userId, subscriptionId) {
                if (confirm('确定要为此订阅开始自动化配置吗？')) {
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'pms_setup_subscription_automation',
                            user_id: userId,
                            subscription_id: subscriptionId,
                            nonce: '<?php echo wp_create_nonce('pms_automation_nonce'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                if (response.data.has_domain) {
                                    alert('此用户已有域名: ' + response.data.domain);
                                } else {
                                    window.open('<?php echo admin_url('admin.php?page=pms-automation-admin-settings'); ?>', '_blank');
                                }
                            } else {
                                alert('操作失败: ' + response.data.message);
                            }
                        }
                    });
                }
            };
        });
        </script>
        
        <style>
        .status-running { color: #4CAF50; font-weight: bold; }
        .status-queued { color: #FF9800; font-weight: bold; }
        .status-failed { color: #F44336; font-weight: bold; }
        .status-completed { color: #2196F3; font-weight: bold; }
        .status-pending { color: #9E9E9E; font-weight: bold; }
        .log-files { max-height: 400px; overflow-y: auto; }
        .log-file { margin-bottom: 20px; padding: 10px; border: 1px solid #ddd; }
        .log-content { background: #f5f5f5; padding: 10px; font-family: monospace; font-size: 12px; }
        
        .ssh-select {
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 3px;
            min-width: 200px;
        }

        .ssh-terminal-container {
            scrollbar-width: thin;
            scrollbar-color: #666 #222;
        }

        .ssh-terminal-container::-webkit-scrollbar {
            width: 8px;
        }

        .ssh-terminal-container::-webkit-scrollbar-track {
            background: #222;
        }

        .ssh-terminal-container::-webkit-scrollbar-thumb {
            background: #666;
            border-radius: 4px;
        }

        .ssh-terminal-container::-webkit-scrollbar-thumb:hover {
            background: #888;
        }

        .ssh-quick-command {
            font-size: 12px;
            padding: 4px 8px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        </style>
        <?php
    }
    
    /**
     * 计算预计等待时间
     */
    private function calculate_estimated_wait_time($queue_position) {
        // 平均任务执行时间（分钟）
        $avg_task_time = 15;
        
        // 预计等待时间（秒）
        return ($queue_position - 1) * $avg_task_time * 60;
    }
    
    /**
     * 渲染用户状态表格
     */
    private function render_user_status_table() {
        global $wpdb;
        
        // 使用用户状态表获取数据
        $user_status_table = $wpdb->prefix . 'pms_user_automation_status';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$user_status_table'");
        
        $users = array();
        if ($table_exists) {
            $users = $wpdb->get_results(
                "SELECT s.user_id, s.domain, s.status, s.progress, s.last_updated,
                        u.user_login, u.user_email,
                        um.meta_value as has_paid
                 FROM $user_status_table s
                 LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID
                 LEFT JOIN {$wpdb->usermeta} um ON s.user_id = um.user_id AND um.meta_key = '_pms_has_paid'
                 ORDER BY s.last_updated DESC
                 LIMIT 50"
            );
        } else {
            // 回退到旧方法
            $users = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT u.ID, u.user_login, u.user_email, 
                            m1.meta_value as automation_status,
                            m2.meta_value as domain,
                            m3.meta_value as progress,
                            m4.meta_value as has_paid
                     FROM {$wpdb->users} u
                     LEFT JOIN {$wpdb->usermeta} m1 ON u.ID = m1.user_id AND m1.meta_key = %s
                     LEFT JOIN {$wpdb->usermeta} m2 ON u.ID = m2.user_id AND m2.meta_key = %s
                     LEFT JOIN {$wpdb->usermeta} m3 ON u.ID = m3.user_id AND m3.meta_key = %s
                     LEFT JOIN {$wpdb->usermeta} m4 ON u.ID = m4.user_id AND m4.meta_key = %s
                     WHERE (m1.meta_value IS NOT NULL OR m4.meta_value = 'yes')
                     ORDER BY u.ID DESC
                     LIMIT 50",
                    '_pms_automation_status',
                    '_pms_automation_domain',
                    '_pms_automation_progress',
                    '_pms_has_paid'
                )
            );
        }
        
        if (empty($users)) {
            echo '<p>没有找到进行中的自动化配置。</p>';
            return;
        }
        
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead>
                <tr>
                    <th>用户ID</th>
                    <th>用户名</th>
                    <th>邮箱</th>
                    <th>已付费</th>
                    <th>状态</th>
                    <th>域名</th>
                    <th>进度</th>
                    <th>最后更新</th>
                    <th>操作</th>
                </tr>
              </thead>';
        echo '<tbody>';
        
        foreach ($users as $user) {
            $status_class = '';
            switch (isset($user->automation_status) ? $user->automation_status : '') {
                case 'running':
                    $status_class = 'status-running';
                    break;
                case 'completed':
                    $status_class = 'status-completed';
                    break;
                case 'failed':
                    $status_class = 'status-failed';
                    break;
                case 'queued':
                    $status_class = 'status-queued';
                    break;
                case 'domain_required':
                    $status_class = 'status-pending';
                    break;
                default:
                    $status_class = 'status-pending';
            }
            
            $has_paid = isset($user->has_paid) && $user->has_paid === 'yes' ? '✅ 是' : '❌ 否';
            $status_text = $this->get_status_text(isset($user->automation_status) ? $user->automation_status : 'pending');
            $last_updated = isset($user->last_updated) ? $user->last_updated : '';
            
            echo '<tr>';
            echo '<td>' . (isset($user->user_id) ? $user->user_id : (isset($user->ID) ? $user->ID : '')) . '</td>';
            echo '<td>' . esc_html(isset($user->user_login) ? $user->user_login : '') . '</td>';
            echo '<td>' . esc_html(isset($user->user_email) ? $user->user_email : '') . '</td>';
            echo '<td>' . $has_paid . '</td>';
            echo '<td><span class="' . $status_class . '">' . $status_text . '</span></td>';
            echo '<td>' . (isset($user->domain) && $user->domain ? esc_html($user->domain) : '-') . '</td>';
            echo '<td>' . (isset($user->progress) ? $user->progress . '%' : '0%') . '</td>';
            echo '<td>' . ($last_updated ?: '-') . '</td>';
            echo '<td>
                    <a href="' . admin_url('admin.php?page=pms-automation-admin-settings&action=reset_user_automation&user_id=' . (isset($user->user_id) ? $user->user_id : (isset($user->ID) ? $user->ID : ''))) . '" class="button button-small" onclick="return confirm(\'确定要重置此用户的自动化状态吗？\')">重置</a>
                    <a href="' . admin_url('user-edit.php?user_id=' . (isset($user->user_id) ? $user->user_id : (isset($user->ID) ? $user->ID : ''))) . '" class="button button-small" target="_blank">编辑</a>
                  </td>';
            echo '</tr>';
        }
        
        echo '</tbody>';
        echo '</table>';
    }
    
    /**
     * 获取状态文本
     */
    private function get_status_text($status) {
        $texts = array(
            'pending' => __('等待开始', 'pms-secupay-automation-admin'),
            'domain_required' => __('需要域名', 'pms-secupay-automation-admin'),
            'queued' => __('队列中', 'pms-secupay-automation-admin'),
            'running' => __('运行中', 'pms-secupay-automation-admin'),
            'completed' => __('已完成', 'pms-secupay-automation-admin'),
            'failed' => __('失败', 'pms-secupay-automation-admin'),
            'cancelled' => __('已取消', 'pms-secupay-automation-admin'),
            'unknown' => __('未知', 'pms-secupay-automation-admin')
        );
        
        return isset($texts[$status]) ? $texts[$status] : $status;
    }
    
    /**
     * 渲染所有用户表格
     */
    private function render_all_users_table() {
        global $wpdb;
        
        $users = $wpdb->get_results(
            "SELECT u.ID, u.user_login, u.user_email, u.user_registered,
                    MAX(CASE WHEN um.meta_key = '_pms_needs_automation' THEN um.meta_value END) as needs_automation,
                    MAX(CASE WHEN um.meta_key = '_pms_automation_status' THEN um.meta_value END) as automation_status,
                    MAX(CASE WHEN um.meta_key = '_pms_automation_domain' THEN um.meta_value END) as domain,
                    MAX(CASE WHEN um.meta_key = '_pms_has_paid' THEN um.meta_value END) as has_paid
             FROM {$wpdb->users} u
             LEFT JOIN {$wpdb->usermeta} um ON u.ID = um.user_id AND um.meta_key IN ('_pms_needs_automation', '_pms_automation_status', '_pms_automation_domain', '_pms_has_paid')
             GROUP BY u.ID, u.user_login, u.user_email, u.user_registered
             ORDER BY u.ID DESC
             LIMIT 50"
        );
        
        if (empty($users)) {
            echo '<p>没有找到用户。</p>';
            return;
        }
        
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead>
                <tr>
                    <th>用户ID</th>
                    <th>用户名</th>
                    <th>邮箱</th>
                    <th>注册时间</th>
                    <th>已付费</th>
                    <th>域名</th>
                    <th>自动化状态</th>
                    <th>操作</th>
                </tr>
              </thead>';
        echo '<tbody>';
        
        foreach ($users as $user) {
            $automation_status = isset($user->automation_status) ? $user->automation_status : '未标记';
            $has_paid = isset($user->has_paid) && $user->has_paid === 'yes' ? '✅ 是' : '❌ 否';
            $domain = isset($user->domain) && $user->domain ? $user->domain : '-';
            
            if (isset($user->needs_automation) && $user->needs_automation === '1') {
                $status_text = $this->get_status_text($automation_status);
                $status_class = 'status-' . $automation_status;
            } else {
                $status_text = '未标记';
                $status_class = '';
            }
            
            echo '<tr>';
            echo '<td>' . $user->ID . '</td>';
            echo '<td>' . esc_html($user->user_login) . '</td>';
            echo '<td>' . esc_html($user->user_email) . '</td>';
            echo '<td>' . $user->user_registered . '</td>';
            echo '<td>' . $has_paid . '</td>';
            echo '<td>' . esc_html($domain) . '</td>';
            echo '<td><span class="' . $status_class . '">' . $status_text . '</span></td>';
            echo '<td>';
            if ($has_paid !== '✅ 是') {
                echo '<a href="' . admin_url('admin.php?page=pms-automation-admin-settings&action=mark_as_paid&user_id=' . $user->ID) . '" class="button button-small button-primary">标记为已付费</a> ';
            }
            if (!isset($user->needs_automation) || $user->needs_automation !== '1') {
                echo '<a href="' . admin_url('admin.php?page=pms-automation-admin-settings&action=mark_user_automation&user_id=' . $user->ID) . '" class="button button-small">标记自动化</a>';
            } else {
                echo '<a href="' . admin_url('admin.php?page=pms-automation-admin-settings&action=reset_user_automation&user_id=' . $user->ID) . '" class="button button-small" onclick="return confirm(\'确定要重置此用户的自动化状态吗？\')">重置</a>';
            }
            echo '</td>';
            echo '</tr>';
        }
        
        echo '</tbody>';
        echo '</table>';
    }
    
    /**
     * 渲染系统日志
     */
    private function render_system_logs() {
        // 首先尝试核心插件的日志目录
        $core_log_dir = defined('PMS_AUTOMATION_CORE_PATH') ? PMS_AUTOMATION_CORE_PATH . 'logs' : '';
        $admin_log_dir = PMS_AUTOMATION_ADMIN_PATH . 'logs';
        
        // 如果核心日志目录不存在，使用管理员日志目录
        $log_dir = file_exists($core_log_dir) ? $core_log_dir : $admin_log_dir;
        
        // 如果目录不存在，创建它
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
        }
        
        $log_files = glob($log_dir . '/*.log');
        
        if (empty($log_files)) {
            echo '<p>没有找到日志文件。</p>';
            return;
        }
        
        echo '<div class="log-files">';
        
        // 按修改时间排序，最新的在前
        usort($log_files, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        
        // 只显示最近5个日志文件
        $log_files = array_slice($log_files, 0, 5);
        
        foreach ($log_files as $log_file) {
            $filename = basename($log_file);
            $file_size = filesize($log_file);
            $file_date = date('Y-m-d H:i:s', filemtime($log_file));
            
            echo '<div class="log-file">';
            echo '<h4>' . esc_html($filename) . ' (' . $this->format_bytes($file_size) . ', ' . $file_date . ')</h4>';
            
            // 只显示最后100行
            $lines = file($log_file, FILE_IGNORE_NEW_LINES);
            if ($lines) {
                $lines = array_slice($lines, -100); // 最后100行
                echo '<div class="log-content">';
                foreach ($lines as $line) {
                    echo esc_html($line) . '<br>';
                }
                echo '</div>';
            }
            
            echo '</div>';
        }
        
        echo '</div>';
    }
    
    /**
     * 格式化字节大小
     */
    private function format_bytes($bytes, $precision = 2) {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}