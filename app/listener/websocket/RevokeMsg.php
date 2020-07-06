<?php

declare(strict_types=1);

namespace app\listener\websocket;

use think\Container;
use think\facade\Session;
use app\core\handler\User as UserHandler;
use app\core\handler\Chatroom as ChatroomHandler;

class RevokeMsg extends BaseListener
{
    public function __construct(Container $container)
    {
        parent::__construct($container);
    }

    /**
     * 事件监听处理
     *
     * @return mixed
     */
    public function handle($event)
    {
        parent::initSession();
        $userId = parent::getUserId();

        $this->websocket->to(parent::ROOM_CHATROOM . $event['chatroomId'])
            ->emit("revoke_msg", ChatroomHandler::revokeMsg($event['chatroomId'], $userId, $event['msgId']));
    }
}
