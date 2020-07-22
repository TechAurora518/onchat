<?php

declare(strict_types=1);

namespace app\listener\websocket;

use app\core\handler\User as UserHandler;
use app\core\handler\Chatroom as ChatroomHandler;

class Message extends BaseListener
{

    /**
     * 事件监听处理
     *
     * @return mixed
     */
    public function handle($event)
    {
        parent::initSession();
        $userId = UserHandler::getId();

        $this->websocket->to(parent::ROOM_CHATROOM . $event['msg']['chatroomId'])
            ->emit("message", ChatroomHandler::setMessage($userId, $event['msg']));
    }
}
