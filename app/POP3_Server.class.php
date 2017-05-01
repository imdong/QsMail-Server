<?php
/**
 * QsMail POP3 Server
 * 基于Swoole的POP3邮件服务器
 *
 * @author      青石 <www@qs5.org>
 * @copyright   QsMail POP3 Server
 */

/**
 * POP3服务基类
 */
class POP3_Server
{

    /**
     * 框架版本号
     * @var string
     */
    private $ver = '0.1.1718.1a';

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
        $this->serv = new swoole_server("0.0.0.0", 110);

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
        // 获取启动时间
        $timeStr = date('Y/m/d H:i:s');

        // 如果是后台运行就重新保存pid
        $this->isRunStatus && file_put_contents(RUN_PID_FILE, $serv->master_pid);

        // 输出运行信息
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

        // 输出调试信息
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
            'client_port' => $cliInfo['remote_port'],   // 客户端端口
            'status'      => 'AUTHORIZATION'    // 用户状态 TRANSACTION UPDATE
        );

        // 输出客户端信息
        IS_DEBUG && printf(
            "[Connect] %s => %s:%s\n",
            $this->cli_pool[$fd]['username'],
            $this->cli_pool[$fd]['client_ip'],
            $this->cli_pool[$fd]['client_port']
        );

        // 消息内容
        $retMsg = sprintf(
            'Hello [%s], Welcome To QsMail POP3 Server!',
            $this->cli_pool[$fd]['username']
        );

        // 回复客户端可以继续
        $this->sendMsg($fd, $retMsg);
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
        // 取出第一条命令处理
        $dataRow = explode("\r\n", $datas)['0'];

        // 取出方法名
        $retMsg = "Unknown command!\r\n";
        if(preg_match('#^(?<cmd>[A-Za-z0-9_]+)(\s(?<par>[^$]+))?$#', $dataRow, $cmdInfo)){
            $cmd = strtoupper(trim($cmdInfo['cmd']));
            $par = empty($cmdInfo['par']) ? '' : $cmdInfo['par'];

            // 输出调试记录
            IS_DEBUG && printf(
                "[%s] %s %s\n",
                $this->cli_pool[$fd]['username'],
                $cmd,
                $par
            );

            // 确认状态时
            if( 'AUTHORIZATION' == $this->cli_pool[$fd]['status'] ){
                // 判断参数是否正确
                $retStatus = false;
                switch ($cmd) {
                    case 'USER':
                        if(empty($par)){
                            $retMsg = 'Missing argument';
                        } else {
                            $this->cli_pool[$fd]['user'] = $par;
                            $retStatus = true;
                            $retMsg = 'core mail';
                        }
                        break;
                    case 'PASS':
                        if(empty($par)){
                            $retMsg = 'Missing argument';
                        } else {
                            $this->cli_pool[$fd]['pass'] = $par;
                            // 验证用户名密码是否正确
                            $loginRet = App::login($this->cli_pool[$fd]['user'], $this->cli_pool[$fd]['pass']);
                            if($loginRet){
                                $retStatus = true;
                                $retMsg = 'login success.';
                            } else {
                                $retMsg = '[AUTH] Invalid login';
                            }

                        }
                        break;
                    // case 'APOP':
                    //     if(empty($par)){
                    //         $retMsg = 'Missing argument';
                    //     } else {
                    //         $this->cli_pool[$fd]['user'] = $par;
                    //         $retStatus = true;
                    //         $retMsg = 'core mail';
                    //     }
                    //     break;
                    default:
                        $retMsg = 'Unrecognized command';
                        break;
                }
            } else
            // 操作状态
            if( 'TRANSACTION' == $this->cli_pool[$fd]['status'] ){

            }

            // 发送消息到客户端
            $this->sendMsg($fd, $retMsg, $retStatus);


            // 判断方法是否存在
            if(is_callable(array('App', $cmd), false, $callable_name)){
                // 调用方法
                $retMsg = call_user_func($callable_name, $par);
            }
        }

    }

    /**
     * 发送消息到客户端
     * @param  string  $msg    消息内容
     * @param  boolean $status 消息状态
     * @return [type]          [description]
     */
    public function sendMsg($fd, $msg, $status = true)
    {
        // 拼接生成消息
        $msgData = sprintf("%s %s\r\n", $status ? '+OK ' : '-ERR ', $msg);

        // 发送消息到客户端
        $this->serv->send($fd, $msgData);
    }


}
