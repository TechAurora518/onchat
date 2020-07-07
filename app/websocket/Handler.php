<?php

namespace app\websocket;

use Swoole\Server;
use Swoole\Websocket\Frame;
use think\Config;
use think\Request;
use think\swoole\websocket\socketio\Handler as BaseHandler;
use app\core\handler\User as UserHandler;
use think\facade\Session;

/**
 * 自定义的WebSocket处理器
 */
class Handler extends BaseHandler
{
    public function __construct(Server $server, Config $config)
    {
        parent::__construct($server, $config);
    }

    /**
     * 连接打通时
     *
     * @param int     $fd
     * @param Request $request
     */
    public function onOpen($fd, Request $request)
    {
        parent::onOpen($fd, $request);
    }

    /**
     * 发生通讯时
     * 仅在未找到事件处理程序时触发
     *
     * @param Frame $frame
     * @return bool
     */
    public function onMessage(Frame $frame)
    {
        return parent::onMessage($frame);
    }

    /**
     * 连接断开时
     *
     * @param int $fd
     * @param int $reactorId
     */
    public function onClose($fd, $reactorId)
    {

        $sessId = UserHandler::getSessIdBytWebSocketFileDescriptor($fd);
        if ($sessId) {
            Session::setId(UserHandler::getSessIdBytWebSocketFileDescriptor($fd));
            Session::init();

            $userId = Session::get(UserHandler::SESSION_USER_LOGIN . '.id');
            $userId && UserHandler::removeUserIdWebSocketFileDescriptorPair($userId);
        }

        UserHandler::removeWebSocketFileDescriptorSessIdPair($fd);
    }
}
