<?php
return [
    // 扩展自身需要的配置
    'protocol' => 'websocket', // 协议 支持 tcp udp unix http websocket text
    'host' => '0.0.0.0', // 监听地址
    'port' => env('WSSPORT'), // 监听端口
    'socket' => '', // 完整监听地址
    'context' => [], // socket 上下文选项
    'worker_class' => Php34\WorkerOnline\server\Workman::class, // 自定义Workerman服务类名 支持数组定义多个服务

    // 支持workerman的所有配置参数
    'name' => 'thinkphp',
    'count' => 1,
    'daemonize' => false,



];
