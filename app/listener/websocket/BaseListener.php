<?php

declare(strict_types=1);

namespace app\listener\websocket;

use think\swoole\Manager;
use think\swoole\Websocket;
use app\core\util\Redis as RedisUtil;

abstract class BaseListener
{
    protected $websocket;
    protected $server;
    /** 当前用户的FD */
    protected $fd;

    /** 聊天室房间前缀 */
    const ROOM_CHATROOM = 'CHATROOM:';
    /** 好友申请房间前缀 */
    const ROOM_FRIEND_REQUEST = 'FRIEND_REQUEST:';
    /** 群聊申请房间前缀 */
    const ROOM_CHAT_REQUEST = 'CHAT_REQUEST:';

    public function __construct(Websocket $websocket)
    {
        $this->websocket = $websocket;
        $this->server = app(Manager::class)->getServer();
        $this->fd = $this->websocket->getSender();
    }

    /**
     * 获取当前user
     *
     * @return array|null
     */
    public function getUser(): ?array
    {
        return RedisUtil::getUserByFd($this->fd);
    }

    /**
     * 判断是否是正确的websocket连接
     *
     * @return bool
     */
    protected function isEstablished(): bool
    {
        return $this->server->isEstablished($this->fd);
    }
}
