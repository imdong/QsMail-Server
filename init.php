<?php
/**
 * QsMail Server
 * 基于Swoole的邮件服务器
 *
 * @author      青石 <www@qs5.org>
 * @copyright   QsMail Server
 */

//**** 输出版本相关信息 ****//
echo "+-----------------------+\n";
echo "|     QsMail Server     |\n";
echo "+-----------------------+\n";
echo "|   http://www.qs5.org  |\n";
echo "+-----------------------+\n";

//**** 启动时配置 ****//

    // pid保存文件名
    define('RUN_PID_FILE', '/var/run/qsmail_server.pid');

    // 定义根目录
    define('ROOT_PATH', dirname(__FILE__) . '/');

    // 定义应用目录
    define('APP_ROOT', ROOT_PATH . 'app/');

    // 日志文件目录
    define('LOG_PATH', ROOT_PATH . 'logs/');

    // 设置调试模式 先定义等下写静态
    $is_debug = true;

//**** 运行前判断 单例模式 ****//

    // 判断是否 cli 运行
    if(php_sapi_name() != 'cli') die('Please use cli Mode to Start!');

    // 判断是否已经运行
    if(file_exists(RUN_PID_FILE)){
        // 判断进程是否存在
        $run_pid = file_get_contents(RUN_PID_FILE);
        if(file_exists("/proc/{$run_pid}/")){
            die("is Runing, pid: {$run_pid}\n");
        }
    }

    // 保存当前进程pid 感觉用不到
    $run_pid = posix_getpid();
    file_put_contents(RUN_PID_FILE, $run_pid) || die("save pid File Error.\n");

    // 打印启动信息
    printf(
        "[RunInit] %s\n\tRun Pid: %s\n",
        date('Y/m/d H:i:s'),
        $run_pid
    );

//**** 运行前初始化 ****//

    // 判断是否传递后台运行命令
    $is_run = !empty($argv['1']) && $argv['1'] == 'start';

    // 判断是否传入日志文件名
    if(empty($argv['2'])){
        // 判断日志文件夹是否存在
        file_exists(LOG_PATH) || mkdir(LOG_PATH);
        $log_file = sprintf(LOG_PATH . '%s_%s.log', date('Ymd'), $run_pid);
    } else{
        $log_file = $argv['2'];
    }

    // 根据运行情况设置调试模式
    define('IS_DEBUG', !$is_run && $is_debug );

//**** 启动进程 ****//

    // 引入进程文件
    require APP_ROOT . 'Mail_Server.class.php';

    // 启动服务器
    $mail = new Mail_Server($is_run, $log_file);

    // 设置监听协议
    $mail->set('class_list',
        array(
            '25' => array(
                'host' => '0.0.0.0',
                'type' => SWOOLE_SOCK_TCP,
                'class' => 'Smtp'
            ),
            '110' => array(
                'host' => '0.0.0.0',
                'type' => SWOOLE_SOCK_TCP,
                'class' => 'Pop3'
            )
        )
    );

    // 其他设置
    $mail->set(
        array(
            'worker_num' => 2,  // worker进程数
        )
    );

    // 启动服务器 并检测错误
    if(!$mail->start()){
        if($error_info = $mail->getError()){
            printf(
                "[RunError] (%s): %s\n",
                $error_info['no'],
                $error_info['msg']
            );
            return;
        }
    }

//**** 结束前处理 ****//

    // 删除pid文件
    unlink(RUN_PID_FILE);

    // 输出结果
    echo "Process Exit";
