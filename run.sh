#!/bin/bash
# Swoole Reload Demo
# Swoole 热更新 示例

# php主程序文件名
script_name='init.php'
# pid文件 请和 php 内设置一致
run_pid_file='/tmp/swoole_reload_demo.pid'
# 运行日志保存文件夹
run_log_path="${init_dir}/logs"
date_str=`date +%Y%m%d`
init_dir="$(cd "$(dirname "$0")" && pwd )"
script_path="${init_dir}/${script_name}"
log_file="${run_log_path}/${date_str}_$$.log"

# 判断是否运行中 并获取进程pid
is_run(){
    # 先判断pid文件是否存在
    if [ -f "${run_pid_file}" ]; then
        # 判断进程是否存在
        run_pid=`cat ${run_pid_file}`
        if [ -d "/proc/${run_pid}" ]; then
            return 1
        fi
    fi
    # 否则返回0
    return 0
}

# 启动脚本
cmd_isStart(){
    # 先判断是否运行中
    is_run
    if [ "$?" = "1" ]; then
        echo "is Runing, pid: ${run_pid}"
        return
    fi
    nohup php "${script_path}" start ${log_file} > ${log_file} 2>&1 &
    echo "[$$] Start Ok!"
}

# 运行状态
cmd_isStatus(){
    # 先判断是否运行中
    is_run
    if [ "$?" = "1" ]; then
        echo "is Runing, pid: ${run_pid}"
        return 1
    else
        echo "is Not Run!"
        return 0
    fi
}

# 重新加载脚本
cmd_isReload(){
    # 先判断是否运行中
    is_run
    if [ "$?" = "1" ]; then
        kill -USR1 ${run_pid}
        return 1
    else
        echo "is Not Run!"
        return 0
    fi
}

# 重新启动脚本
cmd_isRestart(){
    # 停止脚本
    cmd_isStop
    # 启动脚本
    cmd_isStart
}

# 结束进程
cmd_isStop(){
    # 先判断是否运行中
    is_run
    if [ "$?" = "1" ]; then
        kill -15 ${run_pid}
        if [ "$?" = "0" ]; then
            echo -e "stop ${run_pid}\c"
            # 判断进程是否结束
            while [ -d "/proc/${run_pid}" ]
            do
                echo -e ".\c"
                sleep 0.1
            done
            echo "success"
            return 1
        else
            echo "stop pid: ${run_pid} error."
            return 0
        fi
    else
        echo "is Not Run!"
        return 1
    fi
}

# 强制杀死进程 不建议
cmd_isKill(){
    # 先判断是否运行中
    is_run
    if [ "$?" = "1" ]; then
        kill -9 ${run_pid}
        if [ "$?" = "0" ]; then
            echo -e "kill ${run_pid}\c"
            # 判断进程是否结束
            while [ -d "/proc/${run_pid}" ]
            do
                echo -e ".\c"
                sleep 0.1
            done
            echo "success"
            return 1
        else
            echo "kill pid: ${run_pid} error."
            return 0
        fi
    else
        echo "is Not Run!"
        return 1
    fi
}

# 判断日志文件夹是否存在
if [ ! -d "${run_log_path}" ]; then
    mkdir ${run_log_path}
fi

# 判断命令
case "$1" in
    "start")   cmd_isStart ;;
    "status")  cmd_isStatus ;;
    "reload")  cmd_isReload ;;
    "restart") cmd_isRestart ;;
    "stop")    cmd_isStop ;;
    "kill")    cmd_isKill ;;
    *)
        echo -e "+--------------------------+\n|    Swoole Reload Demo    |\n+--------------------------+\n|    http://www.qs5.org    |\n+--------------------------+"
        echo "Usage: $0 {start|status|restart|reload|stop|kill}"
    ;;
esac
