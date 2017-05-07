<?php
/**
 * QsMail Server
 * 基于Swoole的Mail邮件服务器
 *
 * @author      青石 <www@qs5.org>
 * @copyright   QsMail Server
 */

/**
 * Mail服务基类
 */
class Mail_Server
{

    /**
     * 框架版本号
     * @var string
     */
    private $ver = '0.1.1718.m1b';

    /**
     * 运行时状态 / 是否后台运行
     * @var boolean
     */
    private $isRunStatus = false;

    /**
     * Swoole服务对象
     * @var swoole_server
     */
    private $serv;

    /**
     * 错误代码
     * @var [type]
     */
    private $error_no;

    /**
     * 错误描述
     * @var [type]
     */
    private $error_msg;

    /**
     * 协议类
     * @var array
     */
    private $class_list = array();

    /**
     * 运行时用户信息暂存
     * @var array
     */
    private $cli_pool = array();

    /**
     * 用户数据临时存放目录
     * @var string
     */
    private $usr_tmp_data_path = '/dev/shm/qsmail_usrdata/';

    /**
     * 初始化函数
     * @param boolean $isRun    是否后台运行
     * @param string  $log_file 输出日志文件地址
     */
    function __construct($isRun, $log_file)
    {
        // 保存运行时状态
        $this->isRunStatus = $isRun;

        // 修改进程名
        swoole_set_process_name("qsmail server start");

        // 创建 Swoole 对象
        $this->serv = new swoole_server('127.0.0.1', false);

        // 设置默认值
        $this->serv->set(array(
            'daemonize'                => $this->isRunStatus,    // 守护进程化 设置为 true 则后台运行
            'log_file'                 => $log_file,   // 日志文件
            'open_eof_check'           => true,     // 打开buffer缓冲区
            'package_eof'              => "\r\n",   // 设置EOF
            'heartbeat_check_interval' => 30,       // 每隔多少秒检测一次，单位秒
            'heartbeat_idle_time'      => 60 * 1000,       // TCP连接的最大闲置时间
        ));

        // 设置自动加载类
        spl_autoload_register(function($class_name)
        {
            // 定义可以加载的文件列表
            $file_list = array(
                APP_ROOT . "Protocol/{$class_name}.class.php",
                APP_ROOT . "{$class_name}.class.php",
                APP_ROOT . $class_name . '.php',
            );
            // 挨个测试是否可以加载
            foreach ($file_list as $file_name) {
                if(file_exists($file_name)){
                    include $file_name;
                    return true;
                }
            }
            return false;
        });

        // 注册回调事件
        $on_list = array(
            'Start',        // 服务器 启动
            'Shutdown',     // 服务器 停止
            'ManagerStart', // 管理进程 创建
            'ManagerStop',  // 管理进程 停止
            'WorkerStart',  // 服务进程 启动
            'WorkerStop',   // 服务进程 停止
            'WorkerError',  // 服务进程 异常回调
            'Connect',      // 有新连接
            'Receive',      // 收到消息
            'Close',        // 连接断开
            // 'Task',         // 任务被调用
            // 'Finish',       // 任务完成
            // 'PipeMessage',  // 收到管道进程消息
            // 'Timer',        // 定时器 触发
        );
        // 遍历注册每个回调事件
        foreach ($on_list as $name) {
            // 生成方法名
            $method_name = "on{$name}";
            // 方法存在才注册
            if(method_exists($this, $method_name)){
                $this->serv->on($name, array($this, $method_name));
            }
        }

        // 初始化临时data目录
        if(!file_exists($this->usr_tmp_data_path)) mkdir($this->usr_tmp_data_path);
        // 清空目录
        array_map('unlink', glob($this->usr_tmp_data_path . '*'));
    }

    /**
     * 设置错误信息
     * @param [type] $error_no  [description]
     * @param [type] $error_msg [description]
     */
    public function setError($error_no, $error_msg)
    {
        $this->error_no  = $error_no;
        $this->error_msg = $error_msg;
    }

    /**
     * 获取错误代码
     * @return [type] [description]
     */
    public function getError()
    {
        return !$this->error_no
        ? false
        :array(
            'no'  => $this->error_no,
            'msg' => $this->error_msg
        );
    }

    /**
     * 设置选项
     * @param string $value [description]
     */
    public function set($name, $value = null)
    {
        // 判断传参类型
        if(is_array($name)){
            foreach ($name as $key => $val) {
                $this->saveConfig($key, $val);
            }
        } else {
            $this->saveConfig($name, $value);
        }
    }

    /**
     * 保存配置信息
     * @param  string $value [description]
     * @return [type]        [description]
     */
    private function saveConfig($name, $value)
    {
        // 对象存在就设置对象 否则设置 $serv
        if(isset($this->$name)){
            $this->$name = $value;
        } else {
            // 设置serv设置
            $this->serv->setting[$name] = $value;
            $this->serv->set($this->serv->setting);
        }
    }

    /**
     * 保存并读取用户信息
     * @param  string  $fd    用户fid
     * @param  string  $key   键名 为空获取所有 为false则清空数据 为数组则直接保存
     * @param  mixed   $value 需要储存的值 可以是任意类型
     * @return mixed          返回储存的值 删除则返回true
     */
    public function userData($fd, $key = null, $value = null)
    {
        // 储存文件名
        $file_name = $this->usr_tmp_data_path . 'u_' . $fd;

        // 初始化数据 不存在则尝试从文件或空值初始化
        if(!isset($this->cli_pool[$fd])){
            $this->cli_pool[$fd] = file_exists($file_name) ? json_decode(file_get_contents($file_name), true) : null;
        }

        // key 为 false 说明删除数据
        if($key === false){
            if(isset($this->cli_pool[$fd])) unset($this->cli_pool[$fd]);
            return file_exists($file_name) && unlink($file_name);
        } else
        // key 为空则返回所有数据
        if($key === null){
            return $this->cli_pool[$fd];
        } else
        // key 为字符串 且 未传递值 则只获取
        if(is_string($key) && $value === null){
            return isset($this->cli_pool[$fd][$key]) ? $this->cli_pool[$fd][$key] : null;
        }
        // key 为数组则覆盖保存
        if(is_array($key)){
            $this->cli_pool[$fd] = $key;
        } else
        // 为字符串 且 传递值 则修改
        if(is_string($key) && $value !== null){
            $this->cli_pool[$fd][$key] = $value;
        } else {
            // 未知状态...
            return false;
        }

        // 保存数据到文件
        file_put_contents($file_name, json_encode($this->cli_pool[$fd]));

        // 返回数据
        return is_array($key) ?: $this->cli_pool[$fd][$key];
    }

    /**
     * 启动项目
     * @return [type] [description]
     */
    public function start()
    {

        // 遍历每个协议并处理
        foreach ($this->class_list as $port => $classInfo) {
            // 获取处理类名
            $type_name = $classInfo['class'];

            // 监听并获取错误
            if(!$this->listenList[$type_name] = $this->serv->listen($classInfo['host'], $port, $classInfo['type'])){
                $this->setError(1, 'Add Listen Failed. Address already in use.');
                return;
            }
        }

        // 启动服务
        $this->serv->start();
    }

    /**
     * 项目被启动
     * @param  swoole_server $serv [description]
     */
    public function onStart(swoole_server $serv)
    {
        // 获取启动时间
        $timeStr = date('Y/m/d H:i:s');

        // 如果是后台运行就重新保存pid
        $this->isRunStatus && file_put_contents(RUN_PID_FILE, $serv->master_pid);

        // 输出运行信息
        printf(
            "[MainStart] %s\n\tmaster_pid: %s\n\tRand Port: %s\n",
            $timeStr,
            $serv->master_pid,
            $serv->port
        );

        // 修改进程名
        swoole_set_process_name("qsmail server master");
    }

    /**
     * 管理进程被启动
     * @param  swoole_server $serv [description]
     * @return [type]              [description]
     */
    public function onManagerStart(swoole_server $serv)
    {
        // 获取启动时间
        $timeStr = date('Y/m/d H:i:s');

        // 输出运行信息
        printf(
            "[ManagerStart] %s\n\tmanager_pid: %s\n",
            $timeStr,
            $serv->manager_pid
        );

        // 修改进程名
        swoole_set_process_name("qsmail server manager");
    }

    /**
     * 工作进程启动
     * @param  swoole_server $serv      [description]
     * @param  [type]        $worker_id [description]
     * @return [type]                   [description]
     */
    public function onWorkerStart(swoole_server $serv, $worker_id)
    {
        // 获取启动时间
        $timeStr = date('Y/m/d H:i:s');

        // 重命名进程名
        if($serv->taskworker) {
            swoole_set_process_name("qsmail server task worker");
        } else {
            swoole_set_process_name("qsmail server event worker");
        }

        // 创建应用对象
        $this->Mail_App = new Mail_App($serv);

        // 输出调试信息
        $print_str = sprintf(
            "[WorkerStart: %s] %s\n\tworker_pid: %s\n\tMian Ver: %s\n\tApp Ver: %s\n",
            $worker_id,
            $timeStr,
            $serv->worker_pid,
            $this->ver,
            $this->Mail_App->ver()
        );

        // 输出各个协议的情况
        foreach ($this->class_list as $port => $classInfo) {
            // 获取处理类名
            $type_name = $classInfo['class'];

            // 创建处理的对象
            $class_name = "{$type_name}_Server";
            isset($this->$type_name) ?: $this->$type_name = new $class_name($this->Mail_App);

            // 打印对象信息
            $print_str.= sprintf(
                "\t%s Ver: %s\n",
                $type_name,
                $this->$type_name->ver()
            );
        }

        // 输出内容 只在线程0 输出版本信息
        if($worker_id == 0){
            echo $print_str;
        }

        // 注册一个值
        $this->worker_id = $worker_id;
    }

    /**
     * 有客户端连接进入
     * @param  swoole_server $serv    [description]
     * @param  int           $fd      [description]
     * @param  int           $from_id [description]
     * @return [type]                 [description]
     */
    public function onConnect(swoole_server $serv, $fd, $from_id)
    {
        // 获取客户端详细信息
        $cliInfo = $serv->connection_info($fd, $from_id);

        // 创建消息记录数组
        $class_name = $this->class_list[$cliInfo['server_port']]['class'];
        $user_data = array(
            'username'    => "u_{$fd}", // 临时用户名
            'client_ip'   => $cliInfo['remote_ip'],     // 客户端IP
            'client_port' => $cliInfo['remote_port'],   // 客户端端口
            'class_type'  => $class_name,       // 使用协议版本
        );

        // 输出客户端信息
        IS_DEBUG && printf(
            "[Connect] (%s) %s => %s:%s\n",
            $user_data['class_type'],
            $user_data['username'],
            $user_data['client_ip'],
            $user_data['client_port']
        );

        // 调用方法处理
        $ret_msg = $this->$class_name->onConnect($this, $fd, $user_data);

        // 输出调试记录
        IS_DEBUG && printf('(%s) %s: S > %s', $user_data['class_type'], $user_data['username'], $ret_msg);

        // 保存用户信息
        $this->userData($fd, $user_data);

        // 消息发回客户端
        $this->sendMsg($fd, $ret_msg);
    }

    /**
     * 断开连接
     * @param  swoole_server $serv      [description]
     * @param  int           $fd        [description]
     * @param  int           $reactorId [description]
     * @return [type]                   [description]
     */
    public function onClose(swoole_server $serv, $fd, $reactorId)
    {
        // 获取用户信息
        $user_data = $this->userData($fd);

        // 输出调试记录
        IS_DEBUG && printf("[Close] %s\n", $fd);

        // 方法存在就调用处理
        if(method_exists($this->$user_data['class_type'], 'onClose')){
            $this->$user_data['class_type']->onClose($this, $fd, $user_data);
        }

        // 删除消息记录
        $this->userData($fd, false);
    }

    /**
     * 收到消息
     * @param  swoole_server $serv    [description]
     * @param  int           $fd      [description]
     * @param  int           $from_id [description]
     * @param  string        $data    [description]
     * @return [type]                 [description]
     */
    public function onReceive(swoole_server $serv, $fd, $from_id, $data)
    {
        // 获取用户信息
        $user_data = $this->userData($fd);

        // 输出调试记录
        IS_DEBUG && printf('(%s) %s: C < %s', $user_data['class_type'], $user_data['username'], $data);

        // 转发给类处理
        $ret_msg = $this->$user_data['class_type']->onReceive($this, $fd, $data, $user_data);

        // 保存用户信息
        $this->userData($fd, $user_data);

        // 返回了数组就特别处理一下
        $close = false;
        if(is_array($ret_msg)){
            $close   = !empty($ret_msg['close']);
            $ret_msg = isset($ret_msg['msg']) ? $ret_msg['msg'] : '';
        }

        // 有返回消息就发送给客户端
        if($ret_msg){
            // 输出调试记录
            IS_DEBUG && printf('(%s) %s: S > %s', $user_data['class_type'], $user_data['username'], $ret_msg);
            $this->sendMsg($fd, $ret_msg);
        }

        // 如果有设置关闭
        if($close){
            $serv->tick(1000, function() use ($serv, $fd) {
                $serv->close($fd);
            });
        }
    }

    /**
     * 发送消息到客户端
     * @param  string  $msg    消息内容
     * @param  boolean $status 消息状态
     * @return [type]          [description]
     */
    public function sendMsg($fd, $msg)
    {
        // 发送消息到客户端
        return $this->serv->send($fd, $msg);
    }
}
