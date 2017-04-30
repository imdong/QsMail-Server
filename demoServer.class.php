<?php
/**
 * Swoole 热更新功能 简单示例
 *
 * @author      青石 <www@qs5.org>
 * @copyright   Swoole Reload Demo 2017-4-30 09:47:10
 */

/**
 * 主功能类
 */
class Demo_Server
{

    private $ver = '1.0.m170430.b';

    /**
     * 运行时状态 / 是否后台运行
     * @var boolean
     */
    private $isRunStatus = false;

    /**
     * Swoole服务对象
     * @var [type]
     */
    private $serv;

    /**
     * 初始化函数
     */
    function __construct($isRun, $log_file)
    {
        // 保存运行时状态
        $this->isRunStatus = $isRun;

        // 创建 Swoole 对象
        $this->serv = new swoole_server("0.0.0.0", 1082);

        // 设置默认设置
        $this->serv->set(array(
            'daemonize'                => $this->isRunStatus,    // 守护进程化 设置为 true 则后台运行
            'log_file'                 => $log_file,   // 日志文件
            'open_eof_check'           => true,     // 打开buffer缓冲区
            'package_eof'              => "\r\n",   // 设置EOF
        ));

        // 注册回调事件
        $this->serv->on('Start', array($this, 'onStart'));      // 服务器被启动
        $this->serv->on('WorkerStart', array($this, 'onWorkerStart'));      // 工作进程被启动
        $this->serv->on('Connect', array($this, 'onConnect'));  // 客户端有连接
        $this->serv->on('Receive', array($this, 'onReceive'));  // 收到客户端消息
        $this->serv->on('Close', array($this, 'onClose'));      // 客户端断开连接

        // 启动服务
        $this->serv->start();
    }

    // 进程启动
    public function onStart($serv)
    {
        $timeStr = date('Y/m/d H:i:s');

        // 如果是后台运行就重新保存pid
        $this->isRunStatus && file_put_contents(RUN_PID_FILE, $serv->master_pid);

        printf(
            "[Start] %s master_pid: %s\n",
            $timeStr,
            $serv->master_pid
        );
    }

    // 工作进程启动
    public function onWorkerStart($serv, $worker_id)
    {
        // 获取启动时间
        $timeStr = date('Y/m/d H:i:s');

        // 引入应用类文件
        include APP_ROOT . 'app.class.php';

        printf(
            "[WorkerStart: %s] %s master_pid: %s\nMian Ver: %s\nClass Ver: %s\n",
            $worker_id,
            $timeStr,
            $serv->master_pid,
            $this->ver,
            App::ver()
        );

    }

    // 有客户端连接
    public function onConnect($serv, $fd, $from_id)
    {
        // 获取客户端详细信息
        $cliInfo = $serv->connection_info($fd, $from_id);

        // 创建消息记录数组
        $this->cli_pool[$fd] = array(
            'username'    => "u_{$fd}", // 临时用户名
            'client_ip'   => $cliInfo['remote_ip'],     // 客户端IP
            'client_port' => $cliInfo['remote_port']    // 客户端端口
        );

        // 输出客户端信息
        IS_DEBUG && printf(
            "[Connect] %s | %s:%s => %s\n",
            $fd,
            $this->cli_pool[$fd]['client_ip'],
            $this->cli_pool[$fd]['client_port'],
            $this->cli_pool[$fd]['username']
        );

        // 回复客户端可以继续
        $serv->send($fd, "Hello [{$this->cli_pool[$fd]['username']}], Welcome!\r\n");
    }

    // 断开连接
    public function onClose($serv, $fd, $from_id)
    {
        // 输出调试记录
        IS_DEBUG && printf("[Close] %s\n", $fd);

        // 删除消息记录
        unset($this->cli_pool[$fd]);
    }

    // 收到消息
    public function onReceive(swoole_server $serv, $fd, $from_id, $datas)
    {
        // 始终假设用户发来的是多行数据
        $dataArr = explode("\r\n", $datas);

        // 取出第一条命令处理
        $dataRow = $dataArr['0'];

        // 取出方法名
        $retMsg = "Unknown command!\r\n";
        if(preg_match('#^(?<cmd>[A-Za-z0-9_]+)(\s(?<par>[^$]+))?$#', $dataRow, $cmdInfo)){
            $cmd = 'cmd_' . trim($cmdInfo['cmd']);
            $par = empty($cmdInfo['par']) ? '' : $cmdInfo['par'];

            // 输出调试记录
            IS_DEBUG && printf(
                "[%s] %s\n",
                $this->cli_pool[$fd]['username'],
                $cmdInfo['cmd']
            );

            // 判断方法是否存在
            if(is_callable(array('App', $cmd), false, $callable_name)){
                // 调用方法
                $retMsg = call_user_func($callable_name, $par);
            }
        }

        // 发送消息到客户端
        $serv->send($fd, $retMsg);
    }
}
