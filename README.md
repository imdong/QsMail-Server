# QsMail Server
基于Swoole的邮件服务器

青石 博客 http://www.qs5.org

# 文件使用说明
文件名|文件名功能|备注说明
------|----------|--------
run.sh|进程管理脚本|
init.php|执行入口文件|
config.php|应用配置文件|
app/Mail_Server.class.php|邮件服务器主程序类|
app/Mail_App.class.php|应用功能类|热更新
app/Smtp_Server.class.php|SMTP协议支持类|热更新
app/Pop3_Server.class.php|POP3协议资产类|热更新

# run.sh 参数说明
参数名|功能说明
------|--------
start|启动脚本，并后台运行
status|查看运行状态
reload|重新加载脚本 通过这个进行热更新
restart|重新启动 先stop然后start
stop|停止脚本 正常方式停止
kill|强制杀死进程
