<?php
/**
 * Plugin Name: PMS Secupay 支付成功自动化配置 - 核心功能
 * Plugin URI: https://ai47.us/
 * Description: 在Secupay支付成功后自动执行WordPress自动化配置脚本，提供秒级支付监听，支持并行处理和队列管理。仅管理员和已付费用户可见。
 * Version: 1.7.0 (支持WordPress静默安装)
 * Author: AI47 Support
 * Author URI: https://ai47.us/
 * Text Domain: pms-secupay-automation-core
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * License: GPL v2 or later
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

// 定义插件常量
define('PMS_AUTOMATION_CORE_VERSION', '1.7.0');
define('PMS_AUTOMATION_CORE_PATH', plugin_dir_path(__FILE__));
define('PMS_AUTOMATION_CORE_URL', plugin_dir_url(__FILE__));
define('PMS_AUTOMATION_CORE_FILE', __FILE__);

// **修正：使用同一路径，因为文件在同一目录**
// 宿主机和Docker容器内使用相同的脚本路径（因为插件目录被挂载）
define('PMS_AUTOMATION_SCRIPT_PATH', PMS_AUTOMATION_CORE_PATH . 'debian12_wordpress_Auto_deploy_ssl_redis_2.sh');
define('PMS_HOST_SCRIPT_PATH', '/www/docker/wordpress/debian12_wordpress_Auto_deploy_ssl_redis_2.sh');
// 容器内私钥路径
define('PMS_SSH_KEY_PATH', '/var/www/html/wp-content/plugins/id_rsa_wp');
// 宿主机 IP
define('HOST_MACHINE_IP', '172.27.68.1');
define('PMS_AUTOMATION_USE_SUDO', false);
define('PMS_AUTOMATION_SUDO_USER', 'root');
define('PMS_AUTOMATION_MAX_CONCURRENT', 8);
define('PMS_AUTOMATION_QUEUE_ENABLED', true);
define('PMS_AUTOMATION_ADMIN_ONLY_DEBUG', true);
define('PMS_AUTOMATION_PAYMENT_CHECK_MAX_ATTEMPTS', 60);
define('PMS_AUTOMATION_PAYMENT_CHECK_INTERVAL', 5);

// ==================== 数据库表创建 ====================

/**
 * 创建队列数据库表
 */

function pms_automation_core_create_queue_table() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'pms_automation_queue';
    $charset_collate = $wpdb->get_charset_collate();
    
    // 更新SQL，添加WordPress安装参数字段
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT(20) UNSIGNED NOT NULL,
        domain VARCHAR(255) NOT NULL,
        wp_language VARCHAR(20) DEFAULT 'zh_CN',
        wp_username VARCHAR(60) DEFAULT 'admin',
        wp_password VARCHAR(255) NOT NULL,
        wp_email VARCHAR(100) NOT NULL,
        site_title VARCHAR(255) DEFAULT 'My Website',
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
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    
    // 兼容性修复：如果表已存在但缺少字段，手动添加
    $columns_to_check = array('wp_language', 'wp_username', 'wp_email', 'site_title');
    foreach ($columns_to_check as $column) {
        $column_exists = $wpdb->get_results($wpdb->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = %s AND COLUMN_NAME = %s", $table_name, $column));
        if (empty($column_exists)) {
            $data_type = '';
            $default_value = '';
            
            switch($column) {
                case 'wp_language':
                    $data_type = "VARCHAR(20) DEFAULT 'zh_CN'";
                    break;
                case 'wp_username':
                    $data_type = "VARCHAR(60) DEFAULT 'admin'";
                    break;
                case 'wp_email':
                    $data_type = "VARCHAR(100) NOT NULL DEFAULT ''";
                    break;
                case 'site_title':
                    $data_type = "VARCHAR(255) DEFAULT 'My Website'";
                    break;
            }
            
            if ($data_type) {
                $wpdb->query("ALTER TABLE $table_name ADD COLUMN `$column` $data_type");
            }
        }
    }
    
    // 创建进程监控表
    $process_table_name = $wpdb->prefix . 'pms_automation_processes';
    $process_sql = "CREATE TABLE IF NOT EXISTS $process_table_name (
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
    
    dbDelta($process_sql);
    
    // 创建用户自动化状态表（用于PMS订阅页面显示）
    $user_status_table_name = $wpdb->prefix . 'pms_user_automation_status';
    $user_status_sql = "CREATE TABLE IF NOT EXISTS $user_status_table_name (
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
    
    dbDelta($user_status_sql);
}

register_activation_hook(__FILE__, 'pms_automation_core_activation_with_tables');

function pms_automation_core_activation_with_tables() {
    global $wpdb;
    
    // 1. 创建队列数据库表
    pms_automation_core_create_queue_table();
    
    // 2. 创建目录结构
    $dirs = array(
        PMS_AUTOMATION_CORE_PATH . 'scripts',
        PMS_AUTOMATION_CORE_PATH . 'assets/css',
        PMS_AUTOMATION_CORE_PATH . 'assets/js',
        PMS_AUTOMATION_CORE_PATH . 'logs',
        PMS_AUTOMATION_CORE_PATH . 'tmp',
        PMS_AUTOMATION_CORE_PATH . 'cron'
    );
    
    foreach ($dirs as $dir) {
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
            @chmod($dir, 0755);
        }
    }
    
    // 3. 创建保护文件
    $protect_files = array(
        PMS_AUTOMATION_CORE_PATH . 'scripts/.htaccess',
        PMS_AUTOMATION_CORE_PATH . 'logs/.htaccess',
        PMS_AUTOMATION_CORE_PATH . 'tmp/.htaccess',
        PMS_AUTOMATION_CORE_PATH . 'cron/.htaccess',
        PMS_AUTOMATION_CORE_PATH . 'scripts/index.html',
        PMS_AUTOMATION_CORE_PATH . 'logs/index.html',
        PMS_AUTOMATION_CORE_PATH . 'tmp/index.html',
        PMS_AUTOMATION_CORE_PATH . 'cron/index.html'
    );
    
    foreach ($protect_files as $file) {
        if (!file_exists($file)) {
            if (strpos($file, '.htaccess') !== false) {
                file_put_contents($file, "Order Deny,Allow\nDeny from all\n");
            } else {
                file_put_contents($file, '<!DOCTYPE html><html><head><title>403 Forbidden</title></head><body><h1>Access Denied</h1></body></html>');
            }
        }
    }
    
    // 4. 创建系统Cron脚本 - 修复版（支持多种环境）
    pms_automation_core_create_system_cron_scripts();  // 改为普通函数调用
    
    // 5. 检查并设置WordPress Cron事件
    pms_automation_core_setup_wordpress_cron_events();  // 改为普通函数调用
    
    // 6. 检查现有队列状态
    pms_automation_core_check_existing_queue_status();  // 改为普通函数调用
    
    // 7. 显示安装指导信息
    pms_automation_core_display_installation_instructions();  // 改为普通函数调用
}

/**
 * 创建系统Cron脚本
 */
function pms_automation_core_create_system_cron_scripts() {
    // 获取当前WordPress网站URL（容器内访问地址）
    $wp_url = get_site_url();
    $wp_admin_ajax = admin_url('admin-ajax.php');
    
    // 脚本1：适用于Docker环境的Cron脚本
    $cron_docker = <<<'EOD'
#!/bin/bash
# PMS自动化插件 - Docker环境Cron脚本
# 自动触发WordPress Cron和队列处理
# 生成时间：$(date)

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# 日志文件
LOG_FILE="/tmp/pms_cron_$(date +%Y%m%d).log"
echo "========== PMS Cron 执行开始 $(date) ==========" >> "$LOG_FILE"

# WordPress URL（容器内部地址）
WP_URL="http://localhost"
CRON_URL="${WP_URL}/wp-cron.php"

# 方法1：通过curl触发WordPress Cron
echo "触发WordPress Cron..." >> "$LOG_FILE"
CURL_RESULT=$(curl -s -o /dev/null -w "%{http_code}" --max-time 30 "${CRON_URL}" 2>/dev/null)
if [ "$CURL_RESULT" = "200" ] || [ "$CURL_RESULT" = "302" ]; then
    echo -e "${GREEN}✅ WordPress Cron触发成功 (HTTP $CURL_RESULT)${NC}" >> "$LOG_FILE"
else
    echo -e "${YELLOW}⚠️  WordPress Cron触发异常 (HTTP $CURL_RESULT)${NC}" >> "$LOG_FILE"
    # 备选方法：使用wp-cli
    if command -v wp &> /dev/null; then
        echo "使用wp-cli触发Cron..." >> "$LOG_FILE"
        wp cron event run --due-now --quiet 2>&1 >> "$LOG_FILE"
    fi
fi

# 方法2：直接触发PMS自动化队列处理
echo "触发PMS自动化队列处理..." >> "$LOG_FILE"
AJAX_URL="${WP_URL}/wp-admin/admin-ajax.php"
QUEUE_RESULT=$(curl -s -o /dev/null -w "%{http_code}" --max-time 30 \
    -d "action=pms_process_automation_queue&_ajax_nonce=auto" \
    -X POST "${AJAX_URL}" 2>/dev/null)

if [ "$QUEUE_RESULT" = "200" ] || [ "$QUEUE_RESULT" = "302" ]; then
    echo -e "${GREEN}✅ 队列处理触发成功 (HTTP $QUEUE_RESULT)${NC}" >> "$LOG_FILE"
else
    echo -e "${YELLOW}⚠️  队列处理触发失败 (HTTP $QUEUE_RESULT)${NC}" >> "$LOG_FILE"
fi

# 检查并重启WordPress Cron（如果未运行）
if ! pgrep -f "wp-cron.php" > /dev/null; then
    echo "重启WordPress Cron守护进程..." >> "$LOG_FILE"
    # 启动后台Cron进程
    php -f "${WP_URL}/wp-cron.php" > /dev/null 2>&1 &
fi

echo "========== PMS Cron 执行完成 $(date) ==========" >> "$LOG_FILE"
echo "日志文件: $LOG_FILE"

# 清理旧日志文件（保留最近7天）
find /tmp -name "pms_cron_*.log" -mtime +7 -delete 2>/dev/null
EOD;

    // 脚本2：适用于宿主机（宝塔面板）的Cron脚本
    $cron_host = <<<'EOD'
#!/bin/bash
# PMS自动化插件 - 宿主机Cron脚本（用于宝塔面板）
# 通过Docker容器执行Cron任务
# 生成时间：$(date)

# 容器名称（根据实际情况修改）
CONTAINER_NAME="wordpress"  # 修改为你的WordPress容器名称

# 检查Docker容器是否运行
if ! docker ps --format '{{.Names}}' | grep -q "^${CONTAINER_NAME}$"; then
    echo "错误: Docker容器 '${CONTAINER_NAME}' 未运行"
    exit 1
fi

# 日志文件
LOG_DIR="/www/docker/wordpress/cron_logs"
mkdir -p "$LOG_DIR"
LOG_FILE="${LOG_DIR}/pms_cron_$(date +%Y%m%d_%H%M%S).log"

echo "========== PMS 宿主机Cron 执行开始 $(date) ==========" > "$LOG_FILE"

# 在容器内执行Cron脚本
if [ -f "/www/docker/wordpress/pms_cron_docker.sh" ]; then
    echo "执行容器内Cron脚本..." >> "$LOG_FILE"
    docker exec -i "$CONTAINER_NAME" bash -c "bash /var/www/html/wp-content/plugins/pms-cron-docker.sh" 2>&1 >> "$LOG_FILE"
else
    # 直接执行WordPress Cron
    echo "触发WordPress Cron..." >> "$LOG_FILE"
    docker exec -i "$CONTAINER_NAME" curl -s --max-time 30 "http://localhost/wp-cron.php" >> "$LOG_FILE" 2>&1
    
    # 触发队列处理
    echo "触发队列处理..." >> "$LOG_FILE"
    docker exec -i "$CONTAINER_name" curl -s --max-time 30 \
        -d "action=pms_process_automation_queue&_ajax_nonce=auto" \
        -X POST "http://localhost/wp-admin/admin-ajax.php" >> "$LOG_FILE" 2>&1
fi

# 检查队列状态
echo "检查队列状态..." >> "$LOG_FILE"
docker exec -i "$CONTAINER_NAME" wp option get siteurl >> "$LOG_FILE" 2>&1
docker exec -i "$CONTAINER_NAME" wp cron event list >> "$LOG_FILE" 2>&1

echo "========== PMS 宿主机Cron 执行完成 $(date) ==========" >> "$LOG_FILE"
echo "执行完成，日志文件: $LOG_FILE"

# 清理旧日志（保留最近3天）
find "$LOG_DIR" -name "*.log" -mtime +3 -delete 2>/dev/null
EOD;

    // 脚本3：简单的通用Cron脚本
    $cron_simple = <<<'EOD'
#!/bin/bash
# PMS自动化插件 - 简单Cron脚本
# 最小依赖，仅触发核心功能

WP_URL="http://localhost"
LOG_FILE="/tmp/pms_simple_cron.log"

echo "$(date): 开始执行" >> "$LOG_FILE"

# 触发WordPress Cron
curl -s --max-time 30 "${WP_URL}/wp-cron.php?doing_wp_cron" > /dev/null 2>&1

# 触发PMS队列处理（使用GET方式简化）
curl -s --max-time 30 "${WP_URL}/?pms_trigger_queue=1&security_key=auto123" > /dev/null 2>&1

echo "$(date): 执行完成" >> "$LOG_FILE"

# 限制日志大小
tail -n 100 "$LOG_FILE" > "${LOG_FILE}.tmp" && mv "${LOG_FILE}.tmp" "$LOG_FILE"
EOD;

    // 创建Cron脚本文件
    $cron_files = array(
        'docker' => array(
            'path' => PMS_AUTOMATION_CORE_PATH . 'cron/pms_cron_docker.sh',
            'content' => $cron_docker
        ),
        'host' => array(
            'path' => '/www/docker/wordpress/pms_cron_host.sh',
            'content' => $cron_host
        ),
        'simple' => array(
            'path' => PMS_AUTOMATION_CORE_PATH . 'cron/pms_cron_simple.sh',
            'content' => $cron_simple
        )
    );
    
    foreach ($cron_files as $type => $file_info) {
        $file_path = $file_info['path'];
        $file_dir = dirname($file_path);
        
        // 确保目录存在
        if (!file_exists($file_dir)) {
            @mkdir($file_dir, 0755, true);
        }
        
        // 创建脚本文件
        if (file_put_contents($file_path, $file_info['content'])) {
            @chmod($file_path, 0755);
            error_log("[PMS Automation] 创建Cron脚本: {$file_path}");
        } else {
            error_log("[PMS Automation] 警告: 无法创建Cron脚本: {$file_path}");
        }
    }
    
    // 在插件目录创建符号链接，方便容器内访问
    $docker_cron_link = PMS_AUTOMATION_CORE_PATH . 'cron-docker.sh';
    if (!file_exists($docker_cron_link)) {
        @symlink(PMS_AUTOMATION_CORE_PATH . 'cron/pms_cron_docker.sh', $docker_cron_link);
    }
}

/**
 * 设置WordPress Cron事件
 */
function pms_automation_core_setup_wordpress_cron_events() {
    // 确保自定义Cron间隔已添加
    add_filter('cron_schedules', function($schedules) {
        $schedules['every_minute'] = array(
            'interval' => 60,
            'display' => __('每分钟')
        );
        $schedules['every_30_seconds'] = array(
            'interval' => 30,
            'display' => __('每30秒')
        );
        $schedules['every_5_minutes'] = array(
            'interval' => 300,
            'display' => __('每5分钟')
        );
        return $schedules;
    });
    
    // 安排核心Cron任务
    $cron_events = array(
        'pms_automation_cron' => 'every_minute',
        'pms_automation_queue_cron' => 'every_30_seconds',
        'pms_automation_check_missing_payments' => 'every_5_minutes'
    );
    
    foreach ($cron_events as $hook => $schedule) {
        if (!wp_next_scheduled($hook)) {
            wp_schedule_event(time(), $schedule, $hook);
            error_log("[PMS Automation] 安排Cron任务: {$hook} - {$schedule}");
        }
    }
}

/**
 * 检查现有队列状态
 */
function pms_automation_core_check_existing_queue_status() {
    global $wpdb;
    $queue_table = $wpdb->prefix . 'pms_automation_queue';
    
    // 检查表是否存在
    if ($wpdb->get_var("SHOW TABLES LIKE '{$queue_table}'") == $queue_table) {
        $pending_count = $wpdb->get_var("SELECT COUNT(*) FROM {$queue_table} WHERE status IN ('pending', 'queued')");
        $running_count = $wpdb->get_var("SELECT COUNT(*) FROM {$queue_table} WHERE status = 'running'");
        
        if ($pending_count > 0 || $running_count > 0) {
            error_log("[PMS Automation] 发现待处理任务: 排队中={$pending_count}, 运行中={$running_count}");
            
            // 立即触发一次队列处理
            if (class_exists('PMS_Secupay_Automation_Core')) {
                $instance = PMS_Secupay_Automation_Core::get_instance();
                if (method_exists($instance, 'process_automation_queue')) {
                    $instance->process_automation_queue();
                    error_log("[PMS Automation] 已触发队列处理");
                }
            }
        }
    }
}

/**
 * 显示安装指导信息
 */
function pms_automation_core_display_installation_instructions() {
    add_action('admin_notices', function() {
        if (current_user_can('manage_options')) {
            $cron_docker_path = PMS_AUTOMATION_CORE_PATH . 'cron/pms_cron_docker.sh';
            $cron_host_path = '/www/docker/wordpress/pms_cron_host.sh';
            $cron_simple_path = PMS_AUTOMATION_CORE_PATH . 'cron/pms_cron_simple.sh';
            
            $docker_container = shell_exec('hostname') ?: 'wordpress-container';
            $docker_container = trim($docker_container);
            
            echo '<div class="notice notice-success is-dismissible">';
            echo '<h3>🎉 PMS自动化插件激活成功！</h3>';
            echo '<p><strong>重要：</strong>请设置系统Cron任务以确保队列自动执行。</p>';
            
            echo '<div style="background:#f8f9fa;padding:15px;border-left:4px solid #2196F3;margin:15px 0;">';
            echo '<h4>📋 安装步骤：</h4>';
            
            echo '<h5>选项A：在宝塔面板中添加计划任务（推荐）</h5>';
            echo '<ol>';
            echo '<li>登录宝塔面板 → 计划任务</li>';
            echo '<li>添加任务：<br>';
            echo '任务类型：<strong>Shell脚本</strong><br>';
            echo '执行周期：<strong>每分钟</strong><br>';
            echo '脚本内容：<pre style="background:#e9ecef;padding:10px;border-radius:4px;"><code>bash ' . esc_html($cron_host_path) . '</code></pre></li>';
            echo '<li>保存并立即执行一次测试</li>';
            echo '</ol>';
            
            echo '<h5>选项B：在Docker容器内设置Cron</h5>';
            echo '<ol>';
            echo '<li>进入Docker容器：<br>';
            echo '<pre style="background:#e9ecef;padding:10px;border-radius:4px;"><code>docker exec -it ' . esc_html($docker_container) . ' bash</code></pre></li>';
            echo '<li>编辑Crontab：<br>';
            echo '<pre style="background:#e9ecef;padding:10px;border-radius:4px;"><code>crontab -e</code></pre></li>';
            echo '<li>添加以下行：<br>';
            echo '<pre style="background:#e9ecef;padding:10px;border-radius:4px;"><code>* * * * * bash ' . esc_html($cron_docker_path) . ' > /dev/null 2>&1</code></pre></li>';
            echo '</ol>';
            
            echo '<h5>选项C：手动测试执行</h5>';
            echo '<p>立即测试Cron脚本：</p>';
            echo '<pre style="background:#e9ecef;padding:10px;border-radius:4px;"><code>bash ' . esc_html($cron_simple_path) . '</code></pre>';
            
            echo '<h5>📊 当前状态检查：</h5>';
            echo '<p><a href="' . admin_url('admin.php?page=pms-automation-monitor') . '" class="button button-primary">前往任务监控面板</a></p>';
            
            echo '</div>';
            
            echo '<p><small>提示：Cron脚本已生成在以下位置：<br>';
            echo '1. Docker脚本: ' . esc_html($cron_docker_path) . '<br>';
            echo '2. 宿主机脚本: ' . esc_html($cron_host_path) . '<br>';
            echo '3. 简单脚本: ' . esc_html($cron_simple_path) . '</small></p>';
            
            echo '</div>';
        }
    });
}

// ==================== 通用函数 ====================

/**
 * 检查宿主机脚本是否存在和权限
 */
function pms_automation_core_check_host_script() {
    $script_path = PMS_AUTOMATION_SCRIPT_PATH;
    
    if (!file_exists($script_path)) {
        error_log("[PMS Automation Core] 警告: 脚本路径不存在: " . $script_path . " (请确认脚本文件存在)");
        
        // 尝试查找其他可能的位置
        $possible_paths = array(
            PMS_AUTOMATION_CORE_PATH . 'debian12_wordpress_Auto_deploy_ssl_redis_2.sh',
            '/www/docker/wordpress/debian12_wordpress_Auto_deploy_ssl_redis_2.sh',
            '/var/www/html/wp-content/plugins/debian12_wordpress_Auto_deploy_ssl_redis_2.sh',
            dirname(PMS_AUTOMATION_CORE_PATH) . '/debian12_wordpress_Auto_deploy_ssl_redis_2.sh'
        );
        
        foreach ($possible_paths as $path) {
            if (file_exists($path)) {
                define('PMS_AUTOMATION_SCRIPT_PATH_ALT', $path);
                error_log("[PMS Automation Core] 找到备用脚本路径: " . $path);
                return true;
            }
        }
        
        return false;
    }
    
    if (!is_executable($script_path)) {
        // 尝试修复权限 (需要容器内用户对文件有写权限，通常是 www-data)
        @chmod($script_path, 0755);
        if (!is_executable($script_path)) {
            error_log("[PMS Automation Core] 警告: 脚本存在但不可执行 (0755 失败): " . $script_path);
            return false;
        }
        error_log("[PMS Automation Core] 脚本权限已修复为 0755。");
    }
    
    return true;
}

// ==================== PMS订阅页面集成 ====================

/**
 * 在PMS订阅页面添加自动化配置列
 */
add_filter('pms_account_subscriptions_table_columns', 'pms_automation_add_subscription_column', 20);
function pms_automation_add_subscription_column($columns) {
    $columns['automation'] = __('自动化配置', 'pms-secupay-automation-core');
    return $columns;
}

add_filter('pms_account_subscriptions_table_column_automation', 'pms_automation_render_subscription_column', 10, 2);
function pms_automation_render_subscription_column($value, $subscription) {
    $user_id = $subscription->user_id;
    $domain = get_user_meta($user_id, '_pms_automation_domain', true);
    $status = get_user_meta($user_id, '_pms_automation_status', true);
    
    if (!$domain && $status !== 'completed') {
        return '<button class="button button-small pms-setup-automation" data-user-id="' . $user_id . '" data-subscription-id="' . $subscription->id . '">开始配置</button>';
    } elseif ($status === 'running') {
        $progress = get_user_meta($user_id, '_pms_automation_progress', true) ?: 0;
        return '<div class="automation-status-running">
                    <span>部署中: ' . $progress . '%</span>
                    <div class="progress-bar"><div style="width: ' . $progress . '%"></div></div>
                </div>';
    } elseif ($status === 'completed' && $domain) {
        return '<div class="automation-status-completed">
                    <span class="dashicons dashicons-yes-alt"></span>
                    <a href="https://' . esc_attr($domain) . '" target="_blank">' . esc_html($domain) . '</a>
                </div>';
    } elseif ($status === 'failed') {
        return '<span class="automation-status-failed">配置失败</span>';
    } else {
        return '<span class="automation-status-pending">等待配置</span>';
    }
}

/**
 * 在PMS账户页面添加自动化配置区域
 */
add_action('pms_account_after_subscriptions', 'pms_automation_display_account_section', 10);
function pms_automation_display_account_section() {
    if (!is_user_logged_in()) {
        return;
    }
    
    $user_id = get_current_user_id();
    $has_paid = get_user_meta($user_id, '_pms_has_paid', true) === 'yes';
    
    if (!$has_paid) {
        return;
    }
    
    echo '<div class="pms-account-section pms-automation-section">';
    echo '<h3>Ai47网站自动化配置</h3>';
    
    // 调用核心类的渲染方法
    $core = PMS_Secupay_Automation_Core::get_instance();
    if (method_exists($core, 'render_automation_dashboard')) {
        echo $core->render_automation_dashboard();
    }
    
    echo '</div>';
}

// ==================== 初始化 ====================

add_action('init', 'pms_automation_core_init_plugin', 5);

function pms_automation_core_init_plugin() {
    // 检查父插件是否存在
    if (!class_exists('Paid_Member_Subscriptions')) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-warning"><p>PMS Secupay 自动化配置需要Paid Member Subscriptions插件激活。</p></div>';
        });
        return;
    }
    
    // 加载文本域
    load_plugin_textdomain('pms-secupay-automation-core', false, dirname(plugin_basename(__FILE__)) . '/languages');
    
    // 初始化核心功能类
    PMS_Secupay_Automation_Core::get_instance();
}

// ==================== 核心功能类 ====================

class PMS_Secupay_Automation_Core {
    
    private static $instance = null;
    private $processes = array();
    private $active_script = false;
    private $sudo_enabled = false;
    private $sudo_tested = false;
    private $debug_mode = false;
    private $queue_enabled = true;
    private $max_concurrent = 3;
    private $enable_for_all_roles = true;
    private $admin_only_debug = true;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->init_hooks();
        $this->check_sudo_availability();
        $this->debug_mode = defined('WP_DEBUG') && WP_DEBUG;
        $this->queue_enabled = defined('PMS_AUTOMATION_QUEUE_ENABLED') ? PMS_AUTOMATION_QUEUE_ENABLED : true;
        $this->max_concurrent = defined('PMS_AUTOMATION_MAX_CONCURRENT') ? PMS_AUTOMATION_MAX_CONCURRENT : 8;
        $this->enable_for_all_roles = defined('PMS_AUTOMATION_ENABLE_FOR_ALL_ROLES') ? PMS_AUTOMATION_ENABLE_FOR_ALL_ROLES : true;
        $this->admin_only_debug = defined('PMS_AUTOMATION_ADMIN_ONLY_DEBUG') ? PMS_AUTOMATION_ADMIN_ONLY_DEBUG : true;
    }
    
    private function init_hooks() {
        // 初始化
        add_action('init', array($this, 'init'), 10);
        
        // 核心：支付成功监听
        add_action('pms_payment_completed', array($this, 'handle_payment_completion'), 1, 2); 
        add_action('pms_after_payment_status_update', array($this, 'check_payment_status_update'), 1, 3); 
        
        // 定时检查
        add_action('pms_automation_check_missing_payments', array($this, 'check_missing_payments_cron'));
        
        // 核心：前端轮询支付状态的AJAX
        add_action('wp_ajax_pms_poll_payment_status', array($this, 'ajax_poll_payment_status'));
        add_action('wp_ajax_nopriv_pms_poll_payment_status', array($this, 'ajax_require_login'));

        // 常规 AJAX
        add_action('wp_ajax_pms_start_automation', array($this, 'ajax_start_automation'));
        add_action('wp_ajax_nopriv_pms_start_automation', array($this, 'ajax_require_login'));
        add_action('wp_ajax_pms_check_progress', array($this, 'ajax_check_progress'));
        add_action('wp_ajax_nopriv_pms_check_progress', array($this, 'ajax_require_login'));
        add_action('wp_ajax_pms_save_domain', array($this, 'ajax_save_domain'));
        add_action('wp_ajax_nopriv_pms_save_domain', array($this, 'ajax_require_login'));
        add_action('wp_ajax_pms_get_script_output', array($this, 'ajax_get_script_output'));
        add_action('wp_ajax_nopriv_pms_get_script_output', array($this, 'ajax_require_login'));
        add_action('wp_ajax_pms_cancel_automation', array($this, 'ajax_cancel_automation'));
        add_action('wp_ajax_nopriv_pms_cancel_automation', array($this, 'ajax_require_login'));
        add_action('wp_ajax_pms_test_script_execution', array($this, 'ajax_test_script_execution'));
        add_action('wp_ajax_nopriv_pms_test_script_execution', array($this, 'ajax_require_login'));
        add_action('wp_ajax_pms_test_sudo_access', array($this, 'ajax_test_sudo_access'));
        add_action('wp_ajax_nopriv_pms_test_sudo_access', array($this, 'ajax_require_login'));
        add_action('wp_ajax_pms_debug_system', array($this, 'ajax_debug_system'));
        add_action('wp_ajax_nopriv_pms_debug_system', array($this, 'ajax_require_login'));
        add_action('wp_ajax_pms_get_queue_status', array($this, 'ajax_get_queue_status'));
        add_action('wp_ajax_nopriv_pms_get_queue_status', array($this, 'ajax_require_login'));
        add_action('wp_ajax_pms_get_queue_position', array($this, 'ajax_get_queue_position'));
        add_action('wp_ajax_nopriv_pms_get_queue_position', array($this, 'ajax_require_login'));
        
        // 新增：域名验证AJAX
        add_action('wp_ajax_pms_validate_domain', array($this, 'ajax_validate_domain'));
        add_action('wp_ajax_nopriv_pms_validate_domain', array($this, 'ajax_require_login'));
        
        // 管理员菜单
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        
        // 后台任务钩子
        add_action('pms_automation_cron', array($this, 'check_automation_progress'));
        add_action('pms_automation_queue_cron', array($this, 'process_automation_queue'));
        
        // 注册短代码
        add_shortcode('pms_automation_dashboard', array($this, 'render_automation_dashboard'));
        
        // 添加样式和脚本 - 修复：确保脚本正确加载
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'), 20);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // 添加自定义监控钩子
        add_action('pms_automation_monitor', array($this, 'monitor_automation'), 10, 4);
        
        // 账户页面集成
        add_filter('pms_account_shortcode_content', array($this, 'add_automation_to_account'), 10, 2);
        add_action('pms_account_after_content', array($this, 'display_automation_content_directly'), 20);
        
        // 新增：管理员日志 AJAX
        add_action('wp_ajax_pms_admin_get_task_log', array($this, 'ajax_admin_get_task_log'));
        
        // 添加手动标记用户钩子（仅管理员）
        add_action('admin_init', array($this, 'handle_admin_actions'));

        // 实时日志功能
        add_action('wp_ajax_pms_get_realtime_log', array($this, 'ajax_get_realtime_log'));
        add_action('wp_ajax_nopriv_pms_get_realtime_log', array($this, 'ajax_require_login'));
        add_action('wp_ajax_pms_execute_ssh_like_command', array($this, 'ajax_execute_ssh_like_command'));
        add_action('wp_ajax_nopriv_pms_execute_ssh_like_command', array($this, 'ajax_require_login'));
        
        // PMS订阅页面钩子
        add_action('wp_ajax_pms_setup_subscription_automation', array($this, 'ajax_setup_subscription_automation'));
        add_action('wp_ajax_nopriv_pms_setup_subscription_automation', array($this, 'ajax_require_login'));
        
        // 新增：SSH诊断功能
        add_action('wp_ajax_pms_diagnose_ssh_connection', array($this, 'ajax_diagnose_ssh_connection'));
        add_action('wp_ajax_nopriv_pms_diagnose_ssh_connection', array($this, 'ajax_require_login'));
    }
    
    public function init() {
        // 1. 确保目录结构存在
        $this->create_directories();
        
        // 2. 关键修正：必须先添加自定义间隔，否则 WP 无法识别 'every_30_seconds' 等非标准间隔
        add_filter('cron_schedules', array($this, 'add_cron_interval'));
        
        // 3. 安排主自动化 Cron 任务
        if (!wp_next_scheduled('pms_automation_cron')) {
            wp_schedule_event(time(), 'every_minute', 'pms_automation_cron');
        }
        
        // 4. 安排队列处理 Cron 任务
        // 如果这里显示 Invalid，通常是因为 'every_30_seconds' 尚未通过上面的 filter 生效
        if (!wp_next_scheduled('pms_automation_queue_cron')) {
            wp_schedule_event(time(), 'every_30_seconds', 'pms_automation_queue_cron');
        }
        
        // 5. 安排支付漏单检查任务
        if (!wp_next_scheduled('pms_automation_check_missing_payments')) {
            wp_schedule_event(time(), 'every_minute', 'pms_automation_check_missing_payments');
        }
        
        // 6. 脚本路径检查
        // 我们在插件激活或手动运行测试时已确认路径存在，此处作为日志记录
        if (!pms_automation_core_check_host_script()) {
            $this->log_debug("提醒: 容器内本地路径脚本检查未通过，但只要宿主机路径有效且 SSH 配置正确，任务仍可执行。");
        } else {
            $this->log_debug("✅ 初始化完成：Cron 任务已挂载，本地脚本路径确认正常。");
        }
    }
    
    /**
     * 检查sudo可用性 - 针对Docker环境优化
     */
    private function check_sudo_availability() {
        if (!defined('PMS_AUTOMATION_USE_SUDO') || !PMS_AUTOMATION_USE_SUDO) {
            $this->sudo_enabled = false;
            $this->sudo_tested = true;
            return;
        }
        
        $current_user = '';
        if (function_exists('shell_exec')) {
            $current_user = trim(shell_exec('whoami 2>/dev/null'));
        }
        
        if ($current_user === 'root') {
            $this->sudo_enabled = true;
            $this->sudo_tested = true;
            return;
        }
        
        // 测试sudo权限
        $test_result = shell_exec('sudo -n echo "test" 2>&1');
        if ($test_result && trim($test_result) === 'test') {
            $this->sudo_enabled = true;
            $this->log_debug("✅ Sudo权限检查通过");
        } else {
            $this->sudo_enabled = false;
            $this->log_debug("⚠️ Sudo权限检查失败: " . $test_result);
        }
        
        $this->sudo_tested = true;
    }
    
    private function get_sudo_prefix() {
        if ($this->sudo_enabled && PMS_AUTOMATION_USE_SUDO) {
            $current_user = trim(shell_exec('whoami 2>/dev/null'));
            if ($current_user === 'root') {
                return '';
            }
            
            // 检查是否可以直接使用sudo
            $test_sudo = shell_exec('sudo -n echo "test" 2>&1');
            if ($test_sudo && trim($test_sudo) === 'test') {
                return 'sudo -n ';
            }
            
            // 尝试使用特定用户执行
            if (defined('PMS_AUTOMATION_SUDO_USER')) {
                return 'sudo -u ' . PMS_AUTOMATION_SUDO_USER . ' ';
            }
            
            return 'sudo ';
        }
        return '';
    }
    
    private function log_debug($message) {
        #if ($this->debug_mode) {
            error_log("[PMS Automation DEBUG] " . $message);
        #}
    }

    /**
     * ==================== 新增：智能命令执行函数 ====================
     */
    
    /**
     * 智能执行命令 - 根据环境选择最佳方法
     */
    private function execute_command_smart($command, $timeout = 7200) {
        $this->log_debug("智能执行命令: " . $command);
        
        // 方法1：首先尝试shell_exec（最简单可靠）
        $output = shell_exec($command . " 2>&1");
        
        if ($output !== null) {
            $this->log_debug("✅ shell_exec执行成功，输出长度: " . strlen($output));
            return array(
                'success' => true,
                'output' => $output,
                'return_code' => 0,
                'method' => 'shell_exec'
            );
        }
        
        // 方法2：尝试proc_open但忽略返回代码
        $descriptorspec = array(
            0 => array("pipe", "r"),
            1 => array("pipe", "w"),
            2 => array("pipe", "w")
        );
        
        $process = @proc_open($command, $descriptorspec, $pipes, '/tmp');
        
        if (is_resource($process)) {
            fclose($pipes[0]);
            
            // 读取输出，设置超时
            stream_set_blocking($pipes[1], 0);
            stream_set_blocking($pipes[2], 0);
            
            $output = '';
            $error = '';
            $start_time = time();
            
            while (true) {
                $status = proc_get_status($process);
                
                // 读取可用数据
                $read = array($pipes[1], $pipes[2]);
                $write = null;
                $except = null;
                
                if (stream_select($read, $write, $except, 0, 100000) > 0) {
                    foreach ($read as $stream) {
                        if ($stream === $pipes[1]) {
                            $output .= stream_get_contents($pipes[1]);
                        } else {
                            $error .= stream_get_contents($pipes[2]);
                        }
                    }
                }
                
                // 检查超时
                if ((time() - $start_time) > $timeout) {
                    proc_terminate($process);
                    break;
                }
                
                // 如果进程结束，跳出循环
                if (!$status['running']) {
                    break;
                }
                
                usleep(100000);
            }
            
            // 读取剩余输出
            $output .= stream_get_contents($pipes[1]);
            $error .= stream_get_contents($pipes[2]);
            
            fclose($pipes[1]);
            fclose($pipes[2]);
            
            $status = proc_get_status($process);
            proc_close($process);
            
            $this->log_debug("proc_open执行完成，输出长度: " . strlen($output));
            
            // 关键：只要有输出就认为成功（SSH连接已建立）
            $success = (!empty($output) && strlen($output) > 50);
            
            return array(
                'success' => $success,
                'output' => $output,
                'error' => $error,
                'return_code' => $status['exitcode'] ?? -1,
                'pid' => $status['pid'] ?? 0,
                'method' => 'proc_open'
            );
        }
        
        // 方法3：最后尝试exec
        $output_array = array();
        $return_var = 0;
        @exec($command . " 2>&1", $output_array, $return_var);
        
        if (!empty($output_array)) {
            $output = implode("\n", $output_array);
            $this->log_debug("✅ exec执行成功，输出长度: " . strlen($output));
            return array(
                'success' => ($return_var === 0),
                'output' => $output,
                'return_code' => $return_var,
                'method' => 'exec'
            );
        }
        
        // 所有方法都失败
        $this->log_debug("❌ 所有执行方法都失败");
        return array(
            'success' => false,
            'error' => '所有执行方法都失败',
            'return_code' => -1,
            'method' => 'none'
        );
    }
    
    /**
     * 执行命令 - 修复版，添加环境变量和工作目录
     */
    private function execute_command_with_proc_open($command, $timeout = 3600) {
        $this->log_debug("执行系统命令: " . $command);
        
        // 设置正确的环境变量
        $env = array(
            'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            'HOME' => '/tmp',
            'USER' => $this->get_php_user(),
            'SHELL' => '/bin/bash',
            'TERM' => 'xterm'
        );
        
        // 添加当前环境变量（避免丢失重要变量）
        foreach ($_SERVER as $key => $value) {
            if (is_string($value) && !isset($env[$key])) {
                $env[$key] = $value;
            }
        }
        
        $descriptorspec = array(
            0 => array("pipe", "r"),
            1 => array("pipe", "w"),
            2 => array("pipe", "w")
        );

        // 使用/tmp作为工作目录
        $cwd = '/tmp';
        
        // 确保目录存在且可写
        if (!is_dir($cwd)) {
            @mkdir($cwd, 0755, true);
        }
        
        $this->log_debug("工作目录: $cwd, 环境: " . json_encode($env));
        
        $process = @proc_open($command, $descriptorspec, $pipes, $cwd, $env);
        
        if (is_resource($process)) {
            $this->log_debug("proc_open成功创建进程");
            fclose($pipes[0]);

            $output = '';
            $error_output = '';
            
            // 立即读取，避免阻塞
            $output = stream_get_contents($pipes[1]);
            $error_output = stream_get_contents($pipes[2]);
            
            fclose($pipes[1]);
            fclose($pipes[2]);
            
            $status = proc_get_status($process);
            $return_code = proc_close($process);
            
            $this->log_debug("返回代码: $return_code, 输出长度: " . strlen($output));
            
            return array(
                'output'      => trim($output),
                'error'       => trim($error_output),
                'return_code' => $return_code,
                'success'     => ($return_code === 0),
                'pid'         => $status['pid'] ?? 0
            );
        } else {
            $last_error = error_get_last();
            $this->log_debug("proc_open失败: " . print_r($last_error, true));
            
            // 备选方案：使用直接的系统调用
            return $this->execute_command_fallback($command);
        }
    }
    
    /**
     * 获取PHP运行用户
     */
    private function get_php_user() {
        if (function_exists('posix_getpwuid')) {
            $user = posix_getpwuid(posix_getuid());
            return $user['name'] ?? 'www-data';
        }
        
        // 通过shell命令获取
        $user = trim(shell_exec('whoami 2>/dev/null') ?: 'www-data');
        return $user;
    }
    
    /**
     * 备选执行方案
     */
    private function execute_command_fallback($command) {
        $this->log_debug("使用备选方案执行命令");
        
        // 方法1: shell_exec
        if (function_exists('shell_exec')) {
            $output = shell_exec($command . ' 2>&1');
            if ($output !== null) {
                return array(
                    'success' => true,
                    'output' => $output,
                    'return_code' => 0
                );
            }
        }
        
        // 方法2: exec
        if (function_exists('exec')) {
            $output = array();
            $return_var = 0;
            exec($command . ' 2>&1', $output, $return_var);
            return array(
                'success' => $return_var === 0,
                'output' => implode("\n", $output),
                'return_code' => $return_var
            );
        }
        
        // 方法3: system
        if (function_exists('system')) {
            ob_start();
            $return_var = 0;
            system($command . ' 2>&1', $return_var);
            $output = ob_get_clean();
            return array(
                'success' => $return_var === 0,
                'output' => $output,
                'return_code' => $return_var
            );
        }
        
        // 方法4: 直接使用PHP函数
        $output = array();
        $return_var = 0;
        $handle = popen($command . ' 2>&1', 'r');
        if ($handle) {
            while (!feof($handle)) {
                $output[] = fgets($handle);
            }
            $return_var = pclose($handle);
            return array(
                'success' => $return_var === 0,
                'output' => implode('', $output),
                'return_code' => $return_var
            );
        }
        
        return array(
            'success' => false,
            'error' => '所有执行方法都失败',
            'return_code' => -1
        );
    }
    
    /**
     * 准备执行环境 - 简化版
     */
    private function prepare_execution_environment_simple() {
        $key_path = PMS_SSH_KEY_PATH;
        
        // 1. 确保SSH密钥权限正确
        if (file_exists($key_path)) {
            @chmod($key_path, 0600);
            $this->log_debug("SSH密钥权限: " . substr(sprintf('%o', fileperms($key_path)), -4));
        }
        
        // 2. 创建临时SSH目录（避免/var/www/.ssh权限问题）
        $ssh_dir = '/tmp/.ssh';
        if (!file_exists($ssh_dir)) {
            @mkdir($ssh_dir, 0700, true);
            $this->log_debug("创建SSH目录: " . $ssh_dir);
        }
        
        // 3. 添加宿主机到known_hosts
        $known_hosts = $ssh_dir . '/known_hosts';
        $host_key = shell_exec("ssh-keyscan -H " . HOST_MACHINE_IP . " 2>/dev/null");
        if ($host_key) {
            file_put_contents($known_hosts, $host_key);
            @chmod($known_hosts, 0600);
            $this->log_debug("已添加宿主机到known_hosts: " . HOST_MACHINE_IP);
        } else {
            // 如果ssh-keyscan失败，创建空文件避免错误
            if (!file_exists($known_hosts)) {
                file_put_contents($known_hosts, '');
                @chmod($known_hosts, 0600);
            }
        }
        
        // 4. 测试连接（可选）
        $test_cmd = "ssh -i " . escapeshellarg($key_path) . 
                    " -o StrictHostKeyChecking=no" .
                    " -o UserKnownHostsFile=" . escapeshellarg($known_hosts) .
                    " -o ConnectTimeout=5" .
                    " root@" . HOST_MACHINE_IP . " 'echo 连接测试成功' 2>&1";
        
        $test_result = shell_exec($test_cmd);
        $this->log_debug("连接测试结果: " . ($test_result ? trim($test_result) : '(无输出)'));
        
        return !empty($test_result);
    }
    
    // -------------------------------------------------------------
    // 队列系统核心方法 - 修改版，支持WordPress安装参数
    // -------------------------------------------------------------

    private function add_to_queue($user_id, $domain, $wp_password, $wp_language = 'zh_CN', $wp_username = 'admin', $wp_email = '', $site_title = 'My website') {
        global $wpdb;
        $table_name = $wpdb->prefix . 'pms_automation_queue';
        
        // 检查用户是否已有任务
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM $table_name WHERE user_id = %d AND status IN ('pending', 'queued', 'running')",
            $user_id
        ));
        
        if ($existing) {
            return $existing->id;
        }
        
        // 计算队列位置
        $queue_position = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status IN ('queued', 'running')") + 1;
        
        $result = $wpdb->insert(
            $table_name,
            array(
                'user_id' => $user_id,
                'domain' => $domain,
                'wp_language' => $wp_language,
                'wp_username' => $wp_username,
                'wp_password' => $wp_password,
                'wp_email' => $wp_email,
                'site_title' => $site_title,
                'status' => 'queued',
                'queue_position' => $queue_position,
                'estimated_wait_time' => ($queue_position - 1) * 15 * 60,
                'created_at' => current_time('mysql')
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s')
        );
        
        if ($result === false) {
            $this->log_debug("插入队列失败: " . $wpdb->last_error);
            return false;
        }
        
        $queue_id = $wpdb->insert_id;
        
        // 更新用户元数据
        update_user_meta($user_id, '_pms_automation_domain', $domain);
        update_user_meta($user_id, '_pms_automation_status', 'queued');
        update_user_meta($user_id, '_pms_wordpress_password', $wp_password);
        update_user_meta($user_id, '_pms_wp_language', $wp_language);
        update_user_meta($user_id, '_pms_wp_username', $wp_username);
        update_user_meta($user_id, '_pms_wp_email', $wp_email);
        update_user_meta($user_id, '_pms_site_title', $site_title);
        
        // 更新PMS用户状态表
        $this->update_user_automation_status($user_id, $domain, 'queued');
        
        $this->log_debug("用户 {$user_id} 的域名 {$domain} 已添加到队列，ID: {$queue_id}");
        
        return $queue_id;
    }
    
    /**
     * 更新用户自动化状态表
     */
    private function update_user_automation_status($user_id, $domain, $status, $progress = 0) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'pms_user_automation_status';
        
        // 检查是否存在记录
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table_name WHERE user_id = %d",
            $user_id
        ));
        
        if ($existing) {
            // 更新现有记录
            $wpdb->update(
                $table_name,
                array(
                    'domain' => $domain,
                    'status' => $status,
                    'progress' => $progress,
                    'last_updated' => current_time('mysql')
                ),
                array('user_id' => $user_id),
                array('%s', '%s', '%d', '%s'),
                array('%d')
            );
        } else {
            // 插入新记录
            $wpdb->insert(
                $table_name,
                array(
                    'user_id' => $user_id,
                    'domain' => $domain,
                    'status' => $status,
                    'progress' => $progress,
                    'last_updated' => current_time('mysql')
                ),
                array('%d', '%s', '%s', '%d', '%s')
            );
        }
        
        $this->log_debug("用户 {$user_id} 自动化状态更新: {$status}");
    }

    public function process_automation_queue() {
        global $wpdb;
        $queue_table = $wpdb->prefix . 'pms_automation_queue';
        
        $this->cleanup_stalled_queue_items();
        
        $running_count = $wpdb->get_var("SELECT COUNT(*) FROM $queue_table WHERE status = 'running'");
        
        if ($running_count < $this->max_concurrent) {
            $available_slots = $this->max_concurrent - $running_count;
            $waiting_tasks = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM $queue_table WHERE status = 'queued' ORDER BY queue_position ASC LIMIT %d",
                    intval($available_slots)
                )
            );
            
            foreach ($waiting_tasks as $task) {
                $this->start_queue_task($task);
            }
        }
        
        $this->check_running_tasks();
    }
    
    private function start_queue_task($task) {
        global $wpdb;
        $queue_table = $wpdb->prefix . 'pms_automation_queue';
        
        $wpdb->update(
            $queue_table,
            array(
                'status' => 'running', 
                'started_at' => current_time('mysql'), 
                'progress' => 5
            ),
            array('id' => $task->id),
            array('%s', '%s', '%d'),
            array('%d')
        );
        
        // 更新用户元数据和状态表
        update_user_meta($task->user_id, '_pms_automation_status', 'running');
        update_user_meta($task->user_id, '_pms_automation_progress', 5);
        $this->update_user_automation_status($task->user_id, $task->domain, 'running', 5);
        
        $this->log_debug("开始执行队列任务 ID: {$task->id}, 用户: {$task->user_id}, 域名: {$task->domain}");
        
        // 异步执行自动化脚本 - 修改为支持WordPress安装参数
        $this->execute_automation_script_with_wp_params($task);
    }

    /**
     * 执行自动化脚本 - 支持WordPress安装参数
     */
    private function execute_automation_script_with_wp_params($task) {
        global $wpdb;
        $queue_table = $wpdb->prefix . 'pms_automation_queue';

        $this->log_debug("--- [执行WordPress安装] 开始处理队列任务 ID: " . $task->id . " ---");
        $this->log_debug("安装参数: 域名={$task->domain}, 语言={$task->wp_language}, 用户名={$task->wp_username}, 邮箱={$task->wp_email}, 标题={$task->site_title}");
        
        // 准备执行环境
        $this->prepare_execution_environment_simple();
        
        // 获取输出文件路径
        $output_file = $this->get_output_file_path($task->user_id);
        
        // 构建SSH命令，传递所有WordPress安装参数
        $ssh_command = "ssh -i " . escapeshellarg(PMS_SSH_KEY_PATH) . 
                       " -o StrictHostKeyChecking=no" .
                       " -o UserKnownHostsFile=/tmp/.ssh/known_hosts" .
                       " -o ConnectTimeout=30" .
                       " root@" . HOST_MACHINE_IP .
                       " " . escapeshellarg(PMS_HOST_SCRIPT_PATH) . 
                       " " . escapeshellarg($task->domain) . 
                       " " . escapeshellarg($task->wp_language) .
                       " " . escapeshellarg($task->wp_username) .
                       " " . escapeshellarg($task->wp_password) .
                       " " . escapeshellarg($task->wp_email) .
                       " " . escapeshellarg($task->site_title) .
                       " 2>&1";
        
        $this->log_debug("执行命令: " . $ssh_command);
        
        // 执行SSH命令
        set_time_limit(7200);
        $output = shell_exec($ssh_command);
        
        // 记录执行结果
        $log_content = "=== WordPress安装执行结果 ===\n";
        $log_content .= "时间: " . date('Y-m-d H:i:s') . "\n";
        $log_content .= "域名: " . $task->domain . "\n";
        $log_content .= "语言: " . $task->wp_language . "\n";
        $log_content .= "用户名: " . $task->wp_username . "\n";
        $log_content .= "邮箱: " . $task->wp_email . "\n";
        $log_content .= "网站标题: " . $task->site_title . "\n";
        $log_content .= "命令: " . $ssh_command . "\n";
        $log_content .= "输出:\n" . ($output ?: '(无输出)') . "\n";
        
        file_put_contents($output_file, $log_content);
        
        // 更新数据库
        $pid = 0;
        $process_table = $wpdb->prefix . 'pms_automation_processes';
        
        $wpdb->insert(
            $process_table,
            array(
                'queue_id'   => $task->id, 
                'pid'        => $pid, 
                'command'    => $ssh_command,
                'started_at' => current_time('mysql')
            ),
            array('%d', '%d', '%s', '%s')
        );
        
        $wpdb->update(
            $queue_table,
            array(
                'pid'         => $pid, 
                'output_file' => $output_file, 
                'progress'    => 20,
                'updated_at'  => current_time('mysql')
            ),
            array('id' => $task->id),
            array('%d', '%s', '%d', '%s'),
            array('%d')
        );
        
        update_user_meta($task->user_id, '_pms_automation_output_file', $output_file);
        
        // 检查输出判断成功与否
        if ($output) {
            $success = false;
            if (strpos($output, 'SUCCESS') !== false || 
                strpos($output, '部署完成') !== false || 
                strpos($output, 'Ai47网站 完整部署完成') !== false ||
                strpos($output, 'WordPress安装成功') !== false) {
                $success = true;
                $this->log_debug("✅ WordPress安装成功，检测到成功标记");
            } else {
                $success = (strlen($output) > 500);
                $this->log_debug("输出长度: " . strlen($output) . " 字符");
            }
            
            if ($success) {
                $this->update_queue_status($task->id, 'running', 20);
                wp_schedule_single_event(time() + 10, 'pms_automation_monitor', array($task->id, $pid, $output_file, 'queue'));
                $this->log_debug("✅ SSH命令执行成功，已启动监控");
            } else {
                $this->update_queue_status($task->id, 'failed', 0, $output);
                $this->log_debug("❌ SSH命令执行失败，输出内容可疑");
            }
        } else {
            $this->update_queue_status($task->id, 'failed', 0, 'SSH命令无输出，可能连接失败');
            $this->log_debug("❌ SSH命令无输出");
        }
    }

    private function check_running_tasks() {
        global $wpdb;
        $queue_table = $wpdb->prefix . 'pms_automation_queue';
        $process_table = $wpdb->prefix . 'pms_automation_processes';
        
        $running_tasks = $wpdb->get_results(
            "SELECT q.*, p.pid FROM $queue_table q 
             LEFT JOIN $process_table p ON q.id = p.queue_id 
             WHERE q.status = 'running'"
        );
        
        foreach ($running_tasks as $task) {
            if ($task->pid) {
                if (!$this->check_process_with_sudo($task->pid)) {
                    // 进程已结束，检查输出文件
                    $output = '';
                    if (file_exists($task->output_file)) {
                        $output = file_get_contents($task->output_file);
                    }
                    
                    if (strpos($output, 'SUCCESS') !== false || strpos($output, '部署完成') !== false || strpos($output, 'Ai47网站 完整部署完成') !== false) {
                        $this->update_queue_status($task->id, 'completed', 100, $output);
                        $this->log_debug("任务 {$task->id} 完成成功");
                    } else {
                        $error_msg = $output ?: '进程意外结束';
                        $this->update_queue_status($task->id, 'failed', 0, $error_msg);
                        $this->log_debug("任务 {$task->id} 失败: " . $error_msg);
                    }
                } else {
                    // 进程仍在运行，更新进度
                    if (file_exists($task->output_file)) {
                        $progress = $this->estimate_progress(file_get_contents($task->output_file));
                        if ($progress > $task->progress) {
                            $wpdb->update(
                                $queue_table, 
                                array('progress' => $progress), 
                                array('id' => $task->id),
                                array('%d'),
                                array('%d')
                            );
                            update_user_meta($task->user_id, '_pms_automation_progress', $progress);
                            $this->update_user_automation_status($task->user_id, $task->domain, 'running', $progress);
                        }
                    }
                }
            }
        }
    }

    private function cleanup_stalled_queue_items() {
        global $wpdb;
        $queue_table = $wpdb->prefix . 'pms_automation_queue';
        
        $stalled = $wpdb->get_results(
            "SELECT * FROM $queue_table WHERE status = 'running' AND started_at < NOW() - INTERVAL 30 MINUTE"
        );
        
        foreach ($stalled as $task) {
            if ($task->pid) {
                $this->kill_process_with_sudo($task->pid);
            }
            $this->update_queue_status($task->id, 'failed', 0, '任务执行超时（超过30分钟）');
            $this->log_debug("清理超时任务 ID: {$task->id}");
        }
    }

    private function get_user_queue_position($user_id) {
        global $wpdb;
        $queue_table = $wpdb->prefix . 'pms_automation_queue';
        $position = $wpdb->get_var($wpdb->prepare(
            "SELECT queue_position FROM $queue_table WHERE user_id = %d AND status IN ('queued', 'running') ORDER BY id DESC LIMIT 1",
            $user_id
        ));
        return $position ?: 0;
    }
    
    /**
     * 漏单检查方法
     */
    public function check_missing_payments_cron() {
        $this->log_debug("正在运行漏单检查...");
        
        global $wpdb;
        
        // 查找已完成支付但未标记自动化的用户
        $payment_table = $wpdb->prefix . 'pms_payments';
        if ($wpdb->get_var("SHOW TABLES LIKE '$payment_table'") == $payment_table) {
            $payments = $wpdb->get_results(
                "SELECT p.user_id, p.id as payment_id 
                 FROM {$payment_table} p
                 LEFT JOIN {$wpdb->usermeta} um ON p.user_id = um.user_id AND um.meta_key = '_pms_has_paid'
                 WHERE p.status = 'completed' 
                 AND (um.meta_value IS NULL OR um.meta_value != 'yes')
                 ORDER BY p.id DESC
                 LIMIT 10"
            );
            
            foreach ($payments as $payment) {
                $this->log_debug("发现漏单用户: {$payment->user_id}, 支付ID: {$payment->payment_id}");
                $this->mark_user_for_automation($payment->user_id, $payment->payment_id);
            }
        }
    }

    private function calculate_estimated_wait_time($queue_position) {
        return ($queue_position - 1) * 15 * 60; 
    }
    
    private function check_process_with_sudo($pid) {
        if (!$pid) return false;
        $command = "ps -p {$pid} 2>/dev/null | grep -v PID";
        $result = shell_exec($command);
        return !empty(trim($result));
    }
    
    private function kill_process_with_sudo($pid) {
        if (!$pid) return false;
        $sudo_prefix = $this->get_sudo_prefix();
        $command = "{$sudo_prefix}kill -9 {$pid} 2>/dev/null";
        $result = shell_exec($command);
        return $this->check_process_with_sudo($pid) === false;
    }

    /**
     * 更新队列状态 - 修复版
     */
    private function update_queue_status($queue_id, $status, $progress, $error_message = null) {
        global $wpdb;
        $queue_table = $wpdb->prefix . 'pms_automation_queue';
        
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
                
                // 更新PMS状态表
                $this->update_user_automation_status($task->user_id, $task->domain, 'completed', 100);
                
                // 发送完成邮件
                $this->send_completion_email($task->user_id);
                
                $this->log_debug("队列任务完成 - 队列ID: {$queue_id}, 用户: {$task->user_id}, 域名: {$task->domain}");
            } elseif ($status === 'failed') {
                update_user_meta($task->user_id, '_pms_automation_error', $error_message);
                $this->update_user_automation_status($task->user_id, $task->domain, 'failed', 0);
                
                $this->log_debug("队列任务失败 - 队列ID: {$queue_id}, 用户: {$task->user_id}");
            } elseif ($status === 'running') {
                $this->update_user_automation_status($task->user_id, $task->domain, 'running', $progress);
            }
        }
    }

    /**
     * ==================== 渲染与前端 ====================
     */
    
    public function render_automation_dashboard($atts = array()) {
        if (!is_user_logged_in()) {
            return '<p>请先登录以查看自动化配置。</p>';
        }
        
        $user_id = get_current_user_id();
        $is_admin = current_user_can('manage_options');
        
        $has_paid = $this->check_user_has_paid_enhanced($user_id);
        
        if (!$is_admin && !$has_paid) {
            ob_start();
            ?>
            <div id="pms-payment-check-container" class="pms-automation-section">
                <div class="pms-automation-header">
                    <h3><?php _e('正在确认支付状态...', 'pms-secupay-automation-core'); ?></h3>
                </div>
                <div style="text-align: center; padding: 30px;">
                    <div class="spinner" style="display: inline-block; width: 40px; height: 40px; border: 4px solid #f3f3f3; border-top: 4px solid #3498db; border-radius: 50%; animation: spin 1s linear infinite;"></div>
                    <p><?php _e('我们正在从支付网关接收确认信息，请稍候...', 'pms-secupay-automation-core'); ?></p>
                </div>
                <style>@keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }</style>
                <script type="text/javascript">
                jQuery(document).ready(function($) {
                    var checkInterval = setInterval(function() {
                        $.ajax({
                            url: '<?php echo admin_url('admin-ajax.php'); ?>',
                            type: 'POST',
                            data: {
                                action: 'pms_poll_payment_status',
                                nonce: '<?php echo wp_create_nonce('pms_automation_nonce'); ?>'
                            },
                            success: function(res) {
                                if (res.success && res.data.paid) {
                                    clearInterval(checkInterval);
                                    location.reload(); 
                                }
                            }
                        });
                    }, 3000);
                });
                </script>
            </div>
            <?php
            return ob_get_clean();
        }
        
        $domain = get_user_meta($user_id, '_pms_automation_domain', true);
        $status = get_user_meta($user_id, '_pms_automation_status', true) ?: 'pending';
        $progress = get_user_meta($user_id, '_pms_automation_progress', true) ?: 0;
        $queue_position = $this->get_user_queue_position($user_id);
        $error_message = get_user_meta($user_id, '_pms_automation_error', true);
        
        // 获取WordPress安装参数
        $wp_language = get_user_meta($user_id, '_pms_wp_language', true) ?: 'zh_CN';
        $wp_username = get_user_meta($user_id, '_pms_wp_username', true) ?: 'admin';
        $wp_email = get_user_meta($user_id, '_pms_wp_email', true) ?: '';
        $site_title = get_user_meta($user_id, '_pms_site_title', true) ?: 'My website';
        
        $admin_note = $is_admin ? '<div class="admin-note" style="background:#e3f2fd;padding:10px;margin-bottom:15px;border-left:4px solid #2196f3;"><strong>👨‍💼 管理员视图：</strong>您拥有完全访问权限。</div>' : '';
        
        ob_start();
        ?>
        <div class="pms-automation-section" id="pms-automation-dashboard">
            <?php echo $admin_note; ?>
            
            <div class="pms-automation-header">
                <h2><?php _e('🎉 Ai47网站自动化配置', 'pms-secupay-automation-core'); ?></h2>
                <p class="description"><?php _e('输入您的域名和Ai47配置信息，我们将自动为您部署Ai47网站。', 'pms-secupay-automation-core'); ?></p>
            </div>
            
            <?php if ($this->queue_enabled && $is_admin): ?>
            <div class="parallel-status" style="background:#f8f9fa;padding:10px;margin-bottom:15px;border-radius:5px;">
                <h4 style="margin-top:0;"><?php _e('🔄 系统并行状态', 'pms-secupay-automation-core'); ?></h4>
                <?php
                global $wpdb;
                $running_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}pms_automation_queue WHERE status = 'running'");
                echo "<p>当前运行任务: <strong>$running_count</strong> / <strong>{$this->max_concurrent}</strong></p>";
                ?>
            </div>
            <?php endif; ?>
            
            <div class="pms-automation-card" style="background:#fff;border:1px solid #ddd;border-radius:5px;padding:20px;margin-bottom:20px;">
                <div class="automation-status" style="margin-bottom:20px;">
                    <div class="status-badge status-<?php echo esc_attr($status); ?>" style="display:inline-block;padding:5px 15px;border-radius:20px;font-weight:bold;margin-bottom:10px;background:#f0f0f0;">
                        <?php echo $this->get_status_text($status); ?>
                    </div>
                    
                    <?php if ($status === 'running'): ?>
                        <div class="progress-container" style="margin-top:15px;">
                            <div class="progress-bar" style="height:20px;background:#e0e0e0;border-radius:10px;overflow:hidden;">
                                <div style="width: <?php echo esc_attr($progress); ?>%;height:100%;background:#4CAF50;transition:width 0.5s;">
                                    <span class="progress-text" style="color:#fff;line-height:20px;padding-left:10px;font-weight:bold;"><?php echo esc_html($progress); ?>%</span>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($error_message && $status === 'failed'): ?>
                        <div class="error-message" style="margin-top:15px;padding:10px;background:#ffebee;border-left:4px solid #f44336;">
                            <strong>错误信息:</strong>
                            <pre style="white-space:pre-wrap;font-size:12px;"><?php echo esc_html($error_message); ?></pre>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($status === 'pending' || $status === 'failed' || $status === 'domain_required'): ?>
                    <div class="automation-setup">
                        <h3 style="margin-top:0;"><?php _e('配置您的Ai47网站', 'pms-secupay-automation-core'); ?></h3>
                        <p class="description"><?php _e('请输入您的域名和Ai47配置信息', 'pms-secupay-automation-core'); ?></p>
                        
                        <form id="pms-domain-form" class="automation-form" style="margin-top:20px;">
                            <?php wp_nonce_field('pms_save_domain', 'pms_domain_nonce'); ?>
                            <input type="hidden" name="action" value="pms_save_domain">
                            
                            <div class="form-group" style="margin-bottom:15px;">
                                <label for="pms-automation-domain" style="display:block;margin-bottom:5px;font-weight:bold;">域名</label>
                                <input type="text" id="pms-automation-domain" name="domain" 
                                       value="<?php echo esc_attr($domain); ?>" 
                                       placeholder="example.com" 
                                       class="form-control" 
                                       style="width:100%;padding:10px;border:1px solid #ddd;border-radius:4px;font-size:16px;"
                                       required />
                                <div class="form-text" style="margin-top:5px;font-size:12px;color:#666;">请输入您的域名（如：example.com），不需要包含 http:// 或 https://</div>
                                <div id="domain-validation" style="margin-top:5px;font-size:12px;"></div>
                            </div>
                            
                            <!-- 新增：WordPress安装参数 -->
                            <div class="wp-install-params" style="margin-top:20px;padding:15px;background:#f8f9fa;border-radius:5px;">
                                <h4 style="margin-top:0;margin-bottom:15px;">Ai47配置</h4>
                                
                                <div class="row" style="display:flex;flex-wrap:wrap;margin:0 -10px;">
                                    <div class="col-md-6" style="flex:0 0 50%;max-width:50%;padding:0 10px;margin-bottom:15px;">
                                        <label for="wp_language" style="display:block;margin-bottom:5px;font-weight:bold;">网站语言</label>
                                        <select class="form-control" id="wp_language" name="wp_language" 
                                                style="width:100%;padding:10px;border:1px solid #ddd;border-radius:4px;font-size:14px;" required>
                                            <option value="zh_CN" <?php selected($wp_language, 'zh_CN'); ?>>简体中文</option>
                                            <option value="en_US" <?php selected($wp_language, 'en_US'); ?>>English</option>
                                            <option value="ja" <?php selected($wp_language, 'ja'); ?>>日本語</option>
                                            <option value="ko_KR" <?php selected($wp_language, 'ko_KR'); ?>>한국어</option>
                                            <option value="fr_FR" <?php selected($wp_language, 'fr_FR'); ?>>Français</option>
                                            <option value="de_DE" <?php selected($wp_language, 'de_DE'); ?>>Deutsch</option>
                                            <option value="es_ES" <?php selected($wp_language, 'es_ES'); ?>>Español</option>
                                            <option value="ru_RU" <?php selected($wp_language, 'ru_RU'); ?>>Русский</option>
                                        </select>
                                    </div>
                                    
                                    <div class="col-md-6" style="flex:0 0 50%;max-width:50%;padding:0 10px;margin-bottom:15px;">
                                        <label for="site_title" style="display:block;margin-bottom:5px;font-weight:bold;">网站标题</label>
                                        <input type="text" class="form-control" id="site_title" name="site_title" 
                                               value="<?php echo esc_attr($site_title); ?>" 
                                               placeholder="My website" 
                                               style="width:100%;padding:10px;border:1px solid #ddd;border-radius:4px;font-size:14px;" 
                                               required />
                                    </div>
                                </div>
                                
                                <div class="row" style="display:flex;flex-wrap:wrap;margin:0 -10px;">
                                    <div class="col-md-6" style="flex:0 0 50%;max-width:50%;padding:0 10px;margin-bottom:15px;">
                                        <label for="wp_username" style="display:block;margin-bottom:5px;font-weight:bold;">管理员用户名</label>
                                        <input type="text" class="form-control" id="wp_username" name="wp_username" 
                                               value="<?php echo esc_attr($wp_username); ?>" 
                                               placeholder="admin" 
                                               style="width:100%;padding:10px;border:1px solid #ddd;border-radius:4px;font-size:14px;" 
                                               required minlength="4" maxlength="60" />
                                        <div class="form-text" style="margin-top:5px;font-size:12px;color:#666;">4-60个字符</div>
                                    </div>
                                    
                                    <div class="col-md-6" style="flex:0 0 50%;max-width:50%;padding:0 10px;margin-bottom:15px;">
                                        <label for="wp_email" style="display:block;margin-bottom:5px;font-weight:bold;">管理员邮箱</label>
                                        <input type="email" class="form-control" id="wp_email" name="wp_email" 
                                               value="<?php echo esc_attr($wp_email); ?>" 
                                               placeholder="admin@example.com" 
                                               style="width:100%;padding:10px;border:1px solid #ddd;border-radius:4px;font-size:14px;" 
                                               required />
                                    </div>
                                </div>
                                
                                <div class="row" style="display:flex;flex-wrap:wrap;margin:0 -10px;">
                                    <div class="col-md-12" style="flex:0 0 100%;max-width:100%;padding:0 10px;margin-bottom:15px;">
                                        <label for="wp_password" style="display:block;margin-bottom:5px;font-weight:bold;">管理员密码</label>
                                        <div style="position:relative;">
                                            <input type="password" class="form-control" id="wp_password" name="wp_password" 
                                                   placeholder="输入密码" 
                                                   style="width:100%;padding:10px;border:1px solid #ddd;border-radius:4px;font-size:14px;padding-right:40px;" 
                                                   required minlength="8" />
                                            <button type="button" id="togglePassword" 
                                                    style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#666;">
                                                <span id="togglePasswordIcon">👁️</span>
                                            </button>
                                        </div>
                                        <div class="form-text" style="margin-top:5px;font-size:12px;color:#666;">密码至少8位，建议包含字母、数字和特殊字符</div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-actions" style="margin-top:20px;">
                                <button type="submit" class="pms-button pms-button-primary" 
                                        style="background:#4CAF50;color:#fff;border:none;padding:12px 30px;border-radius:4px;font-size:16px;cursor:pointer;width:100%;">
                                    <?php _e('开始部署', 'pms-secupay-automation-core'); ?>
                                </button>
                                <button type="button" id="validate-domain-btn" class="pms-button pms-button-secondary" 
                                        style="background:#2196F3;color:#fff;border:none;padding:10px 20px;border-radius:4px;font-size:14px;cursor:pointer;margin-top:10px;width:100%;">
                                    <?php _e('验证域名', 'pms-secupay-automation-core'); ?>
                                </button>
                            </div>
                        </form>
                        
                        <div id="domain-validation-result" style="margin-top:15px;display:none;"></div>
                    </div>
                <?php endif; ?>
                
                <?php if ($status === 'queued'): ?>
                    <div class="automation-queued" style="text-align:center;padding:30px;">
                        <h3 style="color:#FF9800;">⏳ 正在排队中</h3>
                        <p>您的任务在队列中的位置: <strong>#<?php echo $queue_position; ?></strong></p>
                        <p>预计等待时间: <strong><?php echo ceil($this->calculate_estimated_wait_time($queue_position) / 60); ?> 分钟</strong></p>
                        <div class="spinner" style="display:inline-block;width:40px;height:40px;border:4px solid #f3f3f3;border-top:4px solid #3498db;border-radius:50%;animation:spin 1s linear infinite;margin-top:20px;"></div>
                        <p>请耐心等待，系统会自动处理您的请求...</p>
                    </div>
                <?php endif; ?>

                <?php if ($status === 'running'): ?>
                    <div class="automation-running">
                        <h3 style="color:#2196F3;">🚀 正在部署中...</h3>
                        <p>进度: <strong><?php echo $progress; ?>%</strong></p>
                        
                        <div class="live-output-container" style="margin-top:20px;background:#000;color:#0f0;padding:15px;border-radius:5px;max-height:300px;overflow-y:auto;font-family:'Courier New',monospace;font-size:12px;">
                            <div class="live-output" id="pms-live-output">
                                <div>正在获取实时日志...</div>
                            </div>
                        </div>
                        
                        <div style="margin-top:15px;">
                            <button id="refresh-logs" class="pms-button" style="background:#607D8B;color:#fff;border:none;padding:8px 15px;border-radius:4px;cursor:pointer;">
                                刷新日志
                            </button>
                            <button id="cancel-deployment" class="pms-button" style="background:#f44336;color:#fff;border:none;padding:8px 15px;border-radius:4px;cursor:pointer;margin-left:10px;">
                                取消部署
                            </button>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($status === 'completed'): ?>
                    <div class="automation-completed" style="text-align:center;padding:30px;background:#E8F5E9;border-radius:5px;">
                        <h3 style="color:#4CAF50;margin-top:0;">✅ 部署成功！</h3>
                        
                        <div style="background:#fff;padding:20px;border-radius:5px;margin:20px 0;text-align:left;">
                            <h4>您的网站信息:</h4>
                            <table style="width:100%;">
                                <tr>
                                    <td style="padding:8px 0;font-weight:bold;width:120px;">网站地址:</td>
                                    <td>
                                        <a href="https://<?php echo esc_attr($domain); ?>" target="_blank" style="color:#2196F3;">
                                            https://<?php echo esc_html($domain); ?>
                                        </a>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:8px 0;font-weight:bold;">网站标题:</td>
                                    <td><?php echo esc_html($site_title); ?></td>
                                </tr>
                                <tr>
                                    <td style="padding:8px 0;font-weight:bold;">管理员用户名:</td>
                                    <td><?php echo esc_html($wp_username); ?></td>
                                </tr>
                                <tr>
                                    <td style="padding:8px 0;font-weight:bold;">管理员邮箱:</td>
                                    <td><?php echo esc_html($wp_email); ?></td>
                                </tr>
                                <tr>
                                    <td style="padding:8px 0;font-weight:bold;">管理员密码:</td>
                                    <td><?php echo esc_html(get_user_meta($user_id, '_pms_wordpress_password', true)); ?></td>
                                </tr>
                                <tr>
                                    <td style="padding:8px 0;font-weight:bold;">完成时间:</td>
                                    <td><?php echo esc_html(get_user_meta($user_id, '_pms_automation_completed_time', true)); ?></td>
                                </tr>
                            </table>
                        </div>
                        
                        <div style="margin-top:20px;">
                            <a href="https://<?php echo esc_attr($domain); ?>/wp-admin" target="_blank" 
                               class="pms-button" style="background:#4CAF50;color:#fff;padding:12px 25px;border-radius:4px;text-decoration:none;display:inline-block;margin-right:10px;">
                                访问您的Ai47网站后台
                            </a>
                            <a href="https://<?php echo esc_attr($domain); ?>" target="_blank" 
                               class="pms-button" style="background:#2196F3;color:#fff;padding:12px 25px;border-radius:4px;text-decoration:none;display:inline-block;">
                                访问网站首页
                            </a>
                        </div>
                        
                        <p style="margin-top:20px;color:#666;font-size:14px;">
                            请妥善保存您的管理员密码。建议首次登录后立即修改密码。
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- JavaScript代码 -->
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // 确保jQuery已加载
            if (typeof jQuery === 'undefined') {
                console.error('jQuery未加载');
                return;
            }
            
            // 密码显示/隐藏切换
            $('#togglePassword').off('click').on('click', function() {
                var passwordInput = $('#wp_password');
                var icon = $('#togglePasswordIcon');
                var type = passwordInput.attr('type');
                
                if (type === 'password') {
                    passwordInput.attr('type', 'text');
                    icon.text('🙈');
                } else {
                    passwordInput.attr('type', 'password');
                    icon.text('👁️');
                }
            });
            
            // 验证域名
            $('#validate-domain-btn').off('click').on('click', function() {
                var domain = $('#pms-automation-domain').val();
                if (!domain) {
                    alert('请输入域名');
                    return;
                }
                
                $('#domain-validation-result').html('<div style="padding:10px;background:#fff3cd;border-left:4px solid #ffc107;"><strong>验证中...</strong></div>').show();
                
                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: {
                        action: 'pms_validate_domain',
                        domain: domain,
                        nonce: '<?php echo wp_create_nonce('pms_automation_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#domain-validation-result').html('<div style="padding:10px;background:#d4edda;border-left:4px solid #28a745;"><strong>✅ 验证通过:</strong> ' + response.data.message + '</div>').show();
                        } else {
                            $('#domain-validation-result').html('<div style="padding:10px;background:#f8d7da;border-left:4px solid #dc3545;"><strong>❌ 验证失败:</strong> ' + response.data.message + '</div>').show();
                        }
                    },
                    error: function(xhr, status, error) {
                        $('#domain-validation-result').html('<div style="padding:10px;background:#f8d7da;border-left:4px solid #dc3545;"><strong>❌ 请求失败:</strong> ' + error + '</div>').show();
                        console.error('AJAX错误:', error);
                    }
                });
            });
            
            // 修复表单提交
            $('#pms-domain-form').off('submit').on('submit', function(e) {
                e.preventDefault();
                
                var domain = $('#pms-automation-domain').val();
                if (!domain) {
                    alert('请输入域名');
                    return;
                }
                
                // 基本域名格式验证
                var domainRegex = /^(?:[a-zA-Z0-9](?:[a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/;
                if (!domainRegex.test(domain)) {
                    alert('请输入有效的域名（如：example.com）');
                    return;
                }
                
                // 验证其他必填字段
                var wp_password = $('#wp_password').val();
                var wp_email = $('#wp_email').val();
                var wp_username = $('#wp_username').val();
                var site_title = $('#site_title').val();
                
                if (!wp_password || wp_password.length < 8) {
                    alert('密码至少需要8位字符');
                    return;
                }
                
                if (!wp_email) {
                    alert('请输入管理员邮箱');
                    return;
                }
                
                if (!wp_username) {
                    alert('请输入管理员用户名');
                    return;
                }
                
                if (!site_title) {
                    alert('请输入网站标题');
                    return;
                }
                
                var $submitBtn = $(this).find('button[type="submit"]');
                $submitBtn.prop('disabled', true).text('提交中...');
                
                var formData = $(this).serialize();
                console.log('提交数据:', formData);
                
                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: formData,
                    success: function(response) {
                        console.log('响应:', response);
                        if (response.success) {
                            // 显示成功消息
                            $('#pms-automation-dashboard').html('<div style="text-align:center;padding:50px;"><div class="spinner" style="display:inline-block;width:40px;height:40px;border:4px solid #f3f3f3;border-top:4px solid #3498db;border-radius:50%;animation:spin 1s linear infinite;"></div><h3>已提交，正在处理...</h3><p>页面将自动刷新</p></div>');
                            // 等待2秒后刷新页面
                            setTimeout(function() {
                                location.reload();
                            }, 2000);
                        } else {
                            alert('提交失败: ' + (response.data ? response.data.message : '未知错误'));
                            $submitBtn.prop('disabled', false).text('开始部署');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX错误:', error);
                        alert('请求失败，请稍后重试。错误: ' + error);
                        $submitBtn.prop('disabled', false).text('开始部署');
                    }
                });
            });
            
            <?php if ($status === 'running' || $status === 'queued'): ?>
            // 自动刷新日志
            function refreshLogs() {
                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: {
                        action: 'pms_get_script_output',
                        nonce: '<?php echo wp_create_nonce('pms_automation_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#pms-live-output').html('<pre style="margin:0;">' + response.data.output + '</pre>');
                            // 滚动到底部
                            $('.live-output-container').scrollTop($('.live-output-container')[0].scrollHeight);
                        }
                    }
                });
            }
            
            // 自动刷新进度
            function refreshProgress() {
                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: {
                        action: 'pms_check_progress',
                        nonce: '<?php echo wp_create_nonce('pms_automation_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            var status = response.data.status;
                            var progress = response.data.progress;
                            
                            if (status === 'completed' || status === 'failed') {
                                location.reload();
                            } else if (status === 'running' && progress > <?php echo $progress; ?>) {
                                // 更新进度条
                                $('.progress-bar > div').css('width', progress + '%');
                                $('.progress-text').text(progress + '%');
                                $('p strong').text(progress + '%');
                            }
                        }
                    }
                });
            }
            
            // 初始加载日志
            refreshLogs();
            refreshProgress();
            
            // 每5秒刷新一次日志和进度
            setInterval(refreshLogs, 5000);
            setInterval(refreshProgress, 3000);
            
            // 手动刷新日志
            $('#refresh-logs').off('click').on('click', function() {
                refreshLogs();
            });
            
            // 取消部署
            $('#cancel-deployment').off('click').on('click', function() {
                if (confirm('确定要取消部署吗？')) {
                    $.ajax({
                        url: '<?php echo admin_url('admin-ajax.php'); ?>',
                        type: 'POST',
                        data: {
                            action: 'pms_cancel_automation',
                            nonce: '<?php echo wp_create_nonce('pms_automation_nonce'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                location.reload();
                            }
                        }
                    });
                }
            });
            <?php endif; ?>
        });
        </script>
        
        <style>
        .status-pending { background-color: #ff9800; color: #fff; }
        .status-queued { background-color: #2196f3; color: #fff; }
        .status-running { background-color: #4caf50; color: #fff; }
        .status-completed { background-color: #8bc34a; color: #fff; }
        .status-failed { background-color: #f44336; color: #fff; }
        .status-domain_required { background-color: #ff9800; color: #fff; }
        
        @keyframes spin { 
            0% { transform: rotate(0deg); } 
            100% { transform: rotate(360deg); } 
        }
        
        /* 修复按钮样式 */
        .pms-button {
            transition: all 0.3s ease;
        }
        .pms-button:hover {
            opacity: 0.9;
            transform: translateY(-2px);
        }
        .pms-button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        /* WordPress配置区域样式 */
        .wp-install-params {
            border: 1px solid #ddd;
        }
        .wp-install-params h4 {
            color: #333;
            border-bottom: 2px solid #4CAF50;
            padding-bottom: 10px;
        }
        </style>
        <?php
        return ob_get_clean();
    }
    
    private function get_status_text($status) {
        $texts = array(
            'pending' => '等待配置',
            'domain_required' => '需要输入域名',
            'queued' => '排队中',
            'running' => '部署中',
            'completed' => '已完成',
            'failed' => '失败'
        );
        return isset($texts[$status]) ? $texts[$status] : $status;
    }

    /**
     * ==================== AJAX 处理 ====================
     */
    
    public function ajax_require_login() {
        wp_send_json_error(array('message' => __('请先登录', 'pms-secupay-automation-core')));
    }

    private function check_payment_direct_db_fast($user_id) {
        global $wpdb;
        $payment_table = $wpdb->prefix . 'pms_payments';
        
        // 检查表是否存在
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$payment_table'");
        if (!$table_exists) {
            return false;
        }
        
        $query = $wpdb->prepare(
            "SELECT id FROM {$payment_table} 
             WHERE user_id = %d 
             AND status = 'completed'
             ORDER BY id DESC LIMIT 1",
            $user_id
        );
        $payment_id = $wpdb->get_var($query);
        return $payment_id ?: false;
    }

    private function check_user_has_paid_enhanced($user_id) {
        if (user_can($user_id, 'manage_options')) {
            return true;
        }
        
        $has_paid_marker = get_user_meta($user_id, '_pms_has_paid', true);
        if ($has_paid_marker === 'yes') {
            return true;
        }
        
        $payment_id = $this->check_payment_direct_db_fast($user_id);
        if ($payment_id) {
            $this->mark_user_for_automation($user_id, $payment_id);
            return true;
        }
        
        return false;
    }
    
    public function ajax_poll_payment_status() {
        check_ajax_referer('pms_automation_nonce', 'nonce');
        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(array('message' => '用户未登录'));
        }
        
        if ($this->check_payment_direct_db_fast($user_id)) {
            wp_send_json_success(array('paid' => true));
        } else {
            wp_send_json_success(array('paid' => false));
        }
    }
    
    /**
     * AJAX: 保存域名和WordPress配置 - 修改版
     */
    public function ajax_save_domain() {
        error_log('=== ajax_save_domain called ===');
        error_log('POST数据: ' . print_r($_POST, true));
        
        // 检查nonce
        if (!isset($_POST['pms_domain_nonce']) || !wp_verify_nonce($_POST['pms_domain_nonce'], 'pms_save_domain')) {
            error_log('Nonce验证失败');
            wp_send_json_error(array('message' => '安全验证失败'));
        }
        
        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(array('message' => '用户未登录'));
        }
        
        // 获取所有参数
        $domain = isset($_POST['domain']) ? sanitize_text_field($_POST['domain']) : '';
        $wp_language = isset($_POST['wp_language']) ? sanitize_text_field($_POST['wp_language']) : 'zh_CN';
        $wp_username = isset($_POST['wp_username']) ? sanitize_text_field($_POST['wp_username']) : 'admin';
        $wp_password = isset($_POST['wp_password']) ? sanitize_text_field($_POST['wp_password']) : '';
        $wp_email = isset($_POST['wp_email']) ? sanitize_email($_POST['wp_email']) : '';
        $site_title = isset($_POST['site_title']) ? sanitize_text_field($_POST['site_title']) : 'My website';
        
        // 验证参数
        if (empty($domain)) {
            wp_send_json_error(array('message' => '请输入域名'));
        }
        
        if (empty($wp_password) || strlen($wp_password) < 8) {
            wp_send_json_error(array('message' => '密码至少需要8位字符'));
        }
        
        if (empty($wp_email) || !is_email($wp_email)) {
            wp_send_json_error(array('message' => '请输入有效的邮箱地址'));
        }
        
        if (empty($wp_username) || strlen($wp_username) < 4) {
            wp_send_json_error(array('message' => '用户名至少需要4个字符'));
        }
        
        if (empty($site_title)) {
            wp_send_json_error(array('message' => '请输入网站标题'));
        }
        
        // 验证域名格式
        if (!preg_match('/^(?:[a-zA-Z0-9](?:[a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/', $domain)) {
            wp_send_json_error(array('message' => '域名格式无效'));
        }
        
        // 检查域名是否已存在
        global $wpdb;
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = '_pms_automation_domain' AND meta_value = %s AND user_id != %d LIMIT 1",
            $domain, $user_id
        ));
        
        if ($existing) {
            wp_send_json_error(array('message' => '该域名已被其他用户使用'));
        }
        
        // 保存到队列（包含所有WordPress参数）
        $queue_id = $this->add_to_queue($user_id, $domain, $wp_password, $wp_language, $wp_username, $wp_email, $site_title);
        
        if ($queue_id) {
            wp_send_json_success(array(
                'message' => '域名和Ai47配置已保存并加入队列',
                'queue_id' => $queue_id,
                'queue_position' => $this->get_user_queue_position($user_id)
            ));
        } else {
            wp_send_json_error(array('message' => '加入队列失败'));
        }
    }
    
    /**
     * AJAX: 验证域名 - 修复版
     */
    public function ajax_validate_domain() {
        // 检查nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'pms_automation_nonce')) {
            wp_send_json_error(array('message' => '安全验证失败'));
        }
        
        $domain = isset($_POST['domain']) ? sanitize_text_field($_POST['domain']) : '';
        if (empty($domain)) {
            wp_send_json_error(array('message' => '请输入域名'));
        }
        
        // 检查域名格式
        if (!preg_match('/^(?:[a-zA-Z0-9](?:[a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/', $domain)) {
            wp_send_json_error(array('message' => '域名格式无效，请使用如 example.com 的格式'));
        }
        
        // 检查域名是否已存在（全局检查）
        global $wpdb;
        $user_id = get_current_user_id();
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = '_pms_automation_domain' AND meta_value = %s AND user_id != %d LIMIT 1",
            $domain, $user_id
        ));
        
        if ($existing) {
            wp_send_json_error(array('message' => '该域名已被其他用户使用'));
        }
        
        // 检查队列中是否已有相同域名的任务
        $queue_table = $wpdb->prefix . 'pms_automation_queue';
        $existing_queue = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $queue_table WHERE domain = %s AND status IN ('queued', 'running') LIMIT 1",
            $domain
        ));
        
        if ($existing_queue) {
            wp_send_json_error(array('message' => '该域名正在处理中，请稍后再试'));
        }
        
        // 尝试DNS解析
        $dns_records = @dns_get_record($domain, DNS_A);
        if (empty($dns_records)) {
            wp_send_json_success(array(
                'message' => '域名格式正确，但DNS解析未生效。请确保域名已正确解析到服务器IP。',
                'warning' => true
            ));
        } else {
            wp_send_json_success(array(
                'message' => '域名格式正确，DNS解析正常。',
                'dns_records' => $dns_records
            ));
        }
    }
    
    public function ajax_check_progress() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'pms_automation_nonce')) {
            wp_send_json_error(array('message' => '安全验证失败'));
        }
        
        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(array('message' => '用户未登录'));
        }
        
        $status = get_user_meta($user_id, '_pms_automation_status', true) ?: 'pending';
        $progress = get_user_meta($user_id, '_pms_automation_progress', true) ?: 0;
        $queue_position = $this->get_user_queue_position($user_id);
        
        wp_send_json_success(array(
            'status' => $status,
            'progress' => $progress,
            'queue_position' => $queue_position
        ));
    }
    
    public function ajax_get_script_output() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'pms_automation_nonce')) {
            wp_send_json_error(array('message' => '安全验证失败'));
        }
        
        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(array('message' => '用户未登录'));
        }
        
        $output_file = get_user_meta($user_id, '_pms_automation_output_file', true);
        if ($output_file && file_exists($output_file)) {
            $output = file_get_contents($output_file);
            // 只显示最后100行
            $lines = explode("\n", $output);
            $lines = array_slice($lines, -100);
            $output = implode("\n", $lines);
            wp_send_json_success(array('output' => $output));
        } else {
            wp_send_json_success(array('output' => '正在启动部署脚本...'));
        }
    }

    public function ajax_admin_get_task_log() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => '权限不足'));
        }
        
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'pms_automation_nonce')) {
            wp_send_json_error(array('message' => '安全验证失败'));
        }
        
        $task_id = isset($_POST['task_id']) ? intval($_POST['task_id']) : 0;
        if (!$task_id) {
            wp_send_json_error(array('message' => '无效的任务ID'));
        }
        
        global $wpdb;
        $task = $wpdb->get_row($wpdb->prepare(
            "SELECT output_file, status FROM {$wpdb->prefix}pms_automation_queue WHERE id = %d",
            $task_id
        ));
        
        if ($task && file_exists($task->output_file)) {
            wp_send_json_success(array(
                'output' => file_get_contents($task->output_file), 
                'status' => $task->status
            ));
        } else {
            wp_send_json_error(array('message' => '未找到日志文件'));
        }
    }

    /**
     * AJAX: 从PMS订阅页面开始配置
     */
    public function ajax_setup_subscription_automation() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'pms_automation_nonce')) {
            wp_send_json_error(array('message' => '安全验证失败'));
        }
        
        $user_id = get_current_user_id();
        $subscription_id = isset($_POST['subscription_id']) ? intval($_POST['subscription_id']) : 0;
        
        if (!$user_id || !$subscription_id) {
            wp_send_json_error(array('message' => '参数错误'));
        }
        
        // 检查用户是否有有效的订阅
        if (function_exists('pms_get_member_subscription')) {
            $subscription = pms_get_member_subscription($subscription_id);
            if (!$subscription || $subscription->user_id != $user_id) {
                wp_send_json_error(array('message' => '订阅不存在或无权访问'));
            }
        }
        
        // 检查是否已支付
        if (!$this->check_user_has_paid_enhanced($user_id)) {
            wp_send_json_error(array('message' => '请先完成支付'));
        }
        
        // 检查是否已有域名
        $existing_domain = get_user_meta($user_id, '_pms_automation_domain', true);
        if ($existing_domain) {
            wp_send_json_success(array(
                'has_domain' => true,
                'domain' => $existing_domain,
                'status' => get_user_meta($user_id, '_pms_automation_status', true)
            ));
        } else {
            wp_send_json_success(array(
                'has_domain' => false,
                'message' => '请配置域名'
            ));
        }
    }

    /**
     * AJAX: SSH连接诊断
     */
    public function ajax_diagnose_ssh_connection() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'pms_automation_nonce')) {
            wp_send_json_error(array('message' => '安全验证失败'));
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => '权限不足'));
        }
        
        $results = array();
        
        // 1. 检查proc_open函数
        $results['proc_open_enabled'] = function_exists('proc_open') ? '✅ 已启用' : '❌ 未启用';
        
        // 2. 检查shell_exec函数
        $results['shell_exec_enabled'] = function_exists('shell_exec') ? '✅ 已启用' : '❌ 未启用';
        
        // 3. 检查SSH客户端
        $ssh_path = shell_exec('which ssh');
        $results['ssh_client'] = $ssh_path ? "✅ 找到SSH客户端: " . trim($ssh_path) : '❌ SSH客户端未安装';
        
        // 4. 检查SSH密钥文件
        $key_path = PMS_SSH_KEY_PATH;
        $results['ssh_key_exists'] = file_exists($key_path) ? "✅ 密钥文件存在" : "❌ 密钥文件不存在";
        if (file_exists($key_path)) {
            $results['ssh_key_permissions'] = substr(sprintf('%o', fileperms($key_path)), -4);
            $results['ssh_key_owner'] = posix_getpwuid(fileowner($key_path))['name'] ?? '未知';
        }
        
        // 5. 测试SSH连接
        $test_command = "ssh -i " . escapeshellarg($key_path) . 
                        " -o StrictHostKeyChecking=no" .
                        " -o UserKnownHostsFile=/dev/null" .
                        " -o ConnectTimeout=5" .
                        " root@" . HOST_MACHINE_IP . 
                        " 'echo \"SSH连接测试成功\"' 2>&1";
        
        $results['ssh_test_command'] = $test_command;
        
        // 使用proc_open测试
        $descriptorspec = array(
            0 => array("pipe", "r"),
            1 => array("pipe", "w"),
            2 => array("pipe", "w")
        );
        
        $process = proc_open($test_command, $descriptorspec, $pipes, '/tmp');
        
        if (is_resource($process)) {
            fclose($pipes[0]);
            
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            
            $return_code = proc_close($process);
            
            $results['ssh_test_output'] = $output ?: '(无输出)';
            $results['ssh_test_error'] = $error ?: '(无错误)';
            $results['ssh_test_return_code'] = $return_code;
            $results['ssh_test_success'] = ($return_code === 0) ? '✅ SSH连接成功' : '❌ SSH连接失败';
        } else {
            $results['ssh_test_result'] = '❌ proc_open无法创建进程';
            $results['ssh_test_error'] = error_get_last()['message'] ?? '未知错误';
        }
        
        // 6. 检查容器用户
        $results['current_user'] = shell_exec('whoami');
        $results['php_user'] = get_current_user();
        $results['server_software'] = $_SERVER['SERVER_SOFTWARE'] ?? '未知';
        
        // 7. 检查禁用函数
        $disabled_functions = ini_get('disable_functions');
        $results['disabled_functions'] = $disabled_functions ?: '无';
        
        wp_send_json_success($results);
    }

    /**
     * 检查队列进程状态 - 简化版
     */
    private function check_queue_process_status_simple($queue_id, $pid, $output_file) {
        global $wpdb;
        $queue_table = $wpdb->prefix . 'pms_automation_queue';
        
        // 读取输出文件
        $output = '';
        if (file_exists($output_file)) {
            $output = file_get_contents($output_file);
        }
        
        // 判断任务状态
        $status = 'running';
        $progress = $this->estimate_progress($output);
        
        if (strpos($output, 'SUCCESS') !== false || 
            strpos($output, '部署完成') !== false || 
            strpos($output, 'Ai47网站 完整部署完成') !== false) {
            $status = 'completed';
            $progress = 100;
        } elseif (strpos($output, 'ERROR') !== false || 
                  strpos($output, '失败') !== false ||
                  strpos($output, 'error') !== false) {
            $status = 'failed';
        }
        
        // 如果进度大于99%且没有明显错误，认为完成
        if ($progress >= 99 && $status === 'running') {
            $status = 'completed';
            $progress = 100;
        }
        
        // 更新状态
        if ($status !== 'running') {
            $this->update_queue_status($queue_id, $status, $progress, $output);
            $this->log_debug("任务 {$queue_id} 状态更新为: {$status}, 进度: {$progress}%");
        } else {
            // 仍在运行，安排下次检查
            $wpdb->update(
                $queue_table,
                array('progress' => $progress, 'updated_at' => current_time('mysql')),
                array('id' => $queue_id),
                array('%d', '%s'),
                array('%d')
            );
            
            // 更新用户元数据
            $task = $wpdb->get_row($wpdb->prepare(
                "SELECT user_id, domain FROM $queue_table WHERE id = %d",
                $queue_id
            ));
            if ($task) {
                update_user_meta($task->user_id, '_pms_automation_progress', $progress);
                $this->update_user_automation_status($task->user_id, $task->domain, 'running', $progress);
            }
            
            // 安排下次检查（每30秒）
            wp_schedule_single_event(time() + 30, 'pms_automation_monitor', array($queue_id, $pid, $output_file, 'queue'));
        }
    }
    
    public function monitor_automation($id, $pid, $output_file, $type = 'queue') {
        if ($type === 'queue') {
            $this->check_queue_process_status_simple($id, $pid, $output_file);
        }
    }
    
    public function check_automation_progress() {
        if ($this->queue_enabled) {
            $this->process_automation_queue();
        }
    }

    private function estimate_progress($output) {
        if (strpos($output, '部署完成') !== false || strpos($output, 'Ai47网站 完整部署完成') !== false) {
            return 100;
        }
        if (strpos($output, '开始部署') !== false || strpos($output, '启动') !== false) {
            return 10;
        }
        if (strpos($output, '正在部署') !== false || strpos($output, '执行中') !== false) {
            return 50;
        }
        if (strpos($output, '配置完成') !== false || strpos($output, '设置完成') !== false) {
            return 80;
        }
        return 30;
    }

    public function handle_payment_completion($payment_id, $data) {
        if (function_exists('pms_get_payment')) {
            $payment = pms_get_payment($payment_id);
            if ($payment && ($payment->status == 'completed' || $data == 'completed')) {
                $this->mark_user_for_automation($payment->user_id, $payment_id);
            }
        }
    }
    
    public function check_payment_status_update($payment_id, $data, $old_data) {
        if (isset($data['status']) && $data['status'] === 'completed') {
            if (function_exists('pms_get_payment')) {
                $payment = pms_get_payment($payment_id);
                if ($payment) {
                    $this->mark_user_for_automation($payment->user_id, $payment_id);
                }
            }
        }
    }
    
    private function mark_user_for_automation($user_id, $payment_id) {
        update_user_meta($user_id, '_pms_has_paid', 'yes');
        update_user_meta($user_id, '_pms_needs_automation', '1');
        update_user_meta($user_id, '_pms_automation_status', 'domain_required');
        
        // 更新PMS状态表
        $this->update_user_automation_status($user_id, '', 'domain_required');
        
        // 发送通知邮件
        $this->send_automation_notification($user_id, $payment_id);
        
        $this->log_debug("用户 {$user_id} 已标记为需要自动化配置，支付ID: {$payment_id}");
    }
    
    /**
     * 发送自动化配置通知
     */
    private function send_automation_notification($user_id, $payment_id) {
        $user = get_userdata($user_id);
        if (!$user) return;
        
        $to = $user->user_email;
        $subject = __('您的Ai47网站自动化配置已准备就绪', 'pms-secupay-automation-core');
        
        $account_url = function_exists('pms_get_page_url') ? pms_get_page_url('account') : site_url('/account/');
        
        $message = sprintf(
            __('亲爱的 %s，<br><br>感谢您的支付！您的会员订阅已激活。<br><br>现在您可以开始自动化配置您的Ai47网站了，我们将自动完成Ai47安装并跳过安装页面。<br><br>请登录您的账户页面开始配置：<br><a href="%s">%s</a><br><br>此致，<br>%s', 'pms-secupay-automation-core'),
            $user->display_name,
            $account_url,
            $account_url,
            get_bloginfo('name')
        );
        
        $headers = array('Content-Type: text/html; charset=UTF-8');
        
        wp_mail($to, $subject, $message, $headers);
        
        $this->log_debug("已发送自动化通知邮件给用户 {$user_id}");
    }
    
    private function send_completion_email($user_id) {
        $user = get_userdata($user_id);
        if (!$user) return;
        
        $domain = get_user_meta($user_id, '_pms_automation_domain', true);
        $wp_password = get_user_meta($user_id, '_pms_wordpress_password', true);
        $wp_username = get_user_meta($user_id, '_pms_wp_username', true) ?: 'admin';
        $site_title = get_user_meta($user_id, '_pms_site_title', true) ?: 'My website';
        
        $to = $user->user_email;
        $subject = __('您的Ai47网站自动化配置已完成', 'pms-secupay-automation-core');
        
        $message = sprintf(
            __('亲爱的 %s，<br><br>您的Ai47网站自动化配置已成功完成！Ai47已自动安装并配置完成。<br><br>您的网站详情：<br>网站地址: https://%s<br>网站标题: %s<br>管理员用户名: %s<br>管理员密码: %s<br><br>现在您可以访问您的网站并开始使用了。<br>请使用以下信息登录Ai47后台：<br>用户名: %s<br>密码: %s<br>登录地址: https://%s/wp-admin<br><br>此致，<br>%s', 'pms-secupay-automation-core'),
            $user->display_name,
            esc_html($domain),
            esc_html($site_title),
            esc_html($wp_username),
            esc_html($wp_password),
            esc_html($wp_username),
            esc_html($wp_password),
            esc_html($domain),
            get_bloginfo('name')
        );
        
        $headers = array('Content-Type: text/html; charset=UTF-8');
        
        wp_mail($to, $subject, $message, $headers);
        
        $this->log_debug("已发送完成邮件给用户 {$user_id}");
    }

    public function add_admin_menu() {
        add_submenu_page(
            'pms-settings', 
            '自动化任务监控', 
            '自动化任务', 
            'manage_options', 
            'pms-automation-monitor', 
            array($this, 'render_admin_dashboard')
        );
    }

    public function render_admin_dashboard() {
        global $wpdb;
        $tasks = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}pms_automation_queue ORDER BY id DESC LIMIT 50");
        ?>
        <div class="wrap">
            <h1>自动化任务监控</h1>
            
            <div style="margin: 20px 0; background: #fff; padding: 20px; border: 1px solid #ddd;">
                <h2>系统状态</h2>
                <?php
                $running_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}pms_automation_queue WHERE status = 'running'");
                $queued_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}pms_automation_queue WHERE status = 'queued'");
                $completed_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}pms_automation_queue WHERE status = 'completed'");
                $failed_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}pms_automation_queue WHERE status = 'failed'");
                ?>
                <p>运行中: <strong><?php echo $running_count; ?></strong> | 排队中: <strong><?php echo $queued_count; ?></strong> | 已完成: <strong><?php echo $completed_count; ?></strong> | 失败: <strong><?php echo $failed_count; ?></strong></p>
                <p>最大并行数: <?php echo $this->max_concurrent; ?></p>
            </div>
            
            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>用户ID</th>
                        <th>域名</th>
                        <th>状态</th>
                        <th>进度</th>
                        <th>队列位置</th>
                        <th>创建时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($tasks)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center;">暂无任务</td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($tasks as $task): 
                        $user = get_userdata($task->user_id);
                        $user_name = $user ? $user->user_login : '用户#' . $task->user_id;
                    ?>
                    <tr>
                        <td><?php echo $task->id; ?></td>
                        <td>
                            <a href="<?php echo admin_url('user-edit.php?user_id=' . $task->user_id); ?>" target="_blank">
                                <?php echo esc_html($user_name); ?>
                            </a>
                        </td>
                        <td><?php echo esc_html($task->domain); ?></td>
                        <td>
                            <span class="status-badge status-<?php echo $task->status; ?>">
                                <?php echo $this->get_status_text($task->status); ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($task->status === 'running'): ?>
                                <div style="width: 100px; background: #e0e0e0; border-radius: 10px; height: 20px;">
                                    <div style="width: <?php echo $task->progress; ?>%; background: #4CAF50; height: 100%; border-radius: 10px; text-align: center; color: #fff; font-size: 12px; line-height: 20px;">
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
                            <button class="button pms-view-log" data-task-id="<?php echo $task->id; ?>">查看日志</button>
                            <?php if ($task->status === 'running'): ?>
                                <button class="button button-secondary pms-cancel-task" data-task-id="<?php echo $task->id; ?>">取消</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <div id="pms-admin-live-log" style="background:#000;color:#0f0;padding:10px;height:300px;overflow:auto;margin-top:20px;display:none;">
                <div id="log-content"></div>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('.pms-view-log').click(function(){
                var id = $(this).data('task-id');
                $('#pms-admin-live-log').show();
                $('#log-content').html('加载中...');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'pms_admin_get_task_log',
                        task_id: id,
                        nonce: '<?php echo wp_create_nonce('pms_automation_nonce'); ?>'
                    },
                    success: function(res) {
                        if (res.success) {
                            $('#log-content').html('<pre>' + res.data.output + '</pre>');
                        } else {
                            $('#log-content').html('加载失败: ' + res.data.message);
                        }
                    }
                });
            });
            
            $('.pms-cancel-task').click(function(){
                if (!confirm('确定要取消此任务吗？')) return;
                
                var id = $(this).data('task-id');
                var $button = $(this);
                $button.prop('disabled', true).text('取消中...');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'pms_cancel_automation',
                        task_id: id,
                        nonce: '<?php echo wp_create_nonce('pms_automation_nonce'); ?>'
                    },
                    success: function(res) {
                        if (res.success) {
                            alert('任务已取消');
                            location.reload();
                        } else {
                            alert('取消失败: ' + res.data.message);
                            $button.prop('disabled', false).text('取消');
                        }
                    }
                });
            });
        });
        </script>
        
        <style>
        .status-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: bold;
        }
        .status-pending { background: #ff9800; color: #fff; }
        .status-queued { background: #2196f3; color: #fff; }
        .status-running { background: #4caf50; color: #fff; }
        .status-completed { background: #8bc34a; color: #fff; }
        .status-failed { background: #f44336; color: #fff; }
        </style>
        <?php
    }

    public function add_cron_interval($schedules) {
        $schedules['every_minute'] = array('interval' => 60, 'display' => '每分钟');
        $schedules['every_30_seconds'] = array('interval' => 30, 'display' => '每30秒');
        return $schedules;
    }
    
    public function create_directories() {
        $dirs = array(
            PMS_AUTOMATION_CORE_PATH . 'logs', 
            PMS_AUTOMATION_CORE_PATH . 'tmp', 
            PMS_AUTOMATION_CORE_PATH . 'assets/css', 
            PMS_AUTOMATION_CORE_PATH . 'assets/js'
        );
        foreach ($dirs as $dir) {
            if (!file_exists($dir)) {
                wp_mkdir_p($dir);
            }
        }
    }
    
    private function get_output_file_path($user_id) {
        $log_dir = PMS_AUTOMATION_CORE_PATH . 'logs';
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
        }
        return $log_dir . '/output_' . $user_id . '_' . time() . '.log';
    }

    /**
     * 修复：确保脚本正确加载
     */
    public function enqueue_scripts() {
        // 只在特定页面加载脚本
        global $post;
        
        $load_scripts = false;
        
        // 检查是否为PMS账户页面
        if (function_exists('pms_is_account_page') && pms_is_account_page()) {
            $load_scripts = true;
        }
        
        // 检查页面内容中是否有相关短代码
        if (is_a($post, 'WP_Post')) {
            $post_content = $post->post_content;
            if (has_shortcode($post_content, 'pms_automation_dashboard') || 
                has_shortcode($post_content, 'pms-account') ||
                strpos($post_content, '[pms_automation_dashboard') !== false ||
                strpos($post_content, '[pms-account') !== false) {
                $load_scripts = true;
            }
        }
        
        if ($load_scripts) {
            // 加载jQuery（确保已加载）
            wp_enqueue_script('jquery');
            
            // 创建或检查脚本文件
            $js_file = PMS_AUTOMATION_CORE_PATH . 'assets/js/script.js';
            if (!file_exists($js_file)) {
                $js_dir = dirname($js_file);
                if (!file_exists($js_dir)) {
                    wp_mkdir_p($js_dir);
                }
                // 创建基本的JS文件
                $js_content = '// PMS Automation Script
jQuery(document).ready(function($) {
    console.log("PMS Automation script loaded");
});';
                file_put_contents($js_file, $js_content);
            }
            
            // 加载脚本
            wp_enqueue_script(
                'pms-automation-script',
                PMS_AUTOMATION_CORE_URL . 'assets/js/script.js',
                array('jquery'),
                PMS_AUTOMATION_CORE_VERSION,
                true
            );
            
            // 传递AJAX参数
            wp_localize_script('pms-automation-script', 'pmsAutomation', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('pms_automation_nonce'),
                'domain_nonce' => wp_create_nonce('pms_save_domain')
            ));
            
            // 加载CSS
            $css_file = PMS_AUTOMATION_CORE_PATH . 'assets/css/style.css';
            if (!file_exists($css_file)) {
                $css_dir = dirname($css_file);
                if (!file_exists($css_dir)) {
                    wp_mkdir_p($css_dir);
                }
                // 创建基本的CSS文件
                $css_content = '/* PMS Automation Styles */
.pms-automation-section { margin: 20px 0; }
.status-badge { padding: 3px 10px; border-radius: 15px; font-size: 12px; font-weight: bold; }
.pms-button { cursor: pointer; }
.progress-bar { background: #e0e0e0; border-radius: 10px; overflow: hidden; }';
                file_put_contents($css_file, $css_content);
            }
            
            wp_enqueue_style(
                'pms-automation-style',
                PMS_AUTOMATION_CORE_URL . 'assets/css/style.css',
                array(),
                PMS_AUTOMATION_CORE_VERSION
            );
        }
    }
    
    public function enqueue_admin_scripts($hook) {
        if ($hook === 'paid-member-subscriptions_page_pms-automation-monitor') {
            wp_enqueue_style('pms-automation-admin-style', PMS_AUTOMATION_CORE_URL . 'assets/css/admin.css', array(), PMS_AUTOMATION_CORE_VERSION);
        }
    }
    
    public function add_automation_to_account($content, $args) {
        return $content . $this->render_automation_dashboard();
    }
    
    public function display_automation_content_directly() {
        if (is_user_logged_in() && function_exists('pms_is_account_page') && pms_is_account_page()) {
            echo $this->render_automation_dashboard();
        }
    }
    
    public function register_settings() {
        register_setting('pms_automation_settings', 'pms_automation_settings');
        
        add_settings_section(
            'pms_automation_general',
            '常规设置',
            array($this, 'settings_section_callback'),
            'pms_automation_settings'
        );
        
        add_settings_field(
            'debug_mode',
            '调试模式',
            array($this, 'debug_mode_field_callback'),
            'pms_automation_settings',
            'pms_automation_general'
        );
    }
    
    public function settings_section_callback() {
        echo '<p>配置自动化部署插件的常规设置。</p>';
    }
    
    public function debug_mode_field_callback() {
        $options = get_option('pms_automation_settings');
        $debug_mode = isset($options['debug_mode']) ? $options['debug_mode'] : '0';
        echo '<input type="checkbox" name="pms_automation_settings[debug_mode]" value="1" ' . checked(1, $debug_mode, false) . ' /> 启用调试模式';
    }
    
    public function handle_admin_actions() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        if (isset($_GET['action']) && $_GET['action'] === 'pms_retry_task' && isset($_GET['task_id'])) {
            $task_id = intval($_GET['task_id']);
            $this->retry_task($task_id);
            wp_redirect(admin_url('admin.php?page=pms-automation-monitor&retried=1'));
            exit;
        }
    }
    
    private function retry_task($task_id) {
        global $wpdb;
        $queue_table = $wpdb->prefix . 'pms_automation_queue';
        
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
                    'updated_at' => current_time('mysql')
                ),
                array('id' => $task_id),
                array('%s', '%d', '%s', '%s', '%s', '%s'),
                array('%d')
            );
            
            update_user_meta($task->user_id, '_pms_automation_status', 'queued');
            update_user_meta($task->user_id, '_pms_automation_progress', 0);
            delete_user_meta($task->user_id, '_pms_automation_error');
            
            $this->update_user_automation_status($task->user_id, $task->domain, 'queued', 0);
            
            $this->log_debug("任务 {$task_id} 已重新加入队列");
        }
    }
    
    public function ajax_get_realtime_log() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'pms_automation_nonce')) {
            wp_send_json_error(array('message' => '安全验证失败'));
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => '权限不足'));
        }
        
        $task_id = isset($_POST['task_id']) ? intval($_POST['task_id']) : 0;
        
        if (!$task_id) {
            wp_send_json_error(array('message' => '无效的任务ID'));
        }
        
        global $wpdb;
        $queue_table = $wpdb->prefix . 'pms_automation_queue';
        $task = $wpdb->get_row($wpdb->prepare(
            "SELECT output_file FROM $queue_table WHERE id = %d",
            $task_id
        ));
        
        if ($task && file_exists($task->output_file)) {
            $content = file_get_contents($task->output_file);
            $lines = explode("\n", $content);
            $lines = array_slice($lines, -50);
            wp_send_json_success(array('output' => implode("\n", $lines)));
        } else {
            wp_send_json_success(array('output' => '等待日志文件生成...'));
        }
    }
    
    public function ajax_execute_ssh_like_command() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'pms_automation_nonce')) {
            wp_send_json_error(array('message' => '安全验证失败'));
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => '权限不足'));
        }
        
        $command = isset($_POST['command']) ? sanitize_text_field($_POST['command']) : '';
        if (!$command) {
            wp_send_json_error(array('message' => '请输入命令'));
        }
        
        // 安全过滤
        $allowed_commands = array('tail', 'cat', 'ps', 'grep', 'docker', 'ls', 'pwd', 'whoami');
        $command_parts = explode(' ', $command);
        $base_command = $command_parts[0];
        
        if (!in_array($base_command, $allowed_commands)) {
            wp_send_json_error(array('message' => '命令不被允许'));
        }
        
        $result = shell_exec($command . ' 2>&1');
        wp_send_json_success(array('output' => $result ?: '(无输出)'));
    }
    
    public function ajax_test_script_execution() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'pms_automation_nonce')) {
            wp_send_json_error(array('message' => '安全验证失败'));
        }
        
        if ($this->admin_only_debug && !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => '权限不足'));
        }
        
        $script_path = PMS_AUTOMATION_SCRIPT_PATH;
        
        $test_commands = array(
            'whoami' => shell_exec('whoami'),
            'pwd' => shell_exec('pwd'),
            'script_exists' => file_exists($script_path) ? '是' : '否',
            'script_permissions' => file_exists($script_path) ? substr(sprintf('%o', fileperms($script_path)), -4) : '文件不存在',
            'is_executable' => is_executable($script_path) ? '是' : '否',
            'test_command' => shell_exec('echo "test"'),
            'php_version' => phpversion(),
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? '未知'
        );
        
        wp_send_json_success($test_commands);
    }
    
    public function ajax_test_sudo_access() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'pms_automation_nonce')) {
            wp_send_json_error(array('message' => '安全验证失败'));
        }
        
        if ($this->admin_only_debug && !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => '权限不足'));
        }
        
        $current_user = trim(shell_exec('whoami'));
        $sudo_enabled = false;
        
        if ($current_user === 'root') {
            $sudo_enabled = true;
            $message = '✅ 当前以root身份运行，无需sudo。';
        } else {
            $test_sudo = shell_exec('sudo -n echo "test" 2>&1');
            if ($test_sudo && trim($test_sudo) === 'test') {
                $sudo_enabled = true;
                $message = '✅ Sudo访问已配置。';
            } else {
                $message = '⚠️ Sudo访问未配置，将使用当前用户权限执行。';
            }
        }
        
        wp_send_json_success(array(
            'message' => $message,
            'sudo_enabled' => $sudo_enabled,
            'current_user' => $current_user
        ));
    }
    
    public function ajax_debug_system() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'pms_automation_nonce')) {
            wp_send_json_error(array('message' => '安全验证失败'));
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => '权限不足'));
        }
        
        $script_path = PMS_AUTOMATION_SCRIPT_PATH;
        
        $debug_info = array(
            'server' => array(
                'php_version' => phpversion(),
                'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? '未知',
                'server_name' => $_SERVER['SERVER_NAME'] ?? '未知',
                'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? '未知',
            ),
            'wordpress' => array(
                'wp_version' => get_bloginfo('version'),
                'wp_url' => get_bloginfo('url'),
                'wp_directory' => ABSPATH,
            ),
            'plugin' => array(
                'plugin_path' => PMS_AUTOMATION_CORE_PATH,
                'script_path' => $script_path,
                'script_exists' => file_exists($script_path) ? '是' : '否',
                'script_executable' => is_executable($script_path) ? '是' : '否',
            ),
            'functions' => array(
                'shell_exec' => function_exists('shell_exec') ? '可用' : '禁用',
                'exec' => function_exists('exec') ? '可用' : '禁用',
                'proc_open' => function_exists('proc_open') ? '可用' : '禁用',
            ),
        );
        
        wp_send_json_success($debug_info);
    }
    
    public function ajax_get_queue_status() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'pms_automation_nonce')) {
            wp_send_json_error(array('message' => '安全验证失败'));
        }
        
        global $wpdb;
        $queue_table = $wpdb->prefix . 'pms_automation_queue';
        
        $running = $wpdb->get_var("SELECT COUNT(*) FROM $queue_table WHERE status = 'running'");
        $queued = $wpdb->get_var("SELECT COUNT(*) FROM $queue_table WHERE status = 'queued'");
        $completed = $wpdb->get_var("SELECT COUNT(*) FROM $queue_table WHERE status = 'completed'");
        $failed = $wpdb->get_var("SELECT COUNT(*) FROM $queue_table WHERE status = 'failed'");
        
        wp_send_json_success(array(
            'running' => $running,
            'queued' => $queued,
            'completed' => $completed,
            'failed' => $failed
        ));
    }
    
    public function ajax_get_queue_position() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'pms_automation_nonce')) {
            wp_send_json_error(array('message' => '安全验证失败'));
        }
        
        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(array('message' => '用户未登录'));
        }
        
        $position = $this->get_user_queue_position($user_id);
        $estimated_wait = $this->calculate_estimated_wait_time($position);
        
        wp_send_json_success(array(
            'position' => $position,
            'estimated_wait_minutes' => ceil($estimated_wait / 60)
        ));
    }
    
    public function ajax_start_automation() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'pms_automation_nonce')) {
            wp_send_json_error(array('message' => '安全验证失败'));
        }
        
        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(array('message' => '用户未登录'));
        }
        
        $domain = get_user_meta($user_id, '_pms_automation_domain', true);
        if (!$domain) {
            wp_send_json_error(array('message' => '请先设置域名'));
        }
        
        $wp_password = wp_generate_password(12, true, true);
        $queue_id = $this->add_to_queue($user_id, $domain, $wp_password);
        
        if ($queue_id) {
            wp_send_json_success(array(
                'message' => '自动化任务已启动',
                'queue_id' => $queue_id
            ));
        } else {
            wp_send_json_error(array('message' => '启动任务失败'));
        }
    }
    
    public function ajax_cancel_automation() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'pms_automation_nonce')) {
            wp_send_json_error(array('message' => '安全验证失败'));
        }
        
        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(array('message' => '用户未登录'));
        }
        
        global $wpdb;
        $queue_table = $wpdb->prefix . 'pms_automation_queue';
        
        // 查找用户当前的任务
        $task = $wpdb->get_row($wpdb->prepare(
            "SELECT id, pid FROM $queue_table WHERE user_id = %d AND status IN ('queued', 'running') ORDER BY id DESC LIMIT 1",
            $user_id
        ));
        
        if ($task) {
            // 如果是运行中的任务，尝试终止进程
            if ($task->pid) {
                $this->kill_process_with_sudo($task->pid);
            }
            
            // 更新任务状态为取消
            $wpdb->update(
                $queue_table,
                array(
                    'status' => 'cancelled',
                    'updated_at' => current_time('mysql')
                ),
                array('id' => $task->id),
                array('%s', '%s'),
                array('%d')
            );
            
            // 更新用户元数据
            update_user_meta($task->user_id, '_pms_automation_status', 'cancelled');
            $this->update_user_automation_status($task->user_id, '', 'cancelled', 0);
            
            wp_send_json_success(array('message' => '自动化任务已取消'));
        } else {
            wp_send_json_error(array('message' => '未找到运行中的任务'));
        }
    }
}

function pms_automation_core_deactivation() {
    wp_clear_scheduled_hook('pms_automation_cron');
    wp_clear_scheduled_hook('pms_automation_queue_cron');
    wp_clear_scheduled_hook('pms_automation_check_missing_payments');
}

register_deactivation_hook(__FILE__, 'pms_automation_core_deactivation');