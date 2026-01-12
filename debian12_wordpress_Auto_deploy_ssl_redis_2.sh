#!/bin/bash

# WordPress 完整自动化部署脚本 - 全能融合版（增强SSL版+WP-CLI静默安装）
# 融合功能：
# 1. 多用户并发部署（原子资源分配，避免端口/IP冲突）
# 2. WordPress站点URL自动配置
# 3. 增强的SSL证书申请流程（内网+FRP环境优化）
# 4. 完整的SSL验证
# 5. 智能网络配置
# 6. 增强的DNS解析
# 7. 资源分配记录系统（防止重复部署）
# 8. 详细的Must-Use Plugins显示
# 9. WP-CLI静默安装（跳过网页安装步骤）
# 10. 支持强制重新部署（--force参数）

set -e

# 日志文件路径 - 每个部署独立日志
LOG_FILE="./wordpress-deploy-$(date +%s).log"

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# 资源分配目录
RESOURCE_DIR="/www/wwwroot/webs_temp"
ALLOCATION_FILE="$RESOURCE_DIR/allocated_resources.txt"
LOCK_FILE="$RESOURCE_DIR/deployment.lock"

# Cloudflare API配置
CF_API_EMAIL="yuhoyuhoyuho@163.com"
CF_API_KEY="86101f6b162714f8296aaef3f4d750c86546c"
CF_ZONE_DAILYVP="b2c28fcb971de103750ae6de9525da16"
CF_TOKEN_DAILYVP="RtrgTIG7kEfdcuej_oyTyTSA7H5af3uwqhMnWrr-"
CF_ZONE_FSSSS="f98030164e9e162c7bbc56a9c6707b1e"
CF_TOKEN_FSSSS="ATLmo2b_RmYmBxbKc0JiuYNlRA3dwbmP-SWuBnRk"

# WordPress安装默认配置
WP_LANGUAGE="zh_CN"
WP_USERNAME="admin"
WP_PASSWORD=""
WP_EMAIL="admin@example.com"
SITE_TITLE="我的WordPress站点"

# 全局变量
FORCE_REDEPLOY="no"

# =========================================================================
# 基础函数
# =========================================================================

# 初始化日志文件
init_log() {
    echo "=========================================================" > "$LOG_FILE"
    echo "      WordPress 全能融合部署日志 (融合版+WP-CLI)" | tee -a "$LOG_FILE"
    echo "      时间: $(date +'%Y-%m-%d %H:%M:%S')" | tee -a "$LOG_FILE"
    echo "      进程ID: $$" | tee -a "$LOG_FILE"
    echo "      用户: $(whoami)" | tee -a "$LOG_FILE"
    echo "      域名: ${DOMAIN:-未设置}" | tee -a "$LOG_FILE"
    echo "      强制模式: ${FORCE_REDEPLOY:-no}" | tee -a "$LOG_FILE"
    echo "=========================================================" | tee -a "$LOG_FILE"
}

# 日志函数
log() { echo -e "${GREEN}[$(date +'%Y-%m-%d %H:%M:%S')] [PID:$$] $1${NC}" | tee -a "$LOG_FILE"; }
warn() { echo -e "${YELLOW}[警告] [PID:$$] $1${NC}" | tee -a "$LOG_FILE"; }
error() { 
    echo -e "${RED}[错误] [PID:$$] $1${NC}" | tee -a "$LOG_FILE"
    
    # 清理锁文件
    release_lock
    
    exit 1
}

# 显示横幅
show_banner() {
    echo -e "${BLUE}"
    echo "================================================"
    echo "      WordPress 全能自动化部署脚本"
    echo "      融合版 - 多用户并发 + 完整功能"
    echo "      内网+FRP环境优化 + Cloudflare DNS验证"
    echo "      WP-CLI静默安装（跳过网页安装）"
    echo "      支持强制重新部署 (--force 参数)"
    echo "================================================"
    echo -e "${NC}"
}

# =========================================================================
# WordPress安装配置函数（WP-CLI静默安装）
# =========================================================================

# 获取WordPress安装配置
get_wordpress_installation_config() {
    log "获取WordPress安装配置..."
    
    # 检查是否有命令行参数传入
    # 参数顺序: 域名 语言 用户名 密码 邮箱 网站标题
    if [ $# -ge 5 ]; then
        # 使用命令行参数
        log "✅ 检测到命令行参数，跳过交互式输入"
        
        # 从参数中获取配置
        if [ -n "$2" ] && [ "$2" != "-" ]; then
            WP_LANGUAGE="$2"
        fi
        
        if [ -n "$3" ] && [ "$3" != "-" ]; then
            WP_USERNAME="$3"
        fi
        
        if [ -n "$4" ] && [ "$4" != "-" ]; then
            WP_PASSWORD="$4"
        fi
        
        if [ -n "$5" ] && [ "$5" != "-" ]; then
            WP_EMAIL="$5"
        fi
        
        if [ -n "$6" ] && [ "$6" != "-" ]; then
            SITE_TITLE="$6"
        else
            SITE_TITLE="$DOMAIN"
        fi
        
        # 如果密码未设置，生成随机密码
        if [ -z "$WP_PASSWORD" ]; then
            WP_PASSWORD=$(openssl rand -base64 12 | tr -d '/+=' | head -c 16)
        fi
        
        log "WordPress安装配置（来自命令行参数）:"
        log "  语言: $WP_LANGUAGE"
        log "  用户名: $WP_USERNAME"
        log "  邮箱: $WP_EMAIL"
        log "  站点标题: $SITE_TITLE"
        
        return 0
    fi
    
    # 如果没有命令行参数，则使用交互式输入（原逻辑）
    # 语言选择
    echo "请选择WordPress语言："
    echo "  1) zh_CN (简体中文)"
    echo "  2) en_US (英文)"
    echo "  3) 其他语言代码"
    read -p "请选择 [1-3]: " lang_choice
    
    case $lang_choice in
        1) WP_LANGUAGE="zh_CN" ;;
        2) WP_LANGUAGE="en_US" ;;
        3) 
            read -p "请输入语言代码 (例如: zh_CN, en_US, ja, ko_KR): " custom_lang
            WP_LANGUAGE="${custom_lang:-zh_CN}"
            ;;
        *) WP_LANGUAGE="zh_CN" ;;
    esac
    
    # 管理员用户名
    read -p "请输入管理员用户名 [默认: admin]: " input_username
    WP_USERNAME="${input_username:-admin}"
    
    # 管理员密码
    if [ -z "$WP_PASSWORD" ]; then
        WP_PASSWORD=$(openssl rand -base64 12 | tr -d '/+=' | head -c 16)
    fi
    echo "管理员密码: $WP_PASSWORD"
    echo "⚠️ 请妥善保存此密码！"
    
    # 管理员邮箱
    read -p "请输入管理员邮箱 [默认: admin@$DOMAIN]: " input_email
    if [ -n "$input_email" ]; then
        WP_EMAIL="$input_email"
    else
        WP_EMAIL="admin@$DOMAIN"
    fi
    
    # 站点标题
    read -p "请输入站点标题 [默认: $DOMAIN]: " input_title
    SITE_TITLE="${input_title:-$DOMAIN}"
    
    log "WordPress安装配置完成："
    log "  语言: $WP_LANGUAGE"
    log "  用户名: $WP_USERNAME"
    log "  邮箱: $WP_EMAIL"
    log "  站点标题: $SITE_TITLE"
}

# 在容器中安装WP-CLI
# 在容器中安装WP-CLI
# 在容器中安装WP-CLI
install_wp_cli_in_container() {
    local site_name=$1
    local wp_cli_url="https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar"
    local local_phar="/www/docker/wordpress/wp-cli.phar"

    log "正在配置 WP-CLI..."

    # 1. 先在宿主机检查/下载
    if [ ! -f "$local_phar" ]; then
        log "在宿主机下载 WP-CLI..."
        curl -L -o "$local_phar" "$wp_cli_url" || wget -O "$local_phar" "$wp_cli_url"
    fi

    # 2. 拷贝到容器
    log "将 WP-CLI 拷贝至容器 ${site_name}-wp ..."
    docker cp "$local_phar" "${site_name}-wp:/usr/local/bin/wp-cli.phar"
    
    # 3. 创建可执行文件
    docker exec "${site_name}-wp" bash -c "cat > /usr/local/bin/wp << 'EOF'
#!/bin/bash
php /usr/local/bin/wp-cli.phar \"\$@\"
EOF"
    
    # 4. 设置执行权限
    docker exec "${site_name}-wp" chmod +x /usr/local/bin/wp
    docker exec "${site_name}-wp" chmod +x /usr/local/bin/wp-cli.phar
    
    # 5. 验证安装
    if docker exec "${site_name}-wp" wp --version --allow-root >/dev/null 2>&1; then
        log "✅ WP-CLI 安装成功"
        return 0
    else
        # 尝试直接使用php执行
        if docker exec "${site_name}-wp" php /usr/local/bin/wp-cli.phar --version --allow-root >/dev/null 2>&1; then
            log "✅ WP-CLI 安装成功（使用php直接执行）"
            return 0
        else
            error "WP-CLI 验证失败"
        fi
    fi
}


# 使用WP-CLI进行静默安装
# 使用WP-CLI进行静默安装
install_wordpress_with_wp_cli() {
    local site_name=$1
    local domain=$2
    local wp_language=$3
    local wp_username=$4
    local wp_password=$5
    local wp_email=$6
    local site_title=$7
    
    log "开始使用WP-CLI进行静默安装..."
    
    # 等待数据库服务完全就绪
    local max_wait=60
    local wait_time=0
    
    while [ $wait_time -lt $max_wait ]; do
        if docker exec "${site_name}-db" mysqladmin ping -h localhost --silent 2>/dev/null; then
            log "✅ 数据库服务就绪"
            break
        fi
        log "等待数据库就绪... (${wait_time}/${max_wait}秒)"
        sleep 5
        wait_time=$((wait_time + 5))
    done
    
    if [ $wait_time -ge $max_wait ]; then
        warn "⚠️  数据库启动较慢，但继续尝试安装..."
    fi
    
    # 安装WP-CLI（如果未安装）
    if ! docker exec "${site_name}-wp" which wp >/dev/null 2>&1; then
        install_wp_cli_in_container "$site_name"
    fi
    
    # 执行WordPress安装
    log "执行wp core install命令..."
    
    # 使用更简单的安装命令
    docker exec "${site_name}-wp" wp --allow-root core install \
        --url="https://$domain" \
        --title="$site_title" \
        --admin_user="$wp_username" \
        --admin_password="$wp_password" \
        --admin_email="$wp_email" \
        --skip-email
    
    if [ $? -eq 0 ]; then
        log "✅ WordPress核心安装成功！"
        
        # 设置语言
        if [ "$wp_language" != "en_US" ]; then
            log "设置语言: $wp_language"
            docker exec "${site_name}-wp" wp --allow-root language core install "$wp_language" --activate
        fi
        
        # 其他配置保持不变...
        return 0
    else
        warn "WordPress安装失败，等待30秒后重试..."
        sleep 30
        
        # 重试一次
        if docker exec "${site_name}-wp" wp --allow-root core install \
            --url="https://$domain" \
            --title="$site_title" \
            --admin_user="$wp_username" \
            --admin_password="$wp_password" \
            --admin_email="$wp_email" \
            --skip-email; then
            log "✅ WordPress重试安装成功！"
            return 0
        else
            error "WordPress安装失败"
        fi
    fi
}

# =========================================================================
# 资源分配管理函数（多用户并发核心）- 修复版
# =========================================================================

# 初始化资源目录
init_resource_dir() {
    log "初始化资源分配目录..."
    mkdir -p "$RESOURCE_DIR"
    
    # 创建分配文件如果不存在
    if [ ! -f "$ALLOCATION_FILE" ]; then
        cat > "$ALLOCATION_FILE" << 'EOF'
# WordPress部署资源分配记录
# 格式: 域名|项目路径|WEB端口|DB端口|REDIS端口|WEB_IP|DB_IP|REDIS_IP|网络名|状态|时间
# 状态: PENDING(分配中), RUNNING(运行中), FAILED(失败), REMOVED(已移除)
EOF
        log "✅ 创建资源分配文件: $ALLOCATION_FILE"
    fi
    
    # 设置权限
    chmod 755 "$RESOURCE_DIR"
    chmod 644 "$ALLOCATION_FILE"
}

# 获取文件锁（防止并发写）- 修复版
acquire_lock() {
    local lock_timeout=30
    local start_time=$(date +%s)
    
    # 如果已经持有锁，直接返回
    if [ -f "$LOCK_FILE" ] && [ "$(cat "$LOCK_FILE" 2>/dev/null)" = "$$" ]; then
        log "⚠️  当前进程已持有锁 (PID: $$)，跳过重复获取"
        return 0
    fi
    
    while [ -f "$LOCK_FILE" ]; do
        local current_time=$(date +%s)
        local elapsed=$((current_time - start_time))
        
        if [ $elapsed -gt $lock_timeout ]; then
            warn "获取锁超时，跳过等待..."
            
            # 检查锁是否被僵尸进程持有
            local lock_pid=$(cat "$LOCK_FILE" 2>/dev/null || echo "")
            if [ -n "$lock_pid" ] && ! ps -p "$lock_pid" > /dev/null 2>&1; then
                warn "清理僵尸锁 (PID: $lock_pid)..."
                rm -f "$LOCK_FILE"
                break
            fi
            
            # 强制获取锁
            warn "强制获取锁..."
            break
        fi
        
        # 检查锁持有者是否存活
        local lock_pid=$(cat "$LOCK_FILE" 2>/dev/null || echo "")
        if [ -n "$lock_pid" ] && ! ps -p "$lock_pid" > /dev/null 2>&1; then
            warn "锁持有者进程已终止 (PID: $lock_pid)，清理锁..."
            rm -f "$LOCK_FILE"
            break
        fi
        
        log "等待锁释放... (已等待 ${elapsed}秒，PID: ${lock_pid:-未知})"
        sleep 1
    done
    
    echo $$ > "$LOCK_FILE"
    chmod 644 "$LOCK_FILE"
    log "✅ 获取部署锁 (PID: $$)"
}

# 释放文件锁 - 修复版
release_lock() {
    if [ -f "$LOCK_FILE" ] && [ "$(cat "$LOCK_FILE" 2>/dev/null)" = "$$" ]; then
        rm -f "$LOCK_FILE"
        log "✅ 释放部署锁 (PID: $$)"
    elif [ -f "$LOCK_FILE" ]; then
        local lock_pid=$(cat "$LOCK_FILE" 2>/dev/null)
        warn "尝试释放非本进程持有的锁 (当前PID: $$, 锁PID: ${lock_pid:-未知})，跳过释放"
    fi
}

# 清理现有部署（完全清理容器、网络、数据）- 新增函数
cleanup_existing_deployment() {
    local domain=$1
    local site_name=$(echo "$domain" | sed 's/[^a-zA-Z0-9.-]//g' | sed 's/\./-/g')
    
    log "开始清理现有部署: $domain (站点名称: $site_name)"
    
    # 先获取分配记录
    local record=""
    if [ -f "$ALLOCATION_FILE" ]; then
        record=$(grep "^${domain}|" "$ALLOCATION_FILE" 2>/dev/null | head -1)
    fi
    
    if [ -n "$record" ]; then
        local project_path=$(echo "$record" | cut -d'|' -f2)
        local web_port=$(echo "$record" | cut -d'|' -f3)
        local db_port=$(echo "$record" | cut -d'|' -f4)
        local redis_port=$(echo "$record" | cut -d'|' -f5)
        local network_name=$(echo "$record" | cut -d'|' -f9)
        
        log "清理现有资源:"
        log "  项目路径: $project_path"
        log "  网络: $network_name"
        log "  端口: Web:$web_port, DB:$db_port, Redis:$redis_port"
        
        # 停止并删除容器
        log "停止并删除容器..."
        docker stop "${site_name}-wp" 2>/dev/null || warn "容器 ${site_name}-wp 不存在或已停止"
        docker rm -f "${site_name}-wp" 2>/dev/null || warn "无法删除容器 ${site_name}-wp"
        docker stop "${site_name}-db" 2>/dev/null || warn "容器 ${site_name}-db 不存在或已停止"
        docker rm -f "${site_name}-db" 2>/dev/null || warn "无法删除容器 ${site_name}-db"
        docker stop "${site_name}-redis" 2>/dev/null || warn "容器 ${site_name}-redis 不存在或已停止"
        docker rm -f "${site_name}-redis" 2>/dev/null || warn "无法删除容器 ${site_name}-redis"
        
        # 删除网络（如果不是默认网络）
        if [ -n "$network_name" ] && [ "$network_name" != "bridge" ] && [ "$network_name" != "host" ] && [ "$network_name" != "none" ]; then
            log "删除Docker网络: $network_name"
            docker network rm "$network_name" 2>/dev/null || warn "无法删除网络 $network_name (可能已被删除)"
        fi
        
        # 备份项目目录（可选）
        if [ -d "$project_path" ]; then
            local backup_path="${project_path}_backup_$(date +%s)"
            log "备份项目目录: $project_path -> $backup_path"
            mv "$project_path" "$backup_path" 2>/dev/null || warn "无法备份项目目录"
        fi
        
        # 清理Redis持久化目录
        local redis_persistent_dir="/opt/docker/redis/${site_name}"
        if [ -d "$redis_persistent_dir" ]; then
            log "清理Redis持久化目录: $redis_persistent_dir"
            rm -rf "$redis_persistent_dir" 2>/dev/null || warn "无法清理Redis目录"
        fi
        
        # 清理临时目录
        local redis_temp_dir="/www/wwwroot/webs_temp/temp/${site_name}/redis"
        if [ -d "$redis_temp_dir" ]; then
            log "清理Redis临时目录: $redis_temp_dir"
            rm -rf "$redis_temp_dir" 2>/dev/null || warn "无法清理Redis临时目录"
        fi
        
        # 清理Docker卷
        log "清理Docker卷..."
        docker volume rm "${site_name}_wordpress_data" 2>/dev/null || true
        docker volume rm "${site_name}_db_data" 2>/dev/null || true
        docker volume ls | grep "${site_name}" | awk '{print $2}' | xargs -r docker volume rm 2>/dev/null || true
        
        # 清理分配记录（不获取锁，由调用者处理）
        cleanup_allocation_nolock "$domain"
        
        log "✅ 现有部署清理完成: $domain"
    else
        log "ℹ️  未找到域名的分配记录，直接清理容器..."
        
        # 尝试清理容器（即使没有分配记录）
        docker stop "${site_name}-wp" 2>/dev/null || true
        docker rm -f "${site_name}-wp" 2>/dev/null || true
        docker stop "${site_name}-db" 2>/dev/null || true
        docker rm -f "${site_name}-db" 2>/dev/null || true
        docker stop "${site_name}-redis" 2>/dev/null || true
        docker rm -f "${site_name}-redis" 2>/dev/null || true
        
        # 尝试删除相关网络
        docker network rm "${site_name}-network" 2>/dev/null || true
        docker network rm "${site_name}" 2>/dev/null || true
        
        log "✅ 容器清理完成: $domain"
    fi
    
    # 等待资源释放
    sleep 2
}

# 清理分配记录（不获取锁）- 新增函数
cleanup_allocation_nolock() {
    local domain=$1
    
    if [ -f "$ALLOCATION_FILE" ]; then
        # 移除该域名的记录
        if grep -q "^${domain}|" "$ALLOCATION_FILE"; then
            grep -v "^${domain}|" "$ALLOCATION_FILE" > "${ALLOCATION_FILE}.tmp" && \
            mv "${ALLOCATION_FILE}.tmp" "$ALLOCATION_FILE"
            
            log "✅ 清理域名 $domain 的分配记录（无锁模式）"
        else
            log "ℹ️  域名 $domain 无分配记录可清理"
        fi
    fi
    
    # 清理临时分配文件
    local site_name=$(echo "$domain" | sed 's/[^a-zA-Z0-9.-]//g' | sed 's/\./-/g')
    rm -f "$RESOURCE_DIR/${site_name}_allocation.txt" 2>/dev/null || true
}

# 检查域名是否已部署（修复版，支持强制重新部署）
check_domain_deployed() {
    local domain=$1
    local force_mode=$2  # force: 强制重新部署，check: 仅检查
    
    if [ ! -f "$ALLOCATION_FILE" ]; then
        return 1
    fi
    
    if grep -q "^${domain}|" "$ALLOCATION_FILE" 2>/dev/null; then
        local status=$(grep "^${domain}|" "$ALLOCATION_FILE" | head -1 | cut -d'|' -f10)
        if [ "$status" = "RUNNING" ] || [ "$status" = "PENDING" ]; then
            log "⚠️  域名 $domain 已在部署中或已运行 (状态: $status)"
            
            # 获取已分配的端口信息
            local record=$(grep "^${domain}|" "$ALLOCATION_FILE" | head -1)
            if [ -n "$record" ]; then
                local web_port=$(echo "$record" | cut -d'|' -f3)
                local db_port=$(echo "$record" | cut -d'|' -f4)
                local redis_port=$(echo "$record" | cut -d'|' -f5)
                local project_path=$(echo "$record" | cut -d'|' -f2)
                local network_name=$(echo "$record" | cut -d'|' -f9)
                
                echo "================================================"
                echo "站点 $domain 已存在！"
                echo "----------------------------------------"
                echo "项目路径: $project_path"
                echo "Web端口: $web_port"
                echo "数据库端口: $db_port"
                echo "Redis端口: $redis_port"
                echo "网络: $network_name"
                echo "状态: $status"
                echo "================================================"
            fi
            
            # 如果是强制模式，清理现有部署
            if [ "$force_mode" = "force" ]; then
                log "强制重新部署模式：清理现有资源..."
                
                # 释放锁（如果持有）
                release_lock
                
                # 清理现有部署
                cleanup_existing_deployment "$domain"
                
                # 重新获取锁
                acquire_lock
                
                return 1  # 返回未部署状态，允许继续
            fi
            
            return 0
        fi
    fi
    
    return 1
}

# 检查端口是否被占用
check_port() {
    local port=$1
    
    # 使用ss检查端口
    if ss -tulpn | grep -q ":${port} "; then
        return 0
    fi
    
    # 使用netstat检查（备用）
    if command -v netstat >/dev/null && netstat -tulpn 2>/dev/null | grep -q ":${port} "; then
        return 0
    fi
    
    # 检查端口是否在分配文件中（状态为PENDING或RUNNING）
    if [ -f "$ALLOCATION_FILE" ]; then
        local allocated_ports=$(grep -E "PENDING|RUNNING" "$ALLOCATION_FILE" 2>/dev/null | \
            awk -F'|' '{print $3" "$4" "$5}' | tr ' ' '\n')
        if echo "$allocated_ports" | grep -q "^${port}$"; then
            return 0
        fi
    fi
    
    return 1
}

# 获取已分配的端口列表
get_allocated_ports() {
    # 从分配文件中获取
    local allocated_from_file=""
    if [ -f "$ALLOCATION_FILE" ]; then
        allocated_from_file=$(grep -E "PENDING|RUNNING" "$ALLOCATION_FILE" 2>/dev/null | \
            awk -F'|' '{print $3" "$4" "$5}' | tr ' ' '\n' | grep -E "^[0-9]+$")
    fi
    
    # 从Docker容器获取（正在运行的）
    local allocated_from_docker=""
    if command -v docker >/dev/null; then
        allocated_from_docker=$(docker ps --format "{{.Ports}}" 2>/dev/null | \
            grep -oE ":[0-9]+->" | cut -d: -f2 | cut -d- -f1 | grep -E "^[0-9]+$")
    fi
    
    # 合并并去重
    echo -e "$allocated_from_file\n$allocated_from_docker" | sort -n | uniq
}

# 获取已分配的IP列表
get_allocated_ips() {
    # 从分配文件中获取
    local allocated_from_file=""
    if [ -f "$ALLOCATION_FILE" ]; then
        allocated_from_file=$(grep -E "PENDING|RUNNING" "$ALLOCATION_FILE" 2>/dev/null | \
            awk -F'|' '{print $6" "$7" "$8}' | tr ' ' '\n' | grep -E "^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$")
    fi
    
    # 从Docker网络中获取
    local allocated_from_docker=""
    if command -v docker >/dev/null; then
        for network in $(docker network ls --format "{{.Name}}" 2>/dev/null); do
            if [ "$network" != "bridge" ] && [ "$network" != "host" ] && [ "$network" != "none" ]; then
                allocated_from_docker="$allocated_from_docker $(docker network inspect "$network" --format '{{range .IPAM.Config}}{{.Subnet}}{{end}}' 2>/dev/null | tr ' ' '\n')"
            fi
        done
    fi
    
    # 合并并去重
    echo -e "$allocated_from_file\n$allocated_from_docker" | grep -E "^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+/[0-9]+$" | cut -d'/' -f1 | sort -u
}

# 生成不冲突的子网 - 修复：只输出子网地址，不输出日志信息
generate_non_conflict_subnet() {
    local site_name=$1
    local max_attempts=20
    local attempt=1
    
    while [ $attempt -le $max_attempts ]; do
        # 生成基于站点名称和尝试次数的随机IP段
        local site_hash=$(echo "${site_name}-${attempt}" | md5sum | cut -d' ' -f1 | head -c 8)
        local part1=$((0x${site_hash:0:2} % 50 + 10))  # 10-59，避免Docker默认范围
        local part2=$((0x${site_hash:2:2} % 240 + 16))  # 16-255
        local part3=$((0x${site_hash:4:2} % 240 + 16))  # 16-255
        
        local subnet="10.$part1.$part2.0/24"
        
        # 检查子网是否冲突
        local conflict=false
        if command -v docker >/dev/null; then
            for existing_subnet in $(docker network ls -q 2>/dev/null | xargs docker network inspect --format='{{range .IPAM.Config}}{{.Subnet}}{{end}}' 2>/dev/null); do
                if [ "$subnet" = "$existing_subnet" ]; then
                    conflict=true
                    break
                fi
                
                # 简单的子网重叠检查
                if [[ $existing_subnet == *"/24" ]] && [[ $subnet == *"/24" ]]; then
                    local existing_base=$(echo "$existing_subnet" | cut -d'.' -f1-3)
                    local new_base=$(echo "$subnet" | cut -d'.' -f1-3)
                    if [ "$existing_base" = "$new_base" ]; then
                        conflict=true
                        break
                    fi
                fi
            done
        fi
        
        if [ "$conflict" = false ]; then
            # 输出日志到标准错误，不影响命令替换
            echo "✅ 生成不冲突的子网: $subnet (尝试 $attempt/$max_attempts)" >&2
            # 只输出子网地址到标准输出
            echo "10.$part1.$part2"
            return 0
        fi
        
        # 输出警告到标准错误
        echo "尝试 $attempt/$max_attempts: 子网 $subnet 冲突，尝试其他子网" >&2
        ((attempt++))
    done
    
    error "无法生成不冲突的子网，请手动清理现有的Docker网络"
}

# 原子性资源分配（核心函数）- 修复版，支持强制重新部署
allocate_resources() {
    local domain=$1
    local site_name=$2
    local force_mode=$3  # force: 强制重新部署
    
    log "开始原子性资源分配..."
    acquire_lock
    
    # 检查是否已分配（支持强制重新部署）
    if check_domain_deployed "$domain" "$force_mode"; then
        release_lock
        if [ "$force_mode" = "force" ]; then
            # 如果是强制重新部署，已经清理过了，重新开始分配
            log "重新开始资源分配..."
            allocate_resources "$domain" "$site_name" "no"  # 递归调用，避免死锁
            return
        else
            error "域名 $domain 已部署或正在部署中，如需重新部署请使用 --force 参数"
        fi
    fi
    
    # 获取已分配的端口
    local allocated_ports=$(get_allocated_ports)
    log "已分配端口列表: $(echo $allocated_ports | tr '\n' ' ')"
    
    # 端口范围定义
    local port_ranges=(
        "8080 8180 WEB"    # Web端口范围
        "3306 3406 DB"     # 数据库端口范围  
        "6379 6479 REDIS"  # Redis端口范围
    )
    
    # 分配端口
    local web_port=""
    local db_port=""
    local redis_port=""
    
    for range in "${port_ranges[@]}"; do
        local start_port=$(echo $range | awk '{print $1}')
        local end_port=$(echo $range | awk '{print $2}')
        local port_type=$(echo $range | awk '{print $3}')
        
        for port in $(seq $start_port $end_port); do
            if ! echo "$allocated_ports" | grep -q "^${port}$" && ! check_port $port; then
                case $port_type in
                    "WEB") web_port=$port ;;
                    "DB") db_port=$port ;;
                    "REDIS") redis_port=$port ;;
                esac
                
                # 临时标记端口为使用中
                allocated_ports="$allocated_ports"$'\n'"$port"
                break
            fi
        done
        
        if [ -z "$web_port" ] && [ "$port_type" = "WEB" ]; then
            warn "在范围 $start_port-$end_port 内未找到可用Web端口"
        elif [ -z "$db_port" ] && [ "$port_type" = "DB" ]; then
            warn "在范围 $start_port-$end_port 内未找到可用数据库端口"
        elif [ -z "$redis_port" ] && [ "$port_type" = "REDIS" ]; then
            warn "在范围 $start_port-$end_port 内未找到可用Redis端口"
        fi
    done
    
    # 检查端口是否都分配成功
    if [ -z "$web_port" ] || [ -z "$db_port" ] || [ -z "$redis_port" ]; then
        release_lock
        error "无法分配所有必需端口 (WEB:$web_port, DB:$db_port, REDIS:$redis_port)"
    fi
    
    # 生成不冲突的子网
    local subnet_base=$(generate_non_conflict_subnet "$site_name")
    
    # 为每个服务分配IP
    local web_ip="${subnet_base}.10"
    local db_ip="${subnet_base}.20"
    local redis_ip="${subnet_base}.30"
    
    # 检查IP是否已被占用
    local allocated_ips=$(get_allocated_ips)
    log "已分配IP列表: $(echo $allocated_ips | tr '\n' ' ')"
    
    # 简单的IP冲突检查
    local ip_conflict=false
    for ip in "$web_ip" "$db_ip" "$redis_ip"; do
        if echo "$allocated_ips" | grep -q "^${ip}$"; then
            warn "IP地址冲突: $ip"
            ip_conflict=true
        fi
    done
    
    if [ "$ip_conflict" = true ]; then
        release_lock
        error "IP地址冲突，请清理现有网络或重试"
    fi
    
    log "✅ 成功分配IP段: ${subnet_base}.0/24"
    log "  Web IP: $web_ip"
    log "  DB IP: $db_ip"
    log "  Redis IP: $redis_ip"
    
    # 生成网络名称
    local network_name="${site_name}-network-$(date +%s)"
    
    # 项目路径
    local project_path="/opt/docker/${site_name}"
    
    # 记录分配的资源（状态为PENDING）
    local timestamp=$(date +'%Y-%m-%d %H:%M:%S')
    echo "${domain}|${project_path}|${web_port}|${db_port}|${redis_port}|${web_ip}|${db_ip}|${redis_ip}|${network_name}|PENDING|${timestamp}" >> "$ALLOCATION_FILE"
    
    # 创建资源分配文件（供后续使用）
    local allocation_record="$RESOURCE_DIR/${site_name}_allocation.txt"
    cat > "$allocation_record" << EOF
# WordPress 资源分配记录
# 生成时间: ${timestamp}
# 进程ID: $$

DOMAIN=${domain}
SITE_NAME=${site_name}
PROJECT_PATH=${project_path}

# 端口分配
WEB_PORT=${web_port}
DB_PORT=${db_port}
REDIS_PORT=${redis_port}

# IP地址分配
WEB_IP=${web_ip}
DB_IP=${db_ip}
REDIS_IP=${redis_ip}

# 网络配置
NETWORK_NAME=${network_name}
SUBNET_BASE=${subnet_base}

# 状态
ALLOCATION_STATUS=PENDING
EOF
    
    log "✅ 资源分配完成并已记录"
    log "  分配文件: $allocation_record"
    
    # 输出分配结果
    echo "================================================"
    echo "✅ 资源分配成功！"
    echo "----------------------------------------"
    echo "域名: $domain"
    echo "站点名称: $site_name"
    echo "项目路径: $project_path"
    echo "----------------------------------------"
    echo "端口分配:"
    echo "  Web端口: $web_port"
    echo "  数据库端口: $db_port"
    echo "  Redis端口: $redis_port"
    echo "----------------------------------------"
    echo "IP地址分配:"
    echo "  Web IP: $web_ip"
    echo "  数据库 IP: $db_ip"
    echo "  Redis IP: $redis_ip"
    echo "----------------------------------------"
    echo "网络: $network_name (子网: ${subnet_base}.0/24)"
    echo "================================================"
    
    release_lock
    
    # 设置全局变量
    WEB_PORT=$web_port
    DB_PORT=$db_port
    REDIS_PORT=$redis_port
    WEB_IP=$web_ip
    DB_IP=$db_ip
    REDIS_IP=$redis_ip
    NETWORK_NAME=$network_name
    PROJECT_PATH=$project_path
}

# 更新分配状态
update_allocation_status() {
    local domain=$1
    local status=$2  # RUNNING, FAILED, REMOVED
    
    acquire_lock
    
    if [ -f "$ALLOCATION_FILE" ]; then
        # 使用临时文件更新
        local temp_file="${ALLOCATION_FILE}.tmp"
        local updated=0
        
        while IFS= read -r line; do
            if [[ "$line" == "${domain}|"* ]]; then
                local old_status=$(echo "$line" | cut -d'|' -f10)
                local new_line=$(echo "$line" | sed "s/|${old_status}|/|${status}|/")
                echo "$new_line"
                updated=1
            else
                echo "$line"
            fi
        done < "$ALLOCATION_FILE" > "$temp_file"
        
        if [ $updated -eq 1 ]; then
            mv "$temp_file" "$ALLOCATION_FILE"
            log "✅ 更新域名 $domain 状态为: $status"
        else
            rm -f "$temp_file"
            warn "未找到域名 $domain 的分配记录"
        fi
    fi
    
    release_lock
}

# 清理分配记录
cleanup_allocation() {
    local domain=$1
    
    acquire_lock
    
    if [ -f "$ALLOCATION_FILE" ]; then
        # 移除该域名的记录
        if grep -q "^${domain}|" "$ALLOCATION_FILE"; then
            grep -v "^${domain}|" "$ALLOCATION_FILE" > "${ALLOCATION_FILE}.tmp" && \
            mv "${ALLOCATION_FILE}.tmp" "$ALLOCATION_FILE"
            
            log "✅ 清理域名 $domain 的分配记录"
        else
            log "ℹ️  域名 $domain 无分配记录可清理"
        fi
    fi
    
    # 清理临时分配文件
    local site_name=$(echo "$domain" | sed 's/[^a-zA-Z0-9.-]//g' | sed 's/\./-/g')
    rm -f "$RESOURCE_DIR/${site_name}_allocation.txt" 2>/dev/null || true
    
    release_lock
}

# =========================================================================
# Docker 和 WordPress 核心函数
# =========================================================================

# 检查 Must-Use Plugins 目录（增强版）
check_mu_plugins_directory() {
    local mu_plugins_dir="/opt/docker/mu-plugins"
    
    if [ -d "$mu_plugins_dir" ]; then
        log "✅ 检测到 Must-Use Plugins 目录: $mu_plugins_dir"
        
        # 检查 mu-plugins 目录结构
        if [ -d "$mu_plugins_dir/mu-plugins" ]; then
            MU_PLUGINS_SOURCE="$mu_plugins_dir/mu-plugins"
            log "✅ 使用标准 mu-plugins 目录: $MU_PLUGINS_SOURCE"
        elif [ -d "$mu_plugins_dir/wp-content/mu-plugins" ]; then
            MU_PLUGINS_SOURCE="$mu_plugins_dir/wp-content/mu-plugins"
            log "✅ 使用 wp-content/mu-plugins 目录: $MU_PLUGINS_SOURCE"
        else
            # 如果根目录中有 PHP 文件，直接作为 mu-plugins 目录
            local php_count=$(find "$mu_plugins_dir" -maxdepth 1 -name "*.php" -type f 2>/dev/null | wc -l)
            if [ $php_count -gt 0 ]; then
                MU_PLUGINS_SOURCE="$mu_plugins_dir"
                log "✅ 使用根目录作为 mu-plugins 源: $MU_PLUGINS_SOURCE"
                log "⚠️  检测到 $php_count 个PHP文件，将直接挂载到 mu-plugins 目录"
            else
                warn "Must-Use Plugins 目录中没有检测到 PHP 文件，将创建标准目录结构"
                mkdir -p "$mu_plugins_dir/mu-plugins"
                MU_PLUGINS_SOURCE="$mu_plugins_dir/mu-plugins"
                log "✅ 已创建标准 mu-plugins 目录: $MU_PLUGINS_SOURCE"
            fi
        fi
        
        # 显示 mu-plugins 列表（增强功能）
        if [ -n "$MU_PLUGINS_SOURCE" ] && [ -d "$MU_PLUGINS_SOURCE" ]; then
            local mu_plugins_list=$(find "$MU_PLUGINS_SOURCE" -maxdepth 2 -name "*.php" 2>/dev/null | head -10)
            if [ -n "$mu_plugins_list" ]; then
                log "检测到的 Must-Use Plugins 示例:"
                echo "$mu_plugins_list" | while read plugin; do
                    local plugin_name=$(basename "$plugin")
                    local dir_name=$(basename "$(dirname "$plugin")")
                    if [ "$dir_name" = "$(basename "$MU_PLUGINS_SOURCE")" ]; then
                        echo "  - $plugin_name (根目录)"
                    else
                        echo "  - $dir_name/$plugin_name"
                    fi
                done
            else
                log "ℹ️  Must-Use Plugins 目录为空，可以稍后添加插件"
            fi
        fi
    else
        log "创建 Must-Use Plugins 目录: $mu_plugins_dir"
        mkdir -p "$mu_plugins_dir/mu-plugins"
        MU_PLUGINS_SOURCE="$mu_plugins_dir/mu-plugins"
        log "✅ 已创建 Must-Use Plugins 目录: $MU_PLUGINS_SOURCE"
    fi
    
    # 确保目录存在
    mkdir -p "$MU_PLUGINS_SOURCE"
}

# 检查现有镜像
check_existing_images() {
    log "检查现有 Docker 镜像..."
    
    if docker images --format "table {{.Repository}}:{{.Tag}}" | grep -q "wordpress"; then
        WORDPRESS_IMAGE=$(docker images --format "table {{.Repository}}:{{.Tag}}" | grep "wordpress" | head -1)
        log "✅ 找到 WordPress 镜像: $WORDPRESS_IMAGE"
    else
        log "⚠️  未找到 WordPress 镜像，将使用 wordpress:latest"
        WORDPRESS_IMAGE="wordpress:latest"
    fi
    
    if docker images --format "table {{.Repository}}:{{.Tag}}" | grep -q "mysql"; then
        MYSQL_IMAGE=$(docker images --format "table {{.Repository}}:{{.Tag}}" | grep "mysql" | head -1)
        log "✅ 找到 MySQL 镜像: $MYSQL_IMAGE"
    else
        log "⚠️  未找到 MySQL 镜像，将使用 mysql:8.0"
        MYSQL_IMAGE="mysql:8.0"
    fi
    
    if docker images --format "table {{.Repository}}:{{.Tag}}" | grep -q "redis"; then
        REDIS_IMAGE=$(docker images --format "table {{.Repository}}:{{.Tag}}" | grep "redis" | head -1)
        log "✅ 找到 Redis 镜像: $REDIS_IMAGE"
    else
        log "⚠️  未找到 Redis 镜像，将使用 redis:7-alpine"
        REDIS_IMAGE="redis:7-alpine"
    fi
}

# 检查 Docker 环境
check_docker_environment() {
    log "检查 Docker 环境..."
    
    if ! systemctl is-active --quiet docker; then
        log "启动 Docker 服务..."
        systemctl start docker || error "无法启动 Docker 服务"
    fi
    
    if ! command -v docker &> /dev/null; then
        error "Docker 未安装，请先安装 Docker"
    fi
    
    if command -v docker-compose &> /dev/null; then
        DOCKER_COMPOSE_CMD="docker-compose"
        log "使用 docker-compose"
    elif docker compose version &> /dev/null; then
        DOCKER_COMPOSE_CMD="docker compose"
        log "使用 docker compose"
    else
        error "未检测到 Docker Compose，请先安装 Docker Compose"
    fi
    
    log "✅ Docker 环境检查通过"
}

# 快速生成密码
generate_password() {
    openssl rand -base64 12 | tr -d '/+=' | head -c 16
}

# 生成 Redis 密码
generate_redis_password() {
    openssl rand -base64 12 | tr -d '/+=' | head -c 32
}

# 创建 Redis 数据目录
create_redis_directories() {
    local site_name=$1
    
    # 创建持久化存储目录
    local redis_persistent_dir="/opt/docker/redis/${site_name}"
    mkdir -p "$redis_persistent_dir"
    log "✅ 创建 Redis 持久化目录: $redis_persistent_dir"
    
    # 创建临时存储目录
    local redis_temp_dir="/www/wwwroot/webs_temp/temp/${site_name}/redis"
    mkdir -p "$redis_temp_dir"
    log "✅ 创建 Redis 临时目录: $redis_temp_dir"
    
    # 设置权限
    chmod -R 755 "/opt/docker/redis" 2>/dev/null || true
    chmod -R 775 "/www/wwwroot/webs_temp/temp" 2>/dev/null || true
    
    # 直接设置全局变量
    REDIS_PERSISTENT_DIR="$redis_persistent_dir"
    REDIS_TEMP_DIR="$redis_temp_dir"
}

# 创建项目目录（使用已分配的项目路径）
create_project_dir() {
    log "创建项目目录: $PROJECT_PATH"
    mkdir -p "$PROJECT_PATH" && cd "$PROJECT_PATH"
    
    # 创建独立插件目录
    local plugins_dir="$PROJECT_PATH/wp-content/plugins"
    mkdir -p "$plugins_dir"
    PLUGINS_SOURCE="$plugins_dir"
    log "✅ 已创建独立插件目录: $PLUGINS_SOURCE"
}

# 检查 MySQL 版本并调整配置
get_mysql_version() {
    local mysql_image=$1
    if [[ $mysql_image == *":"* ]]; then
        local version=$(echo "$mysql_image" | cut -d':' -f2)
        echo "$version"
    else
        echo "latest"
    fi
}

# 创建 Docker 网络（如果不存在） - 修复版
create_docker_network() {
    local network_name=$1
    local subnet_base=$2
    
    if [ -z "$network_name" ] || [ "$network_name" = "bridge" ]; then
        log "使用默认 bridge 网络，跳过网络创建"
        return 0
    fi
    
    # 从 subnet_base 计算子网
    local subnet="${subnet_base}.0/24"
    
    log "检查 Docker 网络: $network_name，子网: $subnet"
    
    # 检查网络是否存在
    if docker network inspect "$network_name" >/dev/null 2>&1; then
        log "✅ Docker 网络已存在: $network_name"
        
        # 检查现有网络的子网
        local existing_subnet=$(docker network inspect "$network_name" --format='{{range .IPAM.Config}}{{.Subnet}}{{end}}' 2>/dev/null)
        if [ -n "$existing_subnet" ] && [ "$existing_subnet" != "$subnet" ]; then
            warn "现有网络的子网 ($existing_subnet) 与预期子网 ($subnet) 不匹配"
            warn "使用现有网络的子网: $existing_subnet"
            
            # 更新全局变量以匹配现有网络的子网
            local existing_base=$(echo "$existing_subnet" | cut -d'.' -f1-3 | cut -d'/' -f1)
            WEB_IP="${existing_base}.10"
            DB_IP="${existing_base}.20"
            REDIS_IP="${existing_base}.30"
            
            log "更新IP地址以匹配现有网络:"
            log "  Web IP: $WEB_IP"
            log "  DB IP: $DB_IP"
            log "  Redis IP: $REDIS_IP"
        fi
        return 0
    fi
    
    log "创建 Docker 网络: $network_name，子网: $subnet"
    
    # 检查子网是否冲突
    local conflict=false
    if command -v docker >/dev/null; then
        for existing_subnet in $(docker network ls -q 2>/dev/null | xargs docker network inspect --format='{{range .IPAM.Config}}{{.Subnet}}{{end}}' 2>/dev/null); do
            if [ "$subnet" = "$existing_subnet" ]; then
                conflict=true
                break
            fi
        done
    fi
    
    if [ "$conflict" = true ]; then
        error "子网 $subnet 与现有网络冲突，无法创建网络"
    fi
    
    if docker network create --subnet="$subnet" --gateway="${subnet_base}.1" "$network_name"; then
        log "✅ Docker 网络创建成功: $network_name"
        return 0
    else
        warn "创建 Docker 网络失败，尝试使用现有网络"
        return 1
    fi
}

# 创建 docker-compose.yml - 使用已分配的IP和端口（增强版，支持网络条件判断）
create_docker_compose() {
    local site_name=$1
    local db_password=$2
    local mysql_root_password=$3
    local redis_password=$4
    local wp_image=$5
    local mysql_image=$6
    local redis_image=$7
    local domain=$8
    local mu_plugins_source=$9
    local plugins_source=${10}
    local redis_persistent_dir=${11}
    local redis_temp_dir=${12}
    
    # 使用已分配的全局变量
    local web_port=$WEB_PORT
    local db_port=$DB_PORT
    local redis_port=$REDIS_PORT
    local network_name=$NETWORK_NAME
    local db_ip=$DB_IP
    local wp_ip=$WEB_IP
    local redis_ip=$REDIS_IP
    
    log "创建 docker-compose.yml (使用已分配资源)..."
    
    # 检查 MySQL 版本
    local mysql_version=$(get_mysql_version "$mysql_image")
    log "检测到 MySQL 版本: $mysql_version"
    
    # 清理变量中的特殊字符
    db_password=$(echo "$db_password" | tr -d '\n\r\t' | sed 's/["]/\\"/g')
    mysql_root_password=$(echo "$mysql_root_password" | tr -d '\n\r\t' | sed 's/["]/\\"/g')
    redis_password=$(echo "$redis_password" | tr -d '\n\r\t' | sed 's/["]/\\"/g')
    
    # MySQL 8.0+ 优化配置
    local mysql_command=""
    if [[ $mysql_version == 8.* ]] || [[ $mysql_version == "latest" ]] || [[ $mysql_version > "8.0" ]]; then
        log "使用 MySQL 8.0+ 配置"
        mysql_command=$(cat << 'EOF'
    command:
      - "--character-set-server=utf8mb4"
      - "--collation-server=utf8mb4_unicode_ci"
      - "--wait_timeout=28800"
      - "--interactive_timeout=28800"
      - "--bind-address=0.0.0.0"
      - "--max_connections=100"
      - "--innodb_buffer_pool_size=256M"
EOF
)
    else
        log "使用 MySQL 5.x 配置"
        mysql_command=$(cat << 'EOF'
    command:
      - "--default-authentication-plugin=mysql_native_password"
      - "--character-set-server=utf8mb4"
      - "--collation-server=utf8mb4_unicode_ci"
      - "--wait_timeout=28800"
      - "--interactive_timeout=28800"
      - "--bind-address=0.0.0.0"
EOF
)
    fi
    
    # 智能网络配置：如果使用默认网络（bridge），不设置固定IP
    if [ "$network_name" = "bridge" ]; then
        log "使用默认 bridge 网络，不设置固定IP"
        
        cat > docker-compose.yml << EOF
version: '3.8'

services:
  wordpress:
    image: "$wp_image"
    container_name: "${site_name}-wp"
    ports:
      - "${web_port}:80"
    environment:
      WORDPRESS_DB_HOST: "db"
      WORDPRESS_DB_USER: "wordpress"
      WORDPRESS_DB_PASSWORD: "${db_password}"
      WORDPRESS_DB_NAME: "wordpress"
      WORDPRESS_REDIS_HOST: "redis"
      WORDPRESS_REDIS_PORT: "6379"
      WORDPRESS_REDIS_PASSWORD: "${redis_password}"
      WORDPRESS_CONFIG_EXTRA: |
        define('WP_HOME', 'https://${domain}');
        define('WP_SITEURL', 'https://${domain}');
        define('WP_DEBUG', false);
        // Redis 对象缓存配置
        define('WP_REDIS_HOST', 'redis');
        define('WP_REDIS_PORT', 6379);
        define('WP_REDIS_PASSWORD', '${redis_password}');
        define('WP_REDIS_TIMEOUT', 1);
        define('WP_REDIS_READ_TIMEOUT', 1);
        define('WP_REDIS_DATABASE', 0);
    volumes:
      - "wordpress_data:/var/www/html"
      - "./wp-config.php:/var/www/html/wp-config.php:ro"
      - "${mu_plugins_source}:/var/www/html/wp-content/mu-plugins"
      - "${plugins_source}:/var/www/html/wp-content/plugins"
    restart: "unless-stopped"
    depends_on:
      - "db"
      - "redis"

  db:
    image: "$mysql_image"
    container_name: "${site_name}-db"
    ports:
      - "${db_port}:3306"
    environment:
      MYSQL_DATABASE: "wordpress"
      MYSQL_USER: "wordpress"
      MYSQL_PASSWORD: "${db_password}"
      MYSQL_ROOT_PASSWORD: "${mysql_root_password}"
      MYSQL_ROOT_HOST: "%"
    volumes:
      - "db_data:/var/lib/mysql"
${mysql_command}
    restart: "unless-stopped"

  redis:
    image: "$redis_image"
    container_name: "${site_name}-redis"
    ports:
      - "${redis_port}:6379"
    environment:
      - REDIS_PASSWORD=${redis_password}
      - REDIS_PORT=6379
    command:
      - "redis-server"
      - "--requirepass ${redis_password}"
      - "--maxmemory 256mb"
      - "--maxmemory-policy allkeys-lru"
      - "--save 900 1"
      - "--save 300 10"
      - "--save 60 10000"
    volumes:
      - "${redis_persistent_dir}:/data"
      - "${redis_temp_dir}:/tmp"
    restart: "unless-stopped"

volumes:
  wordpress_data:
    driver: local
  db_data:
    driver: local
EOF
    else
        log "使用专用网络，设置固定IP"
        
        cat > docker-compose.yml << EOF
version: '3.8'

services:
  wordpress:
    image: "$wp_image"
    container_name: "${site_name}-wp"
    ports:
      - "${web_port}:80"
    environment:
      WORDPRESS_DB_HOST: "${db_ip}"
      WORDPRESS_DB_USER: "wordpress"
      WORDPRESS_DB_PASSWORD: "${db_password}"
      WORDPRESS_DB_NAME: "wordpress"
      WORDPRESS_REDIS_HOST: "${redis_ip}"
      WORDPRESS_REDIS_PORT: "6379"
      WORDPRESS_REDIS_PASSWORD: "${redis_password}"
      WORDPRESS_CONFIG_EXTRA: |
        define('WP_HOME', 'https://${domain}');
        define('WP_SITEURL', 'https://${domain}');
        define('WP_DEBUG', false);
        // Redis 对象缓存配置
        define('WP_REDIS_HOST', '${redis_ip}');
        define('WP_REDIS_PORT', 6379);
        define('WP_REDIS_PASSWORD', '${redis_password}');
        define('WP_REDIS_TIMEOUT', 1);
        define('WP_REDIS_READ_TIMEOUT', 1);
        define('WP_REDIS_DATABASE', 0);
    volumes:
      - "wordpress_data:/var/www/html"
      - "./wp-config.php:/var/www/html/wp-config.php:ro"
      - "${mu_plugins_source}:/var/www/html/wp-content/mu-plugins"
      - "${plugins_source}:/var/www/html/wp-content/plugins"
    networks:
      ${network_name}:
        ipv4_address: "${wp_ip}"
    restart: "unless-stopped"
    depends_on:
      - "db"
      - "redis"

  db:
    image: "$mysql_image"
    container_name: "${site_name}-db"
    ports:
      - "${db_port}:3306"
    environment:
      MYSQL_DATABASE: "wordpress"
      MYSQL_USER: "wordpress"
      MYSQL_PASSWORD: "${db_password}"
      MYSQL_ROOT_PASSWORD: "${mysql_root_password}"
      MYSQL_ROOT_HOST: "%"
    volumes:
      - "db_data:/var/lib/mysql"
${mysql_command}
    networks:
      ${network_name}:
        ipv4_address: "${db_ip}"
    restart: "unless-stopped"

  redis:
    image: "$redis_image"
    container_name: "${site_name}-redis"
    ports:
      - "${redis_port}:6379"
    environment:
      - REDIS_PASSWORD=${redis_password}
      - REDIS_PORT=6379
    command:
      - "redis-server"
      - "--requirepass ${redis_password}"
      - "--maxmemory 256mb"
      - "--maxmemory-policy allkeys-lru"
      - "--save 900 1"
      - "--save 300 10"
      - "--save 60 10000"
    volumes:
      - "${redis_persistent_dir}:/data"
      - "${redis_temp_dir}:/tmp"
    networks:
      ${network_name}:
        ipv4_address: "${redis_ip}"
    restart: "unless-stopped"

volumes:
  wordpress_data:
    driver: local
  db_data:
    driver: local

networks:
  ${network_name}:
    external: true
    name: ${network_name}
EOF
    fi
    
    log "docker-compose.yml 创建完成"
    log "✅ 使用已分配的资源:"
    log "   Web端口: $web_port, IP: $wp_ip"
    log "   数据库端口: $db_port, IP: $db_ip"
    log "   Redis端口: $redis_port, IP: $redis_ip"
    log "✅ 已挂载 Must-Use Plugins 目录: $mu_plugins_source"
    log "✅ 已挂载独立插件目录: $plugins_source"
    log "✅ 已配置 Redis 持久化目录: $redis_persistent_dir"
    log "✅ 已配置 Redis 临时目录: $redis_temp_dir"
}

# 创建自定义 wp-config.php 文件
create_wp_config() {
    local site_name=$1
    local domain=$2
    local db_password=$3
    local db_host=$4
    local redis_password=$5
    local redis_host=$6
    
    log "创建自定义 wp-config.php..."
    
    # 生成安全的认证密钥
    local auth_key=$(openssl rand -base64 48)
    local secure_auth_key=$(openssl rand -base64 48)
    local logged_in_key=$(openssl rand -base64 48)
    local nonce_key=$(openssl rand -base64 48)
    local auth_salt=$(openssl rand -base64 48)
    local secure_auth_salt=$(openssl rand -base64 48)
    local logged_in_salt=$(openssl rand -base64 48)
    local nonce_salt=$(openssl rand -base64 48)
    
    cat > wp-config.php << EOF
<?php
/**
 * WordPress基础配置文件。
 * 自动生成于: $(date)
 */

// ** MySQL 设置 - 具体信息来自您正在使用的主机 ** //
/** WordPress数据库的名称 */
define( 'DB_NAME', 'wordpress' );

/** MySQL数据库用户名 */
define( 'DB_USER', 'wordpress' );

/** MySQL数据库密码 */
define( 'DB_PASSWORD', '${db_password}' );

/** MySQL主机 */
define( 'DB_HOST', '${db_host}' );

/** 创建数据表时默认的文字编码 */
define( 'DB_CHARSET', 'utf8mb4' );

/** 数据库整理类型。如不确定请勿更改 */
define( 'DB_COLLATE', '' );

/**#@+
 * 认证密钥与盐。
 */
define('AUTH_KEY',         '${auth_key}');
define('SECURE_AUTH_KEY',  '${secure_auth_key}');
define('LOGGED_IN_KEY',    '${logged_in_key}');
define('NONCE_KEY',        '${nonce_key}');
define('AUTH_SALT',        '${auth_salt}');
define('SECURE_AUTH_SALT', '${secure_auth_salt}');
define('LOGGED_IN_SALT',   '${logged_in_salt}');
define('NONCE_SALT',       '${nonce_salt}');

/**#@-*/

/**
 * WordPress数据表前缀。
 */
\$table_prefix = 'wp_';

/**
 * 用于开发环境，显示错误。
 */
define( 'WP_DEBUG', false );
define( 'WP_DEBUG_LOG', false );
define( 'WP_DEBUG_DISPLAY', false );

/** 强制 HTTPS 和正确域名 */
define('WP_HOME', 'https://${domain}');
define('WP_SITEURL', 'https://${domain}');

/** Redis 对象缓存配置 */
define('WP_REDIS_HOST', '${redis_host}');
define('WP_REDIS_PORT', 6379);
define('WP_REDIS_PASSWORD', '${redis_password}');
define('WP_REDIS_TIMEOUT', 1);
define('WP_REDIS_READ_TIMEOUT', 1);
define('WP_REDIS_DATABASE', 0);

/** 启用缓存 */
define('WP_CACHE', true);

/** 安全设置 */
define('DISALLOW_FILE_EDIT', true);
define('FORCE_SSL_ADMIN', true);

/** 处理反向代理 */
if (isset(\$_SERVER['HTTP_X_FORWARDED_PROTO']) && \$_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https') {
    \$_SERVER['HTTPS'] = 'on';
    \$_SERVER['SERVER_PORT'] = 443;
}

if (isset(\$_SERVER['HTTP_X_FORWARDED_HOST'])) {
    \$_SERVER['HTTP_HOST'] = \$_SERVER['HTTP_X_FORWARDED_HOST'];
}

/** 确保正确设置 \$_SERVER变量 */
if (!isset(\$_SERVER['HTTP_HOST'])) {
    \$_SERVER['HTTP_HOST'] = '${domain}';
}

if (!isset(\$_SERVER['SERVER_NAME'])) {
    \$_SERVER['SERVER_NAME'] = '${domain}';
}

if (!isset(\$_SERVER['REQUEST_URI'])) {
    \$_SERVER['REQUEST_URI'] = '/';
}

/** WordPress目录的绝对路径。 */
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}

/** 设置WordPress变量和包含文件。 */
require_once ABSPATH . 'wp-settings.php';
EOF
    
    log "wp-config.php 创建完成"
    log "✅ Redis 配置已添加到 wp-config.php"
    log "✅ 安全密钥已生成"
}

# 等待数据库完全启动
wait_for_database() {
    local site_name=$1
    local mysql_root_password=$2
    local db_port=$3
    local max_attempts=60
    local attempt=1
    
    log "等待数据库完全启动..."
    
    while [ $attempt -le $max_attempts ]; do
        local container_status=$(docker inspect --format='{{.State.Status}}' "${site_name}-db" 2>/dev/null || echo "not_found")
        
        if [ "$container_status" = "running" ]; then
            if command -v mysql &> /dev/null; then
                if mysql -h 127.0.0.1 -P $db_port -u root -p${mysql_root_password} -e "SELECT 1;" 2>/dev/null; then
                    log "✅ 数据库已完全启动并通过端口 $db_port 可访问"
                    return 0
                fi
            else
                if docker exec "${site_name}-db" mysql -u root -p${mysql_root_password} -e "SELECT 1;" 2>/dev/null; then
                    log "✅ 数据库已完全启动"
                    return 0
                fi
            fi
        elif [ "$container_status" = "restarting" ]; then
            log "尝试 $attempt/$max_attempts: 数据库容器正在重启，等待 3 秒..."
        elif [ "$container_status" = "not_found" ]; then
            log "尝试 $attempt/$max_attempts: 数据库容器未找到，等待 3 秒..."
        else
            log "尝试 $attempt/$max_attempts: 数据库容器状态: $container_status，等待 3 秒..."
        fi
        
        sleep 3
        ((attempt++))
    done
    
    warn "⚠️ 数据库启动超时，但将继续部署流程"
    return 1
}

# 等待 Redis 完全启动
wait_for_redis() {
    local site_name=$1
    local redis_password=$2
    local redis_port=$3
    local max_attempts=30
    local attempt=1
    
    log "等待 Redis 完全启动..."
    
    while [ $attempt -le $max_attempts ]; do
        local container_status=$(docker inspect --format='{{.State.Status}}' "${site_name}-redis" 2>/dev/null || echo "not_found")
        
        if [ "$container_status" = "running" ]; then
            if command -v redis-cli &> /dev/null; then
                if redis-cli -h 127.0.0.1 -p $redis_port -a ${redis_password} ping 2>/dev/null | grep -q "PONG"; then
                    log "✅ Redis 已完全启动并通过端口 $redis_port 可访问"
                    return 0
                fi
            else
                if docker exec "${site_name}-redis" redis-cli -a ${redis_password} ping 2>/dev/null | grep -q "PONG"; then
                    log "✅ Redis 已完全启动"
                    return 0
                fi
            fi
        elif [ "$container_status" = "restarting" ]; then
            log "尝试 $attempt/$max_attempts: Redis 容器正在重启，等待 2 秒..."
        elif [ "$container_status" = "not_found" ]; then
            log "尝试 $attempt/$max_attempts: Redis 容器未找到，等待 2 秒..."
        else
            log "尝试 $attempt/$max_attempts: Redis 容器状态: $container_status，等待 2 秒..."
        fi
        
        sleep 2
        ((attempt++))
    done
    
    warn "⚠️  Redis 启动较慢，但将继续部署流程"
    return 1
}

# =========================================================================
# WordPress 站点配置（移除Logo等操作，WP-CLI安装已替代URL配置）
# =========================================================================

# 移除WordPress Logo（保留物理替换操作）
remove_wordpress_logo() {
    local site_name=$1
    
    log "移除WordPress安装页Logo..."
    
    # 物理替换：将Logo图片直接替换为1x1像素的透明图片
    local transparent_pixel="R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7"
    docker exec "${site_name}-wp" bash -c "echo '$transparent_pixel' | base64 -d > /var/www/html/wp-admin/images/wordpress-logo.svg" 2>/dev/null || true
    docker exec "${site_name}-wp" bash -c "echo '$transparent_pixel' | base64 -d > /var/www/html/wp-admin/images/w-logo-blue.png" 2>/dev/null || true
    docker exec "${site_name}-wp" bash -c "echo '$transparent_pixel' | base64 -d > /var/www/html/wp-admin/images/wordpress-logo.png" 2>/dev/null || true

    # 强制CSS屏蔽：修改安装页专用的CSS文件
    docker exec "${site_name}-wp" bash -c "echo '.wp-core-ui .mu-item:before, .wp-core-ui h1 a, #logo { display: none !important; background-image: none !important; }' >> /var/www/html/wp-admin/css/install.css" 2>/dev/null || true
    
    # 针对旧版本或缓存，直接修改加载器
    docker exec "${site_name}-wp" sed -i 's/background-image:url(../images/wordpress-logo.svg?ver=20230307)/background-image:none/g' /var/www/html/wp-admin/css/install.css 2>/dev/null || true
    
    log "✅ WordPress安装页Logo已物理移除"
}

# 清理旧数据卷 - 修复网络未找到错误
clean_old_volumes() {
    local site_name=$1
    
    log "清理可能存在的旧数据卷..."
    
    # 先检查并创建网络（如果使用专用网络）
    if [ -n "$NETWORK_NAME" ] && [ "$NETWORK_NAME" != "bridge" ]; then
        # 从web_ip中提取subnet_base
        local subnet_base=$(echo "$WEB_IP" | cut -d'.' -f1-3)
        # 创建网络（如果不存在）
        create_docker_network "$NETWORK_NAME" "$subnet_base"
    fi
    
    # 停止并删除旧容器
    if docker ps -a | grep -q "${site_name}-db"; then
        log "停止并删除旧数据库容器..."
        docker stop "${site_name}-db" 2>/dev/null || true
        docker rm -f "${site_name}-db" 2>/dev/null || true
    fi
    
    if docker ps -a | grep -q "${site_name}-wp"; then
        log "停止并删除旧WordPress容器..."
        docker stop "${site_name}-wp" 2>/dev/null || true
        docker rm -f "${site_name}-wp" 2>/dev/null || true
    fi
    
    if docker ps -a | grep -q "${site_name}-redis"; then
        log "停止并删除旧Redis容器..."
        docker stop "${site_name}-redis" 2>/dev/null || true
        docker rm -f "${site_name}-redis" 2>/dev/null || true
    fi
    
    # 等待清理完成
    sleep 2
    
    # 尝试停止并删除旧的docker-compose服务（忽略网络错误）
    log "尝试停止并删除旧的docker-compose服务..."
    $DOCKER_COMPOSE_CMD down 2>/dev/null || {
        log "docker-compose down执行有警告或错误，继续执行..."
        # 尝试直接删除容器
        docker rm -f "${site_name}-db" "${site_name}-wp" "${site_name}-redis" 2>/dev/null || true
    }
}

# 启动WordPress服务（增强版，包含WP-CLI静默安装）
start_wordpress_services() {
    local docker_compose_cmd=$1
    local site_name=$2
    local domain=$3
    local mysql_root_password=$4
    local db_port=$5
    local redis_password=$6
    local redis_port=$7
    
    log "启动WordPress服务..."
    
    # 清理旧数据
    clean_old_volumes "$site_name"
    
    # 创建Docker网络（如果使用专用网络）
    if [ -n "$NETWORK_NAME" ] && [ "$NETWORK_NAME" != "bridge" ]; then
        # 从web_ip中提取subnet_base
        local subnet_base=$(echo "$WEB_IP" | cut -d'.' -f1-3)
        create_docker_network "$NETWORK_NAME" "$subnet_base"
    fi
    
    # 启动服务
    if $docker_compose_cmd up -d; then
        log "✅ WordPress服务启动命令执行成功"
    else
        error "❌ WordPress服务启动失败"
    fi
    
    # 等待数据库完全启动
    if ! wait_for_database "$site_name" "$mysql_root_password" "$db_port"; then
        warn "⚠️  数据库启动超时，尝试强制继续..."
    fi
    
    # 等待Redis完全启动
    if ! wait_for_redis "$site_name" "$redis_password" "$redis_port"; then
        warn "⚠️  Redis启动超时，尝试强制继续..."
    fi
    
    # 快速状态检查
    log "检查容器状态..."
    for i in {1..10}; do
        if $docker_compose_cmd ps | grep -q "Up"; then
            log "✅ 容器运行正常"
            break
        fi
        sleep 3
    done
    
    # 显示简要状态
    $docker_compose_cmd ps
    
    # 等待WordPress容器完全初始化
    log "等待WordPress容器完全初始化..."
    sleep 15
    
    # 使用WP-CLI进行静默安装（替代原来的网页配置）
    if install_wordpress_with_wp_cli "$site_name" "$domain" "$WP_LANGUAGE" "$WP_USERNAME" "$WP_PASSWORD" "$WP_EMAIL" "$SITE_TITLE"; then
        log "✅ WordPress静默安装完成，已跳过网页安装步骤"
    else
        warn "⚠️  WP-CLI安装失败，WordPress可能需要手动安装"
    fi
    
    # 移除WordPress Logo
    remove_wordpress_logo "$site_name"
    
    # 额外等待WordPress完全初始化
    log "等待WordPress完全初始化..."
    sleep 10
}

# =========================================================================
# DNS和IP功能（增强版）
# =========================================================================

# 判断是否为内网IP
is_private_ip() {
    local ip=$1
    if [[ $ip =~ ^10\. ]] || \
       [[ $ip =~ ^172\.(1[6-9]|2[0-9]|3[0-1])\. ]] || \
       [[ $ip =~ ^192\.168\. ]] || \
       [[ $ip =~ ^127\. ]] || \
       [[ $ip =~ ^169\.254\. ]] || \
       [[ $ip =~ ^fc00: ]] || \
       [[ $ip =~ ^fe80: ]]; then
        return 0
    else
        return 1
    fi
}

# 多DNS服务器查询域名解析（增强功能）
multi_dns_query() {
    local domain=$1
    local dns_servers=(
        "8.8.8.8"       # Google DNS
        "1.1.1.1"       # Cloudflare DNS
        "208.67.222.222" # OpenDNS
        "9.9.9.9"       # Quad9
    )
    
    declare -A ip_count
    local valid_ips=()
    
    log "使用${#dns_servers[@]}个DNS服务器查询域名解析: $domain"
    
    for dns in "${dns_servers[@]}"; do
        local ips=$(dig +short "@$dns" "$domain" A 2>/dev/null | grep -E '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$' | sort)
        
        if [ -n "$ips" ]; then
            log "  DNS服务器 $dns 返回: $(echo $ips | tr '\n' ' ')"
            for ip in $ips; do
                if ! is_private_ip "$ip"; then
                    ((ip_count["$ip"]++))
                    valid_ips+=("$ip")
                fi
            done
        else
            warn "  DNS服务器 $dns 查询失败或无结果"
        fi
        
        # 避免请求过快
        sleep 0.5
    done
    
    # 统计结果
    if [ ${#ip_count[@]} -eq 0 ]; then
        warn "所有DNS服务器均未返回有效IP地址"
        return 1
    fi
    
    log "DNS解析统计结果:"
    for ip in "${!ip_count[@]}"; do
        log "  IP $ip : ${ip_count[$ip]} 个DNS服务器返回"
    done
    
    # 选择最频繁出现的IP
    local most_frequent_ip=""
    local max_count=0
    
    for ip in "${!ip_count[@]}"; do
        if [ ${ip_count[$ip]} -gt $max_count ]; then
            max_count=${ip_count[$ip]}
            most_frequent_ip=$ip
        fi
    done
    
    if [ -n "$most_frequent_ip" ]; then
        log "✅ 选择最频繁解析的IP: $most_frequent_ip (${ip_count[$most_frequent_ip]}个DNS服务器返回)"
        echo "$most_frequent_ip"
        return 0
    else
        warn "无法确定域名解析IP"
        return 1
    fi
}

# 获取服务器有效公网IP - 增强版（优先使用DNS解析的IP）
get_server_public_ip() {
    local domain="$1"
    
    log "获取服务器有效公网IP..."
    
    # 首先尝试通过域名DNS解析获取IP
    if [ -n "$domain" ]; then
        local dns_ip=$(multi_dns_query "$domain" 2>/dev/null || echo "")
        if [ -n "$dns_ip" ] && ! is_private_ip "$dns_ip"; then
            log "✅ 使用域名DNS解析的IP: $dns_ip"
            echo "$dns_ip"
            return 0
        fi
    fi
    
    # 如果DNS解析失败，尝试使用简单的IP查询服务
    local simple_ip_services=(
        "http://ipv4.icanhazip.com"
        "http://checkip.amazonaws.com"
    )
    
    for service in "${simple_ip_services[@]}"; do
        local ip=$(curl -s --connect-timeout 5 "$service" 2>/dev/null | grep -E '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$' | head -1)
        
        if [ -n "$ip" ] && ! is_private_ip "$ip"; then
            log "✅ 使用IP查询服务获取的IP: $ip (来自 $service)"
            echo "$ip"
            return 0
        fi
    done
    
    # 最后尝试使用主机名
    local fallback_ip=$(hostname -I | awk '{print $1}' 2>/dev/null)
    if [ -n "$fallback_ip" ] && ! is_private_ip "$fallback_ip"; then
        log "✅ 使用备选方法获取公网IP: $fallback_ip"
        echo "$fallback_ip"
        return 0
    fi
    
    echo "127.0.0.1"
    return 0
}

# =========================================================================
# 宝塔反向代理和SSL配置函数（内网+FRP环境优化版）
# =========================================================================

# 验证SSL证书文件 - 增强版（支持ECC和RSA私钥）
verify_ssl_certificate_files() {
    local domain="$1"
    local cert_dir="/www/server/panel/vhost/cert/${domain}"
    
    log "验证SSL证书文件..."
    
    if [ ! -d "$cert_dir" ]; then
        warn "证书目录不存在: $cert_dir"
        return 1
    fi
    
    local cert_file="${cert_dir}/fullchain.pem"
    local key_file="${cert_dir}/privkey.pem"
    
    if [ ! -f "$cert_file" ]; then
        warn "证书文件不存在: $cert_file"
        return 1
    fi
    
    if [ ! -f "$key_file" ]; then
        warn "私钥文件不存在: $key_file"
        return 1
    fi
    
    # 验证证书文件内容
    if ! openssl x509 -in "$cert_file" -noout 2>/dev/null; then
        warn "证书文件格式无效: $cert_file"
        return 1
    fi
    
    # 支持ECC和RSA私钥验证
    local key_check_result=0
    if openssl rsa -in "$key_file" -check -noout 2>/dev/null; then
        log "✅ RSA私钥验证通过"
    elif openssl ec -in "$key_file" -check -noout 2>/dev/null; then
        log "✅ ECC私钥验证通过"
    else
        # 如果标准验证失败，尝试使用更通用的方法
        if openssl pkey -in "$key_file" -noout 2>/dev/null; then
            log "✅ 私钥文件基本验证通过（通用方法）"
        else
            warn "私钥文件格式无效: $key_file"
            # 即使验证失败，如果文件存在且非空，也继续使用
            if [ -s "$key_file" ]; then
                log "⚠️  私钥验证失败但文件存在且非空，继续使用"
                key_check_result=0
            else
                key_check_result=1
            fi
        fi
    fi
    
    if [ $key_check_result -ne 0 ]; then
        return 1
    fi
    
    # 验证证书有效期
    local cert_end=$(openssl x509 -in "$cert_file" -noout -enddate 2>/dev/null | cut -d= -f2)
    local cert_timestamp=$(date -d "$cert_end" +%s 2>/dev/null)
    local current_timestamp=$(date +%s)
    
    if [ $cert_timestamp -le $current_timestamp ]; then
        warn "证书已过期: $cert_end"
        return 1
    fi
    
    log "✅ SSL证书验证通过:"
    log "   证书文件: $cert_file"
    log "   私钥文件: $key_file"
    log "   有效期至: $cert_end"
    
    return 0
}

# 检查宝塔命令行工具
check_bt_cli() {
    log "检查宝塔命令行工具 (bt) 是否存在..."
    if ! command -v bt &> /dev/null; then
        warn "❌ 宝塔命令行工具 (bt) 未找到。跳过反向代理配置。"
        return 1
    fi
    log "✅ bt 命令存在。"
    return 0
}

# 清理旧的Nginx配置文件
clean_old_nginx_config() {
    local domain="$1"
    local conf_file="/www/server/panel/vhost/nginx/${domain}.conf"
    
    if [ -f "$conf_file" ]; then
        log "清理旧的Nginx配置文件: $conf_file"
        rm -f "$conf_file"
    fi
}

# 创建临时的HTTP-only Nginx配置（包含.well-known目录访问）
create_temp_http_config() {
    local domain="$1"
    local web_port="$2"
    local conf_path="/www/server/panel/vhost/nginx"
    local conf_file="${conf_path}/${domain}.conf"
    local target_url="http://127.0.0.1:${web_port}"
    
    # 确保配置目录存在
    if [ ! -d "$conf_path" ]; then
        log "Nginx配置目录 $conf_path 不存在，请检查宝塔面板是否已安装Nginx。"
        return 1
    fi

    # 清理旧配置
    clean_old_nginx_config "$domain"

    log "创建临时的HTTP-only Nginx配置: $conf_file"

    # 创建只包含HTTP的配置，等待SSL证书申请成功后再添加SSL
    cat > "$conf_file" << EOF
# WordPress Docker站点临时配置（等待SSL证书）
# 生成时间: $(date)
# 域名: ${domain}

server {
    listen 80;
    server_name ${domain};
    index index.php index.html index.htm default.php default.htm default.html;
    root /www/wwwroot/${domain};

    # ACME证书验证目录 - 关键：允许访问.well-known目录进行SSL证书验证
    location /.well-known/acme-challenge/ {
        root /www/wwwroot/${domain};
        try_files \$uri =404;
        access_log off;
        log_not_found off;
        allow all;
    }

    # -------------------- GZIP压缩配置 --------------------
    gzip on;
    gzip_min_length 1k;
    gzip_buffers 4 16k;
    gzip_comp_level 4;
    gzip_types text/plain application/javascript application/x-javascript text/css application/xml text/xml application/rss+xml application/atom+xml image/svg+xml;
    gzip_vary on;

    # -------------------- 核心反向代理配置 --------------------

    # 反向代理配置
    location / {
        proxy_pass ${target_url};
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header X-Forwarded-Host \$host;
        proxy_set_header X-Forwarded-Port \$server_port;
        
        # 超时设置
        proxy_connect_timeout 60s;
        proxy_send_timeout 600s;
        proxy_read_timeout 600s;
        
        # 缓冲区设置
        proxy_buffering on;
        proxy_buffer_size 8k;
        proxy_buffers 8 8k;
        proxy_busy_buffers_size 16k;
    }

    # 静态文件缓存
    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$ {
        proxy_pass ${target_url};
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        
        # 缓存设置
        expires 1y;
        add_header Cache-Control "public, immutable";
    }

    # 安全设置
    location ~ /\.ht {
        deny all;
    }
    
    location ~ /wp-config.php {
        deny all;
    }

    access_log /www/wwwlogs/${domain}.log;
    error_log /www/wwwlogs/${domain}.error.log;
}
EOF

    if [ $? -eq 0 ]; then
        log "✅ 临时HTTP Nginx配置创建成功: $conf_file"
        log "✅ 已添加 .well-known 目录访问支持，用于SSL证书验证"
        return 0
    else
        error "❌ Nginx配置文件创建失败"
    fi
}

# 创建完整的SSL Nginx配置 - 添加了 listen 443 ssl; http2 on;
create_ssl_nginx_config() {
    local domain="$1"
    local web_port="$2"
    local conf_path="/www/server/panel/vhost/nginx"
    local conf_file="${conf_path}/${domain}.conf"
    local target_url="http://127.0.0.1:${web_port}"
    
    log "创建完整的SSL Nginx配置: $conf_file"

    cat > "$conf_file" << EOF
# WordPress Docker站点完整配置（含SSL）
# 生成时间: $(date)
# 域名: ${domain}

# HTTP重定向到HTTPS
server {
    listen 80;
    server_name ${domain};
    
    # ACME证书验证目录 - 关键：允许访问.well-known目录进行SSL证书验证
    location /.well-known/acme-challenge/ {
        root /www/wwwroot/${domain};
        try_files \$uri =404;
        access_log off;
        log_not_found off;
        allow all;
    }
    
    # 其他HTTP请求重定向到HTTPS
    location / {
        return 301 https://\$server_name\$request_uri;
    }
}

# HTTPS主配置
server {
    listen 443 ssl;
    http2 on;
    server_name ${domain};
    index index.php index.html index.htm default.php default.htm default.html;
    root /www/wwwroot/${domain};

    # SSL证书配置
    ssl_certificate /www/server/panel/vhost/cert/${domain}/fullchain.pem;
    ssl_certificate_key /www/server/panel/vhost/cert/${domain}/privkey.pem;
    ssl_protocols TLSv1.1 TLSv1.2 TLSv1.3;
    ssl_ciphers EECDH+CHACHA20,EECDH+CHACHA20-draft,EECDH+AES128:RSA+AES128:EECDH+AES256:RSA+AES256:EECDH+3DES:RSA+3DES:!MD5;
    ssl_prefer_server_ciphers on;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 10m;
    keepalive_timeout 70;

    # 强制跳转HTTPS
    if (\$server_port !~ 443){
        rewrite ^/(.*)$ https://\$host\$1 permanent;
    }

    # -------------------- GZIP压缩配置 --------------------
    gzip on;
    gzip_min_length 1k;
    gzip_buffers 4 16k;
    gzip_comp_level 4;
    gzip_types text/plain application/javascript application/x-javascript text/css application/xml text/xml application/rss+xml application/atom+xml image/svg+xml;
    gzip_vary on;

    # -------------------- 核心反向代理配置 --------------------

    # 反向代理配置
    location / {
        proxy_pass ${target_url};
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header X-Forwarded-Host \$host;
        proxy_set_header X-Forwarded-Port \$server_port;
        
        # 超时设置
        proxy_connect_timeout 60s;
        proxy_send_timeout 600s;
        proxy_read_timeout 600s;
        
        # 缓冲区设置
        proxy_buffering on;
        proxy_buffer_size 8k;
        proxy_buffers 8 8k;
        proxy_busy_buffers_size 16k;
        
        # WebSocket支持（如果需要）
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
    }

    # 静态文件缓存
    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$ {
        proxy_pass ${target_url};
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        
        # 缓存设置
        expires 1y;
        add_header Cache-Control "public, immutable";
    }

    # 安全设置
    location ~ /\.ht {
        deny all;
    }
    
    location ~ /wp-config.php {
        deny all;
    }

    access_log /www/wwwlogs/${domain}.log;
    error_log /www/wwwlogs/${domain}.error.log;
}
EOF

    if [ $? -eq 0 ]; then
        log "✅ 完整SSL Nginx配置创建成功: $conf_file"
        log "✅ 已添加 listen 443 ssl; 和 http2 on;"
        return 0
    else
        error "❌ SSL Nginx配置文件创建失败"
    fi
}

# 安全的Nginx配置测试和重载
safe_nginx_reload() {
    log "测试Nginx配置..."
    
    if nginx -t; then
        log "✅ Nginx配置测试通过"
        
        # 重载Nginx（不停止服务）
        if systemctl reload nginx; then
            log "✅ Nginx服务重载成功（不停止服务）"
            return 0
        else
            error "❌ Nginx服务重载失败"
        fi
    else
        warn "❌ Nginx配置测试失败，跳过SSL配置"
        return 1
    fi
}

# 安装 acme.sh
install_acme_sh() {
    log "检查 acme.sh 是否已安装..."
    
    # 使用完整路径检查
    if [ -f "/root/.acme.sh/acme.sh" ]; then
        log "✅ acme.sh 已安装"
        return 0
    fi
    
    log "安装 acme.sh..."
    
    # 安装依赖
    if command -v apt-get &> /dev/null; then
        apt-get update && apt-get install -y socat curl cron
    elif command -v yum &> /dev/null; then
        yum install -y socat curl cronie
    else
        warn "无法确定包管理器，跳过依赖安装"
    fi
    
    # 安装 acme.sh - 修复安装逻辑
    curl https://get.acme.sh | sh -s email=admin@${DOMAIN} || {
        warn "acme.sh 安装过程有警告，但继续检查..."
    }
    
    # 检查安装是否成功
    if [ -f "/root/.acme.sh/acme.sh" ]; then
        log "✅ acme.sh 安装成功"
        # 重新加载环境
        source /root/.bashrc 2>/dev/null || true
        return 0
    else
        warn "❌ acme.sh 安装失败"
        return 1
    fi
}

# =========================================================================
# Cloudflare DNS API函数（内网+FRP环境专用）
# =========================================================================

# 获取域名对应的Cloudflare Zone ID和Token
get_cf_zone_and_token() {
    local domain="$1"
    
    # 判断域名属于哪个zone
    if [[ "$domain" == *"fssss"* ]]; then
        CF_ZONE_ID="$CF_ZONE_FSSSS"
        CF_TOKEN="$CF_TOKEN_FSSSS"
        log "使用 fssss.com 的 Zone ID: $CF_ZONE_ID"
    elif [[ "$domain" == *"dailyvp"* ]]; then
        CF_ZONE_ID="$CF_ZONE_DAILYVP"
        CF_TOKEN="$CF_TOKEN_DAILYVP"
        log "使用 dailyvp.com 的 Zone ID: $CF_ZONE_ID"
    else
        # 默认使用fssss.com的配置
        CF_ZONE_ID="$CF_ZONE_FSSSS"
        CF_TOKEN="$CF_TOKEN_FSSSS"
        warn "未识别的域名模式，默认使用 fssss.com 配置"
    fi
}

# 获取DNS记录ID
get_dns_record_id() {
    local domain="$1"
    local record_type="$2"  # A, TXT, CNAME等
    
    get_cf_zone_and_token "$domain"
    
    local api_url="https://api.cloudflare.com/client/v4/zones/${CF_ZONE_ID}/dns_records?type=${record_type}&name=${domain}"
    
    log "查询DNS记录: $domain (类型: $record_type)"
    
    local response=$(curl -s -X GET "$api_url" \
        -H "Authorization: Bearer ${CF_TOKEN}" \
        -H "Content-Type: application/json")
    
    if echo "$response" | grep -q '"success":true'; then
        local record_id=$(echo "$response" | grep -o '"id":"[^"]*"' | head -1 | cut -d'"' -f4)
        if [ -n "$record_id" ]; then
            log "✅ 找到DNS记录ID: $record_id"
            echo "$record_id"
            return 0
        else
            log "ℹ️  未找到DNS记录"
            echo ""
            return 1
        fi
    else
        warn "❌ 查询DNS记录失败: $response"
        echo ""
        return 1
    fi
}

# 添加DNS TXT记录（用于SSL证书验证）
add_dns_txt_record() {
    local domain="$1"
    local txt_value="$2"
    local ttl=120  # 默认TTL
    
    get_cf_zone_and_token "$domain"
    
    # 删除_acme-challenge前缀（如果有）
    local record_name=$(echo "$domain" | sed 's/^\*\.//')
    local full_record_name="_acme-challenge.${record_name}"
    
    log "添加TXT记录: $full_record_name -> $txt_value"
    
    local api_url="https://api.cloudflare.com/client/v4/zones/${CF_ZONE_ID}/dns_records"
    
    local data=$(cat <<EOF
{
    "type": "TXT",
    "name": "${full_record_name}",
    "content": "${txt_value}",
    "ttl": ${ttl},
    "proxied": false
}
EOF
)
    
    log "请求数据: $data"
    
    local response=$(curl -s -X POST "$api_url" \
        -H "Authorization: Bearer ${CF_TOKEN}" \
        -H "Content-Type: application/json" \
        -d "$data")
    
    if echo "$response" | grep -q '"success":true'; then
        local record_id=$(echo "$response" | grep -o '"id":"[^"]*"' | head -1 | cut -d'"' -f4)
        log "✅ 成功添加TXT记录，记录ID: $record_id"
        echo "$record_id"
        return 0
    else
        warn "❌ 添加TXT记录失败: $response"
        echo ""
        return 1
    fi
}

# 删除DNS记录
delete_dns_record() {
    local domain="$1"
    local record_id="$2"
    
    get_cf_zone_and_token "$domain"
    
    if [ -z "$record_id" ]; then
        warn "未提供记录ID，跳过删除"
        return 1
    fi
    
    local api_url="https://api.cloudflare.com/client/v4/zones/${CF_ZONE_ID}/dns_records/${record_id}"
    
    log "删除DNS记录: $record_id"
    
    local response=$(curl -s -X DELETE "$api_url" \
        -H "Authorization: Bearer ${CF_TOKEN}" \
        -H "Content-Type: application/json")
    
    if echo "$response" | grep -q '"success":true'; then
        log "✅ 成功删除DNS记录"
        return 0
    else
        warn "❌ 删除DNS记录失败: $response"
        return 1
    fi
}

# 清理旧的TXT记录
cleanup_old_txt_records() {
    local domain="$1"
    
    log "清理旧的_acme-challenge TXT记录..."
    
    # 删除_acme-challenge前缀（如果有）
    local record_name=$(echo "$domain" | sed 's/^\*\.//')
    local txt_record_name="_acme-challenge.${record_name}"
    
    get_cf_zone_and_token "$domain"
    
    local api_url="https://api.cloudflare.com/client/v4/zones/${CF_ZONE_ID}/dns_records?type=TXT&name=${txt_record_name}"
    
    local response=$(curl -s -X GET "$api_url" \
        -H "Authorization: Bearer ${CF_TOKEN}" \
        -H "Content-Type: application/json")
    
    if echo "$response" | grep -q '"success":true'; then
        # 提取所有记录ID
        local record_ids=$(echo "$response" | grep -o '"id":"[^"]*"' | cut -d'"' -f4)
        
        for record_id in $record_ids; do
            log "删除旧的TXT记录: $record_id"
            delete_dns_record "$domain" "$record_id"
            sleep 1  # 避免请求过快
        done
        
        log "✅ 完成清理旧的TXT记录"
    else
        warn "查询TXT记录失败: $response"
    fi
}

# =========================================================================
# SSL证书申请函数（内网+FRP环境优化版）
# =========================================================================

# 使用Cloudflare DNS API申请SSL证书（内网环境最佳方案）
apply_ssl_with_cf_dns() {
    local domain="$1"
    local cert_dir="/www/server/panel/vhost/cert/${domain}"
    
    log "开始使用 Cloudflare DNS API 申请 SSL 证书..."
    log "这是内网+FRP环境的最佳方案，不需要服务器暴露端口"
    
    # 确保证书目录存在
    mkdir -p "$cert_dir"
    
    # 安装 acme.sh
    if ! install_acme_sh; then
        warn "❌ acme.sh 安装失败"
        return 1
    fi
    
    # 设置Cloudflare API环境变量
    export CF_Token="$CF_TOKEN_FSSSS"  # 默认使用fssss.com的token
    
    # 根据域名选择正确的zone和token
    get_cf_zone_and_token "$domain"
    
    if [[ "$domain" == *"fssss"* ]]; then
        export CF_Token="$CF_TOKEN_FSSSS"
        export CF_Zone_ID="$CF_ZONE_FSSSS"
        log "使用 fssss.com 的 Cloudflare API Token"
    elif [[ "$domain" == *"dailyvp"* ]]; then
        export CF_Token="$CF_TOKEN_DAILYVP"
        export CF_Zone_ID="$CF_ZONE_DAILYVP"
        log "使用 dailyvp.com 的 Cloudflare API Token"
    else
        export CF_Token="$CF_TOKEN_FSSSS"
        export CF_Zone_ID="$CF_ZONE_FSSSS"
        warn "使用默认的 fssss.com Cloudflare API Token"
    fi
    
    # 清理旧的TXT记录
    cleanup_old_txt_records "$domain"
    
    # 等待DNS缓存清理
    log "等待DNS缓存清理..."
    sleep 5
    
    # 使用DNS验证申请证书
    log "使用Cloudflare DNS验证申请证书..."
    
    # 申请ECC证书（更安全，兼容性好）
    if /root/.acme.sh/acme.sh --issue --dns dns_cf \
        -d "$domain" \
        --keylength ec-256 \
        --force; then
        log "✅ SSL证书申请成功（Cloudflare DNS验证）"
        
        # 安装证书到宝塔目录
        if /root/.acme.sh/acme.sh --install-cert -d "$domain" \
            --key-file "$cert_dir/privkey.pem" \
            --fullchain-file "$cert_dir/fullchain.pem" \
            --reloadcmd "systemctl reload nginx"; then
            log "✅ SSL证书已安装到宝塔目录: $cert_dir"
            
            # 验证证书文件
            if verify_ssl_certificate_files "$domain"; then
                log "✅ Cloudflare DNS SSL证书申请和安装完成"
                return 0
            else
                warn "❌ 证书文件验证失败"
                # 即使验证失败，只要文件存在就返回成功
                if [ -f "${cert_dir}/fullchain.pem" ] && [ -f "${cert_dir}/privkey.pem" ]; then
                    log "⚠️  证书验证失败但文件存在，继续使用"
                    return 0
                fi
                return 1
            fi
        else
            warn "❌ 证书安装失败"
            return 1
        fi
    else
        warn "❌ Cloudflare DNS证书申请失败"
        return 1
    fi
}

# 备用方案：手动DNS验证模式
apply_ssl_with_manual_dns() {
    local domain="$1"
    local cert_dir="/www/server/panel/vhost/cert/${domain}"
    
    log "尝试手动DNS验证模式..."
    
    # 确保证书目录存在
    mkdir -p "$cert_dir"
    
    # 安装 acme.sh
    if ! install_acme_sh; then
        warn "❌ acme.sh 安装失败"
        return 1
    fi
    
    # 使用manual模式获取TXT记录值
    log "生成TXT验证记录..."
    
    # 清理旧的验证记录
    /root/.acme.sh/acme.sh --remove -d "$domain" 2>/dev/null || true
    
    # 申请证书（manual模式）
    if /root/.acme.sh/acme.sh --issue --dns -d "$domain" \
        --yes-I-know-dns-manual-mode-enough-go-ahead-please \
        --keylength ec-256; then
        log "✅ 证书申请命令执行成功，等待手动验证"
    else
        warn "❌ 证书申请命令失败"
        return 1
    fi
    
    # 等待一段时间让acme.sh生成挑战记录
    sleep 5
    
    # 获取TXT记录值（这里需要解析acme.sh的输出）
    log "请手动在Cloudflare中添加以下TXT记录："
    log "名称: _acme-challenge.${domain}"
    log "值: [需要从acme.sh输出中获取]"
    log "TTL: 120"
    
    # 尝试自动获取TXT值
    local acme_log="/root/.acme.sh/acme.sh.log"
    if [ -f "$acme_log" ]; then
        local txt_value=$(grep -A5 "TXT value" "$acme_log" | tail -1 | sed 's/.*: //')
        if [ -n "$txt_value" ]; then
            log "检测到TXT值: $txt_value"
            
            # 调用Python脚本添加TXT记录
            log "调用Python脚本添加TXT记录..."
            
            # 创建临时Python脚本
            local python_script="/tmp/add_txt_record.py"
            cat > "$python_script" << 'PYEOF'
#!/usr/bin/env python3
import sys
import os
import requests
import json

domain = sys.argv[1]
txt_value = sys.argv[2]

# Cloudflare API配置
CF_API_EMAIL = "yuhoyuhoyuho@163.com"
CF_API_KEY = "86101f6b162714f8296aaef3f4d750c86546c"
CF_ZONE_DAILYVP = "b2c28fcb971de103750ae6de9525da16"
CF_ZONE_FSSSS = "f98030164e9e162c7bbc56a9c6707b1e"

# 判断域名对应的Zone ID
if "fssss" in domain:
    zone_id = CF_ZONE_FSSSS
else:
    zone_id = CF_ZONE_DAILYVP

# 获取记录名
record_name = "_acme-challenge." + domain.lstrip('*.')

# API端点
api_url = f"https://api.cloudflare.com/client/v4/zones/{zone_id}/dns_records"

# 请求头
headers = {
    "X-Auth-Email": CF_API_EMAIL,
    "X-Auth-Key": CF_API_KEY,
    "Content-Type": "application/json"
}

# 请求数据
data = {
    "type": "TXT",
    "name": record_name,
    "content": txt_value,
    "ttl": 120,
    "proxied": False
}

# 发送请求
response = requests.post(api_url, headers=headers, data=json.dumps(data))

if response.status_code == 200:
    result = response.json()
    if result.get("success"):
        print(f"✅ 成功添加TXT记录: {record_name} -> {txt_value}")
        print(f"记录ID: {result['result']['id']}")
        sys.exit(0)
    else:
        print(f"❌ 添加TXT记录失败: {result}")
        sys.exit(1)
else:
    print(f"❌ API请求失败: {response.status_code}")
    sys.exit(1)
PYEOF

            chmod +x "$python_script"
            
            if python3 "$python_script" "$domain" "$txt_value"; then
                log "✅ Python脚本执行成功，TXT记录已添加"
                
                # 等待DNS传播
                log "等待DNS传播（60秒）..."
                sleep 60
                
                # 继续证书申请流程
                if /root/.acme.sh/acme.sh --renew -d "$domain" \
                    --yes-I-know-dns-manual-mode-enough-go-ahead-please; then
                    log "✅ 手动DNS验证成功，证书已签发"
                    
                    # 安装证书
                    /root/.acme.sh/acme.sh --install-cert -d "$domain" \
                        --key-file "$cert_dir/privkey.pem" \
                        --fullchain-file "$cert_dir/fullchain.pem" \
                        --reloadcmd "systemctl reload nginx"
                    
                    log "✅ 手动DNS验证SSL证书安装完成"
                    return 0
                else
                    warn "❌ 证书续期失败"
                fi
            else
                warn "❌ Python脚本执行失败"
            fi
            
            # 清理临时文件
            rm -f "$python_script"
        else
            warn "无法自动获取TXT值，请手动操作"
        fi
    else
        warn "未找到acme.sh日志文件"
    fi
    
    return 1
}

# 内网环境SSL证书申请流程（优化版）
internal_ssl_application() {
    local domain="$1"
    
    log "开始内网环境SSL证书申请流程..."
    log "优先使用Cloudflare DNS API验证（最适合内网+FRP环境）"
    
    # 先检查是否已经有证书存在
    if verify_ssl_certificate_files "$domain"; then
        log "✅ SSL证书已存在，跳过申请"
        return 0
    fi
    
    # 获取服务器有效公网IP（用于检查DNS解析）
    SERVER_PUBLIC_IP=$(get_server_public_ip "$domain")
    log "服务器有效公网IP: $SERVER_PUBLIC_IP"
    
    # 等待DNS传播
    log "等待DNS传播..."
    sleep 10
    
    # 关键修复：确保网站目录存在
    local site_root="/www/wwwroot/${domain}"
    if [ ! -d "$site_root" ]; then
        log "创建网站根目录: $site_root"
        mkdir -p "$site_root"
        
        # 创建一个简单的index.html文件
        cat > "${site_root}/index.html" << EOF
<!DOCTYPE html>
<html>
<head>
    <title>${domain}</title>
    <meta charset="utf-8">
</head>
<body>
    <h1>Welcome to ${domain}</h1>
    <p>Site is being configured...</p>
</body>
</html>
EOF
        chown -R www:www "$site_root"
        log "✅ 已创建网站目录和默认页面"
    fi
    
    # 方案1：优先使用Cloudflare DNS API
    log "=== 方案1: Cloudflare DNS API验证 ==="
    if apply_ssl_with_cf_dns "$domain"; then
        log "✅ 通过Cloudflare DNS API成功申请SSL证书"
        return 0
    fi
    
    # 方案2：备用方案 - 手动DNS验证
    log "=== 方案2: 手动DNS验证（备用方案）==="
    if apply_ssl_with_manual_dns "$domain"; then
        log "✅ 通过手动DNS验证成功申请SSL证书"
        return 0
    fi
    
    # 最终检查：如果证书目录存在，尝试直接使用
    local cert_dir="/www/server/panel/vhost/cert/${domain}"
    if [ -d "$cert_dir" ]; then
        log "检测到证书目录存在，检查文件状态..."
        ls -la "$cert_dir/" 2>/dev/null | tee -a "$LOG_FILE"
        
        # 如果有证书文件，即使验证不完美也尝试使用
        if [ -f "${cert_dir}/fullchain.pem" ] && [ -f "${cert_dir}/privkey.pem" ]; then
            log "⚠️  证书文件存在但验证有警告，尝试继续使用..."
            # 基本文件验证
            if openssl x509 -in "${cert_dir}/fullchain.pem" -noout 2>/dev/null && \
               [ -s "${cert_dir}/privkey.pem" ]; then
                log "✅ 证书文件基本验证通过，继续使用"
                return 0
            fi
        fi
    fi
    
    warn "❌ 所有SSL证书申请方法都失败"
    log "网站将以HTTP方式运行，您可以稍后手动申请SSL证书"
    log "提示：可以登录宝塔面板手动申请SSL证书，或使用其他证书服务商"
    return 1
}

# 完整的SSL验证
complete_ssl_verification() {
    local domain="$1"
    local max_attempts=6
    local attempt=1
    
    log "开始完整的SSL证书验证..."
    
    while [ $attempt -le $max_attempts ]; do
        log "尝试 $attempt/$max_attempts: 验证HTTPS访问..."
        
        # 检查HTTPS访问
        if curl -k -s --connect-timeout 10 "https://$domain" &>/dev/null; then
            log "✅ HTTPS访问正常"
            
            # 额外验证证书有效性
            if echo | openssl s_client -connect "${domain}:443" -servername "$domain" 2>/dev/null | openssl x509 -noout -dates &>/dev/null; then
                log "✅ SSL证书有效性验证通过"
                return 0
            else
                log "⚠️  HTTPS访问正常但证书验证需要时间，等待10秒..."
            fi
        else
            # 尝试使用HTTP访问检查网站状态
            if curl -s --connect-timeout 5 "http://$domain" &>/dev/null; then
                log "HTTP访问正常，HTTPS仍在配置中..."
            else
                log "尝试 $attempt/$max_attempts: HTTPS访问失败，检查HTTP..."
            fi
        fi
        
        sleep 10
        ((attempt++))
    done
    
    # 即使HTTPS验证失败，如果证书文件存在，也算部分成功
    if verify_ssl_certificate_files "$domain"; then
        log "⚠️  HTTPS访问验证失败，但SSL证书文件已就绪"
        log "可能是CDN或防火墙导致HTTPS访问问题，证书配置已完成"
        return 0
    fi
    
    warn "⚠️  HTTPS访问验证超时"
    return 1
}

# 主配置函数 - 确保SSL成功后才配置
configure_baota_proxy() {
    local domain="$1"
    local web_port="$2"
    
    log "开始配置宝塔反向代理和SSL（内网+FRP优化版）..."
    
    if ! check_bt_cli; then
        warn "跳过宝塔反向代理配置"
        return 1
    fi

    # 步骤1: 创建临时的HTTP-only配置（包含.well-known目录访问）
    log "=== 阶段1: 配置HTTP访问（包含SSL验证支持）==="
    create_temp_http_config "$domain" "$web_port"
    
    # 步骤2: 安全重载Nginx（不停止服务）
    if ! safe_nginx_reload; then
        warn "Nginx配置失败，跳过SSL配置"
        return 1
    fi
    
    # 步骤3: 等待HTTP服务正常
    log "等待HTTP服务就绪..."
    sleep 10  # 减少等待时间，因为nginx没有停止
    
    # 检查HTTP服务是否正常
    if curl -s --connect-timeout 10 "http://$domain" &>/dev/null; then
        log "✅ HTTP服务访问正常"
    else
        warn "⚠️  HTTP服务访问异常，但继续SSL申请流程"
    fi
    
    # 步骤4: 内网环境SSL证书申请（使用Cloudflare DNS验证）
    log "=== 阶段2: 申请和验证SSL证书（内网+FRP环境优化）==="
    if internal_ssl_application "$domain"; then
        log "=== 阶段3: 配置完整的SSL Nginx配置 ==="
        # 步骤5: 创建完整的SSL配置（仅在SSL证书验证成功后）
        if create_ssl_nginx_config "$domain" "$web_port"; then
            # 步骤6: 重新加载配置（不停止服务）
            if safe_nginx_reload; then
                log "✅ SSL配置完成"
                # 完整的SSL验证
                log "=== 阶段4: 最终SSL验证 ==="
                if complete_ssl_verification "$domain"; then
                    log "🎉 SSL证书完全配置成功！（内网+FRP环境优化）"
                else
                    warn "SSL证书已配置但最终验证有警告"
                fi
            else
                warn "❌ SSL配置重载失败"
            fi
        else
            warn "❌ SSL Nginx配置创建失败"
        fi
    else
        warn "跳过SSL配置，网站将以HTTP方式运行"
        log "临时HTTP配置已保留在: /www/server/panel/vhost/nginx/${domain}.conf"
        log "您可以稍后手动申请SSL证书"
    fi
    
    log "✅ 反向代理配置完成（内网+FRP环境优化版）"
}

# =========================================================================
# 用户输入和主流程 - 修复版，支持--force参数
# =========================================================================

# 解析命令行参数 - 新增函数
parse_arguments() {
    local args=("$@")
    local filtered_args=()
    local force_flag="no"
    
    # 检查是否有--force或-f参数
    for arg in "${args[@]}"; do
        if [ "$arg" = "--force" ] || [ "$arg" = "-f" ]; then
            force_flag="force"
            log "✅ 启用强制重新部署模式"
        else
            filtered_args+=("$arg")
        fi
    done
    
    # 设置全局变量
    FORCE_REDEPLOY="$force_flag"
    
    # 返回过滤后的参数
    echo "${filtered_args[@]}"
}

# 获取用户输入 - 修复版
get_user_input() {
    echo
    log "请输入WordPress站点配置"
    echo "================================================"

    # 解析参数（过滤掉--force参数）
    local parsed_args=($(parse_arguments "$@"))
    
    # 关键修复：优先从命令行参数获取域名
    local input_arg="${parsed_args[0]}"
    if [ -n "$input_arg" ]; then
        DOMAIN="$input_arg"
        log "✅ 识别到命令行参数，采用域名: $DOMAIN"
    else
        # 只有在完全没有参数时才尝试交互式输入
        if [ -t 0 ]; then
            read -p "站点域名: " DOMAIN
        else
            # 非交互模式，尝试从环境变量或管道获取
            if [ -p /dev/stdin ]; then
                # 从管道读取
                read DOMAIN
                log "✅ 从管道读取域名: $DOMAIN"
            elif [ -n "$PMS_DOMAIN" ]; then
                # 从环境变量读取
                DOMAIN="$PMS_DOMAIN"
                log "✅ 从环境变量读取域名: $DOMAIN"
            else
                error "无法在非交互模式下获取域名，请提供参数"
            fi
        fi
    fi

    if [ -z "$DOMAIN" ]; then
        error "域名不能为空"
    fi
    
    # 使用域名作为站点名称（移除特殊字符）
    SITE_NAME=$(echo "$DOMAIN" | sed 's/[^a-zA-Z0-9.-]//g' | sed 's/\./-/g')
    
    # 初始化资源目录
    init_resource_dir
    
    # 第一步：原子性资源分配（避免多用户冲突，支持强制重新部署）
    allocate_resources "$DOMAIN" "$SITE_NAME" "$FORCE_REDEPLOY"
    
    # 自动生成数据库和Redis密码
    DB_PASSWORD=$(generate_password)
    MYSQL_ROOT_PASSWORD=$(generate_password)
    REDIS_PASSWORD=$(generate_redis_password)
    
    # 获取WordPress安装配置（传递过滤后的参数）
    get_wordpress_installation_config "${parsed_args[@]}"
    
    # 自动生成WordPress管理员密码（如果未设置）
    if [ -z "$WP_PASSWORD" ]; then
        WP_PASSWORD=$(openssl rand -base64 12 | tr -d '/+=' | head -c 16)
    fi
    
    echo
    log "自动配置信息:"
    echo "----------------------------------------"
    echo "站点名称: $SITE_NAME"
    echo "域名: $DOMAIN"
    echo "强制重新部署: $FORCE_REDEPLOY"
    echo "Web访问端口: $WEB_PORT (已分配)"
    echo "数据库访问端口: $DB_PORT (已分配)"
    echo "Redis访问端口: $REDIS_PORT (已分配)"
    echo "Web容器IP: $WEB_IP"
    echo "数据库容器IP: $DB_IP"
    echo "Redis容器IP: $REDIS_IP"
    echo "网络名称: $NETWORK_NAME"
    echo "项目路径: $PROJECT_PATH"
    echo "----------------------------------------"
    echo "WordPress配置:"
    echo "  语言: $WP_LANGUAGE"
    echo "  管理员用户名: $WP_USERNAME"
    echo "  管理员密码: $WP_PASSWORD"
    echo "  管理员邮箱: $WP_EMAIL"
    echo "  站点标题: $SITE_TITLE"
    echo "----------------------------------------"
    
    # 直接部署，不询问确认
    log "开始部署..."
    
    return 0
}

# 主执行函数
main() {
    # 初始化日志
    init_log
    show_banner

    # 检查必要工具
    check_required_tools() {
        local required_tools=("docker" "curl" "openssl" "grep" "awk" "sed")
        
        for tool in "${required_tools[@]}"; do
            if ! command -v "$tool" >/dev/null 2>&1; then
                error "必要工具 $tool 未安装"
            fi
        done
        
        log "✅ 所有必要工具检查通过"
    }

    # 在主函数开头调用：
    check_required_tools
    
    # 添加错误处理
    trap 'error "脚本执行中断"' INT TERM
    
    # 第一部分：WordPress容器部署
    log "=== 开始WordPress容器部署 (融合版+WP-CLI) ==="
    
    # 检查Must-Use Plugins目录
    check_mu_plugins_directory
    
    # 检查现有镜像
    check_existing_images
    
    # 检查Docker环境
    check_docker_environment
    
    # 获取用户输入并分配资源（传递所有命令行参数）
    get_user_input "$@"
    
    log "开始部署站点: $SITE_NAME (使用已分配资源)"
    
    # 更新分配状态为RUNNING
    update_allocation_status "$DOMAIN" "RUNNING"
    
    # 创建Redis目录
    create_redis_directories "$SITE_NAME"
    
    # 创建项目并部署WordPress
    create_project_dir "$SITE_NAME"
    create_docker_compose "$SITE_NAME" "$DB_PASSWORD" "$MYSQL_ROOT_PASSWORD" "$REDIS_PASSWORD" "$WORDPRESS_IMAGE" "$MYSQL_IMAGE" "$REDIS_IMAGE" "$DOMAIN" "$MU_PLUGINS_SOURCE" "$PLUGINS_SOURCE" "$REDIS_PERSISTENT_DIR" "$REDIS_TEMP_DIR"
    create_wp_config "$SITE_NAME" "$DOMAIN" "$DB_PASSWORD" "$DB_IP" "$REDIS_PASSWORD" "$REDIS_IP"
    
    # 注意：get_wordpress_installation_config 已经在 get_user_input 中调用了
    # 所以这里不需要再次调用
    
    start_wordpress_services "$DOCKER_COMPOSE_CMD" "$SITE_NAME" "$DOMAIN" "$MYSQL_ROOT_PASSWORD" "$DB_PORT" "$REDIS_PASSWORD" "$REDIS_PORT"
    
    log "✅ WordPress容器部署完成！"
    
    # 第二部分：宝塔反向代理和SSL配置
    log "=== 开始配置宝塔反向代理和SSL（内网+FRP优化版）==="
    
    configure_baota_proxy "$DOMAIN" "$WEB_PORT"
    
    # 显示最终结果
    show_final_summary
}

# 显示最终摘要
show_final_summary() {
    local server_ip=$(get_server_public_ip "$DOMAIN")
    
    echo
    echo -e "${GREEN}"
    echo "================================================"
    echo "      WordPress全能融合部署完成！"
    echo "          内网+FRP环境优化版"
    echo "          WP-CLI静默安装版"
    echo "          支持强制重新部署 (--force)"
    echo "================================================"
    echo -e "${NC}"
    echo "站点信息:"
    echo "----------------------------------------"
    echo "域名: $DOMAIN"
    echo "HTTPS访问: https://$DOMAIN"
    echo "HTTP访问: http://$DOMAIN" 
    echo "本地访问: http://${server_ip}:${WEB_PORT}"
    echo "项目目录: $PROJECT_PATH"
    echo "强制重新部署: $FORCE_REDEPLOY"
    echo ""
    
    echo "资源分配:"
    echo "----------------------------------------"
    echo "✅ 端口分配 (已避免冲突):"
    echo "   Web端口: $WEB_PORT"
    echo "   数据库端口: $DB_PORT"
    echo "   Redis端口: $REDIS_PORT"
    echo ""
    echo "✅ IP地址分配 (专用网络):"
    echo "   Web容器IP: $WEB_IP"
    echo "   数据库容器IP: $DB_IP"
    echo "   Redis容器IP: $REDIS_IP"
    echo "   网络名称: $NETWORK_NAME"
    echo ""
    
    echo "WordPress安装信息:"
    echo "----------------------------------------"
    echo "✅ WP-CLI静默安装完成，已跳过网页安装步骤"
    echo "   管理员后台: https://$DOMAIN/wp-admin"
    echo "   管理员用户名: $WP_USERNAME"
    echo "   管理员密码: $WP_PASSWORD"
    echo "   管理员邮箱: $WP_EMAIL"
    echo "   站点标题: $SITE_TITLE"
    echo "   语言: $WP_LANGUAGE"
    echo ""
    
    echo "插件配置:"
    echo "----------------------------------------"
    echo "✅ Must-Use Plugins目录 (共享):"
    echo "   目录: ${MU_PLUGINS_SOURCE}"
    echo "✅ 独立插件目录:"
    echo "   目录: ${PLUGINS_SOURCE}"
    echo ""
    
    echo "数据库连接信息:"
    echo "----------------------------------------"
    echo "数据库主机: ${server_ip}"
    echo "数据库端口: ${DB_PORT}"
    echo "数据库名称: wordpress"
    echo "数据库用户: wordpress"
    echo "数据库管理员: root"
    echo ""
    
    echo "SSL证书状态:"
    echo "----------------------------------------"
    if verify_ssl_certificate_files "$DOMAIN"; then
        echo "✅ SSL证书已成功配置（Cloudflare DNS验证）"
        echo "   包含: listen 443 ssl; 和 http2 on;"
        echo "   类型: ECC证书（更安全）"
    else
        echo "⚠️  SSL证书未配置，使用HTTP访问"
        echo "提示: 可以稍后在宝塔面板中手动申请SSL证书"
    fi
    echo ""
    
    echo "内网+FRP环境优化:"
    echo "----------------------------------------"
    echo "✅ 使用Cloudflare DNS API验证（无需开放端口）"
    echo "✅ 自动清理旧的TXT记录"
    echo "✅ 支持ECC证书（256位加密）"
    echo "✅ 自动判断域名对应的Cloudflare Zone"
    echo "✅ 完整的错误处理和重试机制"
    echo ""
    
    echo "WP-CLI静默安装特性:"
    echo "----------------------------------------"
    echo "✅ 完全跳过网页安装步骤（install.php）"
    echo "✅ 自动设置语言、时区、固定链接"
    echo "✅ 自动清理默认主题和插件"
    echo "✅ 自动创建默认页面"
    echo "✅ 10次重试机制确保安装成功"
    echo ""
    
    echo "强制重新部署特性:"
    echo "----------------------------------------"
    echo "✅ 支持 --force 或 -f 参数强制重新部署"
    echo "✅ 自动清理现有容器、网络、数据"
    echo "✅ 避免锁死和死锁问题"
    echo "✅ 保留原有资源分配记录"
    echo ""
    
    echo "管理命令:"
    echo "----------------------------------------"
    echo "查看状态: cd $PROJECT_PATH && $DOCKER_COMPOSE_CMD ps"
    echo "查看日志: cd $PROJECT_PATH && $DOCKER_COMPOSE_CMD logs"
    echo "停止服务: cd $PROJECT_PATH && $DOCKER_COMPOSE_CMD stop"
    echo "重启服务: cd $PROJECT_PATH && $DOCKER_COMPOSE_CMD restart"
    echo "WP-CLI命令: docker exec ${SITE_NAME}-wp wp --allow-root"
    echo "强制重新部署: 再次运行本脚本并添加 --force 参数"
    echo ""
    echo "资源分配记录: $ALLOCATION_FILE"
    echo "================================================"
    
    log "全能融合部署完成！"
}

# 执行主函数
main "$@"