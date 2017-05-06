<?php
/**
 * QsMail POP3 Server
 * 基于Swoole的POP3邮件服务器
 *
 * @author      青石 <www@qs5.org>
 * @copyright   QsMail POP3 Server
 */

/**
 * 应用功能类 这里的代码支持热更新
 */
class Mail_App
{
    /**
     * Swoole服务对象
     * @var swoole_server
     */
    private $serv;

    /**
     * 配置信息
     * @var array
     */
    private $config;

    /**
     * 数据库连接
     * @var MysqliDb
     */
    private $db;

    /**
     * 数据库操作锁
     * @var [type]
     */
    private $db_look;


    /**
     * 程序应用版本号
     * @var string
     */
    private $ver = '0.1.1718.a1c';

    /**
     * 初始化函数
     */
    function __construct($serv)
    {
        // 保存服务对象
        $this->serv = $serv;

        // 引入配置文件
        $this->config = require ROOT_PATH . 'config.php';

        // 连接到数据库
        $this->db_connect();

        // 创建数据库操作锁
        $this->db_look = new swoole_lock(SWOOLE_SPINLOCK);

    }

    /**
     * 获取版本号
     * @return [type] [description]
     */
    public function ver()
    {
        return $this->ver;
    }

    /**
     * 连接到数据库
     * @return [type] [description]
     */
    public function db_connect()
    {
        // 连接到数据库
        $this->db = new MySqli(
            $this->config['DB_CONFIG']['host'],
            $this->config['DB_CONFIG']['username'],
            $this->config['DB_CONFIG']['password'],
            $this->config['DB_CONFIG']['db']
        );

        // 连接失败则报错
        if($this->db->connect_error){
            printf("MySQLi Connect Error(%s): %s\n",
                $this->db->connect_errno,
                $this->db->connect_error
            );
            // 退出线程
            $this->serv->shutdown();
        }

        return true;
    }

    /**
     *
     * @param  string $sql 查询语句
     * @return [type]      [description]
     */
    /**
     * 执行一次sql查询
     * @param  string $sql  查询语句
     * @param  string $type 查询类型 如果设置就尝试处理结果
     * @return [type]       [description]
     */
    public function db_query($sql, $type = false)
    {
        // 操作加锁
        $this->db_look->lock();

        // 创建事务

        // 执行sql查询
        $result = $this->db->query($sql);

        // 如果出错是因为超时
        if(!$result && in_array($this->db->errno, array(2006, 2013))){
            // 连接到数据库
            $this->mysqlConnect();
            // 插入到数据库
            $result = $this->db->query($sql);
        }

        // 查询失败或没设置类型就直接返回
        if(!$result || !$type) {
            // 取消锁
            $this->db_look->unlock();

            // 取消事务

            // 返回结果
            return $result;
        }

        // 判断查询类型
        switch (strtoupper($type)) {
            case 'EXPLAIN': // 不知道
            case 'DESCRIBE':// 不知道
            case 'SHOW':    // 显示所有表信息
            case 'SELECT':  // 从数据库表中获取数据
                $ret_data = $result->fetch_all( MYSQLI_ASSOC);
                break;
            // INSERT INTO - 向数据库表中插入数据
            case 'INSERT':
                $ret_data = $this->db->insert_id;
                break;
            // UPDATE - 更新数据库表中的数据
            case 'UPDATE':
            // DELETE - 从数据库表中删除数据
            case 'DELETE':
                $ret_data = $this->db->affected_rows;
                break;
            default:
                $ret_data = $result;
                break;
        }

        // 取消锁
        $this->db_look->unlock();

        // 取消事务

        // 返回数据
        return $ret_data;
    }

    /**
     * 用户登录鉴定
     * @param  string $user [description]
     * @param  string $pass [description]
     * @return booled       [description]
     */
    public function login($user, $pass)
    {
        // 校验输入
        $username = explode('@', trim($user))['0'];
        $password = md5($pass);

        // 生成查询Sql
        $sql = sprintf(
            'SELECT * FROM `user_list` WHERE `username` = \'%s\' AND `password` = \'%s\';',
            $username,
            $password
        );
        // 执行查询
        $userList = $this->db_query($sql);

        // 判断是否有这个用户
        return $userList->num_rows >= 1;
    }

    /**
     * 获取邮件列表
     * @param  [type] $username [description]
     * @return [type]           [description]
     */
    public function get_mail_list($username)
    {
        // 生成查询Sql
        $sql = sprintf(
            'SELECT `mail_id`, `size` FROM `mail_list` WHERE `owner` = \'%s\';',
            $username
        );

        // 执行查询
        $sql_ret = $this->db_query($sql);

        // 取出数据
        $list = $sql_ret->fetch_all(MYSQLI_ASSOC);

        // 重新组合数据
        $mail_list = array();
        foreach ($list as $id => $value) {
            $mid = $id + 1;
            $mail_list[$mid] = array(
                'mid'     => $mid,
                'mail_id' => $value['mail_id'],
                'size'    => $value['size']
            );
        }

        return array(
            'mail_list'  => $mail_list,
        );
    }

    /**
     * 获取邮件正文
     * @param  [type] $mail_id [description]
     * @return [type]          [description]
     */
    public function get_mail_body($mail_id)
    {
        // 生成查询Sql
        $sql = sprintf(
            'SELECT `body` FROM `mail_list` WHERE `mail_id` = \'%s\';',
            $mail_id
        );

        // 执行查询
        if(!$sql_ret = $this->db_query($sql)){
            return false;
        }

        // 取出数据
        if(!$mail_info = $sql_ret->fetch_assoc()){
            return false;
        }

        return $mail_info['body'];
    }

    /**
     * 删除邮件
     * @param  array $dele_list 需要删除的邮件ID列表
     * @return integer          成功删除的邮件数量
     */
    public function delete($dele_list)
    {
        // 生成查询Sql
        $sql = sprintf(
            'DELETE FROM `mail_list` WHERE `mail_id` IN (\'%s\')',
            implode('\', \'', $dele_list)
        );



        // 执行一次sql查询
        $ret_data = false;
        if($this->db->query($sql)){
            $ret_data = $this->db->affected_rows;
        }

        // 取消锁
        $this->db_look->unlock();

        return $ret_data;
    }


}
