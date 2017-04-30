# Swoole Reload Demo
Swoole 热更新 示例
基于单例模式下的基本功能实现

本示例是根据本人个人理解而写
可能有错误，还望大神指导

青石 博客 http://www.qs5.org

# 文件使用说明
文件名|文件名功能|备注说明
------|----------|--------
init.php|执行入口文件|
demoServer.class.php|核心功能类|这里的代码是不支持热更新的
app.class.php|应用功能类|这里的代码可以支持热更新
run.sh|进程管理脚本|

# run.sh 参数说明
参数名|功能说明
------|--------
start|启动脚本，并后台运行
status|查看运行状态
reload|重新加载脚本 通过这个进行热更新
restart|重新启动 先stop然后start
stop|停止脚本 正常方式停止
kill|强制杀死进程
