<?php

namespace Php34\WorkerOnline\server;


use InvalidArgumentException;
use Php34\WorkerOnline\common\logic\SseLogic;
use RuntimeException;
use think\facade\Config;
use think\facade\Log;
use think\worker\Server;
use Throwable;
use Workerman\Connection\TcpConnection;
use Workerman\Lib\Timer;


class Workman extends Server
{
    protected $worker;
    protected $clients = [];
    protected $timeout = 120; // 120秒超时

    // 支持的訊息類型和方法映射
    protected $messageHandlers = [
        'ping' => 'handlePing',
        'login' => 'handleLogin',
        'logout' => 'handleLogout',
        'withdraw_apply' => 'handWithdrawApply',
        'online_user' => 'handleOnlineUserCheck',
        'rechange_and_withdraw' => 'handleBroadcastMessage',

    ];
    public function __construct()
    {
        $config = Config::load('config/worker_server.php','worker_server');

        // 将配置映射到属性
        foreach ($config as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            } elseif (is_array($value)) {
                // 处理嵌套数组，如 option 和 context
                $this->$key = array_merge($this->$key, $value);
            }
        }

        parent::__construct();

    }

    /**
     * 用戶連接時
     * @param TcpConnection $connection
     * @return void
     */
    public function onConnect(TcpConnection $connection): void
    {
        try {
            // 生成唯一连接ID（增强唯一性）
            $connection->id = uniqid('ws_conn_', true);
            $this->clients[$connection->id] = $connection;



            Log::info("新連接建立: {$connection->id}");
        } catch (Throwable $e) {


            Log::error("連接處理錯誤: {$e->getMessage()}");
            $connection->close();
        }

       $this->handleOnlineUserCheck($connection);
        $this->broadcastRechargeWithdrawData();
        echo date('Y-m-d H:i:s') . " Connection success: {$connection->id}\n";
    }

    /**
     * Worker啟動時
     * @return void
     */
    public function onWorkerStart(): void
    {
        // 每30秒清理無效連接
        Timer::add(30, [$this, 'cleanupDeadConnections']);
    }

    /**
     * 廣播充值提現數據
     * @return void
     */
    public function broadcastRechargeWithdrawData(): void
    {
        try {
            $data = [
                'recharge_count' => cache('rechange_count') ?: 0,
                'withdraw_count' => cache('withdraw_count') ?: 0
            ];

            $message = json_encode([
                'type' => 'rechange_and_withdraw',
                'data' => $data,
                'time' => date('Y-m-d H:i:s')
            ]);

            foreach ($this->clients as $client) {
                if ($client->getStatus() === TcpConnection::STATUS_ESTABLISHED) {
                    $client->send($message);
                }
            }
        } catch (Throwable $e) {
            Log::error("廣播數據錯誤: {$e->getMessage()}");
        }
    }

    protected function handWithdrawApply(TcpConnection $connection){
        app(SseLogic::class)->set_change_or_withdraw();
        $this->broadcastRechargeWithdrawData();

    }



    /**
     * 清理無效連接
     * @return void
     */
    public function cleanupDeadConnections(): void
    {
        $nowTime =time();
        foreach ($this->clients as $connectionId => $connection) {
            if ($connection->getStatus() !== TcpConnection::STATUS_ESTABLISHED) {
                unset($this->clients[$connectionId]);

                Log::info("链接已关闭, 清理無效連接: {$connectionId}");
            }

//            if (($nowTime - $connection->lastActive) > $this->timeout) {
//                $connection->close();
//                unset($this->clients[$connectionId]);
//
//                Log::info("链接超时, 清理無效連接: {$connectionId}");
//            }
        }
    }

    /**
     * 處理客戶端消息
     * @param TcpConnection $connection
     * @param $data
     * @return void
     */
    public function onMessage(TcpConnection $connection, $data): void
    {
        try {
            $message = json_decode($data, true);

            if (!$message || !isset($message['type'])) {
                throw new InvalidArgumentException('Invalid message format'); //無效的消息格式
            }

            $handler = $this->messageHandlers[$message['type']] ?? null;

            if ($handler && method_exists($this, $handler)) {
                $this->$handler($connection, $message);
            } else {
                throw new RuntimeException('Unknown message type'); //未知的消息類型
            }
        } catch (Throwable $e) {
            Log::error("消息處理錯誤: {$e->getMessage()}");
            $connection->send(json_encode([
                'type' => 'error',
                'message' => $e->getMessage()
            ]));
        }
    }

    /**
     * 斷開連接時
     * @param TcpConnection $connection
     * @return void
     */
    public function onClose(TcpConnection $connection): void
    {
        echo date('Y-m-d H:i:s') . " Connection closed: {$connection->id}\n";

        // 從客戶端列表中移除
        unset($this->clients[$connection->id]);
        $this->handleOnlineUserCheck($connection);

        Log::info("連接關閉: {$connection->id}");
    }

    /**
     * 广播消息
     * @param TcpConnection $sender
     * @param $content
     * @return void
     */
    protected function handleBroadcastMessage(TcpConnection $sender, $content): void
    {
        $message = json_encode([
            'type' => 'chat',
            'from' => $sender->id,
            'content' => $content,
            'time' => date('Y-m-d H:i:s')
        ]);

        foreach ($this->clients as $client) {
            $client->send($message);
        }
    }

    /**
     * 處理ping消息
     * @param TcpConnection $connection
     * @return void
     */
    protected function handlePing(TcpConnection $connection): void
    {
        // 更新心跳时间   没有 lastActive 属性
//        $connection->lastActive = time();
//        $this->clients[$connection->id]->lastActive = time();

        // 返回pong响应
        $connection->send(json_encode(['type' => 'pong']));
    }

    /**
     * 處理登錄消息
     * @param TcpConnection $connection
     * @param array $message
     * @return void
     */
    protected function handleLogin(TcpConnection $connection, array $message): void
    {

        $userId = $message['data']['userid'] ?? null;

        // 參數驗證
        if (!empty($userId)) {

            unset($this->clients[$connection->id]);

            $connection->id = $userId;
            $this->clients[$userId] = $connection;
            $this->handleOnlineUserCheck($connection);
        }

        // 建立新映射


        Log::info("用戶登錄成功", ['user_id' => $userId]);
    }

    protected function handleLogout(TcpConnection $connection){
        unset($this->clients[$connection->id]);
        $this->handleOnlineUserCheck($connection);
    }


    /**
     * 處理在線用戶檢查
     * @param TcpConnection $connection
     * @param array $message
     * @return void
     */
    protected function handleOnlineUserCheck(TcpConnection $connection): void
    {
//        $userIds = json_decode($message['data'], true) ?? [];
        $result = [];
//
//        foreach ($userIds as $userId) {
//            $result[$userId] = isset($this->clients[$userId]) ? 1 : 0;
//        }
        //返回在线人数
        foreach ($this->clients as $client) {
            $result[] = $client->id;
        }

        foreach ($this->clients as $client) {
            $client->send(json_encode([
                'type' => 'online_user',
                'data' => $result
            ]));
        }

    }



}