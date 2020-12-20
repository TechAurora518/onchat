<?php

declare(strict_types=1);

namespace app\core\service;

use app\core\Result;
use think\facade\Db;
use Identicon\Identicon;
use app\model\User as UserModel;
use app\core\util\Arr as ArrUtil;
use app\core\util\Sql as SqlUtil;
use app\core\util\Date as DateUtil;
use app\core\oss\Client as OssClient;
use app\model\Chatroom as ChatroomModel;
use app\model\ChatMember as ChatMemberModel;
use app\model\ChatRecord as ChatRecordModel;
use app\core\identicon\generator\ImageMagickGenerator;

class Chatroom
{
    /** 没有消息 */
    const CODE_NO_RECORD = 1;
    /** 聊天室名字过长 */
    const CODE_NAME_LONG = 2;
    /** 群介绍长度不符合规范 */
    const CODE_DESCRIPTION_IRREGULAR = 3;
    /** 可创建的群聊数量已满 */
    const CODE_GROUP_CHAT_COUNT_FULL = 4;

    /** 每次查询的消息行数 */
    const MSG_ROWS = 15;
    /** 群名最大长度 */
    const NAME_MAX_LENGTH = 30;
    /** 群介绍最小长度 */
    const DESCRIPTION_MIN_LENGTH = 5;
    /** 群介绍最大长度 */
    const DESCRIPTION_MAX_LENGTH = 300;

    /** 用户创建群聊最大数量 */
    const MAX_GROUP_CHAT_COUNT = 10;

    /**
     * 获取聊天室名称
     *
     * @param integer $id 聊天室ID
     * @return Result
     */
    public static function getName(int $id): Result
    {
        $chatroom = ChatroomModel::where('id', '=', $id)->field('name, type')->find();
        if (!$chatroom) {
            return new Result(Result::CODE_ERROR_PARAM);
        }

        // 如果聊天室类型是私聊的，则聊天室的名称需要返回私聊好友的Nickname
        if ($chatroom->type == ChatroomModel::TYPE_PRIVATE_CHAT) {
            $userId = User::getId();
            if (empty($userId)) {
                return new Result(Result::CODE_ERROR_NO_ACCESS);
            }

            // 找到自己
            $self = ChatMemberModel::where([
                'chatroom_id' => $id,
                'user_id'     => $userId
            ])->find();

            // 如果找不到，则代表自己没有进这个群
            if (empty($self)) {
                return new Result(Result::CODE_ERROR_NO_ACCESS);
            }

            // 查找加入了这个房间的另一个好友的nickname
            $name = ChatMemberModel::where('chatroom_id', '=', $id)->where('user_id', '<>', $userId)->value('nickname');

            if (empty($name)) {
                return new Result(Result::CODE_ERROR_UNKNOWN, '该私聊聊天室没有其他成员');
            }

            return Result::success($name);
        }

        return Result::success($chatroom->name);
    }

    public static function getChatroom(int $id): Result
    {
        $chatroom = ChatroomModel::where('id', '=', $id)->find();
        if (!$chatroom) {
            return new Result(Result::CODE_ERROR_PARAM);
        }

        // 如果聊天室类型是私聊的，则聊天室的名称需要返回私聊好友的Nickname
        if ($chatroom->type == ChatroomModel::TYPE_PRIVATE_CHAT) {
            $userId = User::getId();
            if (!$userId) {
                return new Result(Result::CODE_ERROR_NO_ACCESS);
            }

            // 找到自己
            $self = ChatMemberModel::where([
                'chatroom_id' => $id,
                'user_id'     => $userId
            ])->find();

            // 如果找不到，则代表自己没有进这个群
            if (empty($self)) {
                return new Result(Result::CODE_ERROR_NO_ACCESS);
            }

            // 查找加入了这个房间的另一个好友的nickname
            $name = ChatMemberModel::where('chatroom_id', '=', $id)->where('user_id', '<>', $userId)->value('nickname');

            if (empty($name)) {
                return new Result(Result::CODE_ERROR_UNKNOWN, '该私聊聊天室没有其他成员');
            }
            $chatroom->name = $name;
        }

        return Result::success(ArrUtil::keyToCamel($chatroom->toArray()));
    }

    /**
     * 创建一个聊天室
     *
     * @param string $name 聊天室名称
     * @param integer $type 聊天室类型
     * @param integer $description 聊天室描述、简介
     * @return Result
     */
    public static function creatChatroom(string $name = null, int $type = ChatroomModel::TYPE_GROUP_CHAT, ?string $description = null): Result
    {
        if ($name) {
            $name = trim($name);
            // 如果长度超出
            if (mb_strlen($name, 'utf-8') > self::NAME_MAX_LENGTH) {
                return new Result(self::CODE_NAME_LONG, '聊天室名字长度不能大于' . self::NAME_MAX_LENGTH . '位字符');
            }
        }

        if ($description) {
            $description = trim($description);
            $length = mb_strlen($description, 'utf-8');
            // 如果长度超出
            if ($length < self::DESCRIPTION_MIN_LENGTH || $length > self::DESCRIPTION_MAX_LENGTH) {
                return new Result(self::CODE_DESCRIPTION_IRREGULAR, '聊天室介绍长度必须在' . self::DESCRIPTION_MIN_LENGTH  . '~' . self::DESCRIPTION_MAX_LENGTH  . '位字符之间');
            }
        }

        $maxPeopleNum = 1;
        $timestamp = time() * 1000;

        switch ($type) {
            case ChatroomModel::TYPE_PRIVATE_CHAT:
                $maxPeopleNum = 2;
                break;

            case ChatroomModel::TYPE_GROUP_CHAT:
                $maxPeopleNum = 1000;
                break;
        }

        // 创建一个聊天室
        $chatroom = ChatroomModel::create([
            'name'           => $name,
            'type'           => $type,
            'description'    => $description,
            'max_people_num' => $maxPeopleNum,
            'create_time'    => $timestamp,
            'update_time'    => $timestamp,
        ]);

        self::addChatRecordTable($chatroom->id);

        if ($type == ChatroomModel::TYPE_GROUP_CHAT) {
            $ossClient = OssClient::getInstance();
            $bucket = OssClient::getBucket();
            $identicon = new Identicon(new ImageMagickGenerator());
            // 如果为调试模式，则将数据存放到dev/目录下
            $object = OssClient::getRootPath() . 'avatar/chatroom/' . $chatroom->id . '/' . md5((string) DateUtil::now()) . '.png';
            // 根据用户ID创建哈希头像
            $content = $identicon->getImageData($chatroom->id, 256, null, '#f5f5f5');
            // 上传到OSS
            $ossClient->putObject($bucket, $object, $content, OssClient::$imageHeadersOptions);

            ChatroomModel::update([
                'id' => $chatroom->id,
                'avatar' => $object
            ]);

            $chatroom->avatar = $ossClient->signImageUrl($object, OssClient::getOriginalImgStylename());
            $chatroom->avatarThumbnail = $ossClient->signImageUrl($object, OssClient::getThumbnailImgStylename());
        }

        return Result::success(ArrUtil::keyToCamel($chatroom->toArray()));
    }

    /**
     * 添加聊天成员
     *
     * @param integer $id 聊天室ID
     * @param integer $userId 用户ID
     * @param integer $nickname 室友昵称（好友昵称）
     * @param integer $role 角色
     * @return Result
     */
    public static function addChatMember(int $id, int $userId, string $nickname = null, int $role = 0): Result
    {
        $username = User::getUsernameById($userId);
        // 如果没有这个房间，或者没有这个用户，或者这个用户已经加入了这个房间
        if (
            empty(ChatroomModel::find($id)) ||
            empty($username) ||
            !empty(ChatMemberModel::where([
                'chatroom_id' => $id,
                'user_id'     => $userId
            ])->find())
        ) {
            return new Result(Result::CODE_ERROR_PARAM);
        }

        $timestamp = time() * 1000;

        $data = ChatMemberModel::create([
            'chatroom_id' => $id,
            'user_id'     => $userId,
            'nickname'    => $nickname ?: $username,
            'role'        => $role,
            'unread'      => 1,
            'create_time' => $timestamp,
            'update_time' => $timestamp,
        ]);

        return Result::success(ArrUtil::keyToCamel($data->toArray()));
    }

    /**
     * 添加消息
     *
     * @param integer $userId 用户ID
     * @param array $msg 消息体
     * @return Result
     */
    public static function setMessage(int $userId, array $msg): Result
    {
        // 拿到当前用户在这个聊天室的昵称
        $nickname = User::getNicknameInChatroom($userId, $msg['chatroomId']);
        if (!$nickname) { // 如果拿不到就说明当前用户不在这个聊天室
            return new Result(Result::CODE_ERROR_NO_ACCESS);
        }

        $result = Message::handler($msg);

        if ($result->code != Result::CODE_SUCCESS) {
            return $result;
        }

        $msg = $result->data;

        // 启动事务
        Db::startTrans();
        try {
            $timestamp = time() * 1000;

            $id = ChatRecordModel::opt($msg['chatroomId'])->json(['data'])->insertGetId([
                'chatroom_id' => $msg['chatroomId'],
                'user_id'     => $userId,
                'type'        => $msg['type'],
                'data'        => $msg['data'],
                'reply_id'    => $msg['replyId'] ?? null,
                'create_time' => $timestamp
            ]);

            ChatMemberModel::update([
                'is_show'     => true,
                'update_time' => $timestamp,
                // 如果是该用户的，则归零；
                // 如果不是该用户的，且小于100，则递增；否则直接100
                'unread'      => Db::raw('CASE WHEN user_id = ' . $userId . ' THEN 0 ELSE CASE WHEN unread < 100 THEN unread + 1 ELSE 100 END END')
            ], [
                'chatroom_id' => $msg['chatroomId']
            ]);

            $ossClient = OssClient::getInstance();
            $object = User::getInfoByKey('id', $userId, 'avatar')['avatar'];

            $msg['id'] = $id;
            $msg['userId'] = $userId;
            $msg['nickname'] = $nickname;
            $msg['avatarThumbnail'] = $ossClient->signImageUrl($object, OssClient::getThumbnailImgStylename());
            $msg['createTime'] = $timestamp;

            // 提交事务
            Db::commit();
            return Result::success($msg);
        } catch (\Exception $e) {
            // 回滚事务
            Db::rollback();
            return new Result(Result::CODE_ERROR_UNKNOWN, $e->getMessage());
        }
    }

    /**
     * 查询消息记录
     * 按照消息ID查询，若消息ID为0，则为初次查询，否则查询传入的消息ID之前的消息
     *
     * @param integer $id 聊天室ID
     * @param integer $msgId 消息ID
     * @return Result
     */
    public static function getRecords(int $id, int $msgId): Result
    {
        $userId = User::getId();
        if (!$userId) {
            return new Result(Result::CODE_ERROR_NO_ACCESS);
        }

        // 拿到当前用户在这个聊天室的昵称
        $nickname = User::getNicknameInChatroom($userId, $id);
        if (!$nickname) { // 如果拿不到就说明当前用户不在这个聊天室
            return new Result(Result::CODE_ERROR_NO_ACCESS);
        }

        // 用于缓存 user id => nickname
        $nicknameMap = [];
        $nicknameMap[$userId] = $nickname;
        // 用于缓存 user id => avatarThumbnail
        $avatarThumbnailMap = [];

        $chatRecord = ChatRecordModel::opt($id)->json(['data'])->where('chatroom_id', '=', $id);
        if ($chatRecord->count() === 0) { // 如果没有消息
            return new Result(self::CODE_NO_RECORD, '没有消息');
        }

        // 查询的时候，顺带把未读消息数归零
        ChatMemberModel::where([
            'user_id'     => $userId,
            'chatroom_id' => $id
        ])->update([
            'unread' => 0
        ]);

        // 如果msgId为0，则代表初次查询
        $data = $msgId == 0 ? $chatRecord : $chatRecord->where('id', '<', $msgId);

        $ossClient = OssClient::getInstance();
        $stylename = OssClient::getThumbnailImgStylename();

        $object = null;
        $records = [];
        foreach ($data->order('id', 'DESC')->limit(self::MSG_ROWS)->cursor() as $item) {
            $item = $item->toArray();

            // 如果nicknameMap里面没有找到已经缓存的nickname
            if (!isset($nicknameMap[$item['user_id']])) {
                $nickname = User::getNicknameInChatroom($item['user_id'], $id);

                if (!$nickname) { // 如果在聊天室成员表找不到这名用户了（退群了）但是她的消息还在，直接去用户表找
                    $nickname = User::getUsernameById($item['user_id']);
                }

                $nicknameMap[$item['user_id']] = $nickname;
            }

            // 如果avatarThumbnailMap里面没有找到已经缓存的avatarThumbnail
            if (!isset($avatarThumbnailMap[$item['user_id']])) {
                $object = User::getInfoByKey('id', $item['user_id'], 'avatar')['avatar'];

                $avatarThumbnailMap[$item['user_id']] = $ossClient->signImageUrl($object, $stylename);
            }

            $item['nickname'] = $nicknameMap[$item['user_id']];
            $item['avatarThumbnail'] = $avatarThumbnailMap[$item['user_id']];
            $item['data'] = json_decode($item['data']);
            $records[] = $item;
        }

        return Result::success(ArrUtil::keyToCamel($records));
    }

    /**
     * 撤回消息
     *
     * @param integer $id 房间号
     * @param integer $userId 用户ID
     * @param integer $msgId 消息ID
     * @return Result
     */
    public static function revokeMsg(int $id, int $userId, int $msgId): Result
    {
        $query = ChatRecordModel::opt($id)->where('id', '=', $msgId);
        $msg = $query->find();
        // 如果没找到这条消息
        if (!$msg) {
            return new Result(Result::CODE_ERROR_PARAM);
        }

        // 如果消息不是它本人发的 或者 已经超时了
        if ($msg['user_id'] != $userId || time() > $msg['create_time'] + 120000) {
            return new Result(Result::CODE_ERROR_NO_ACCESS);
        }

        // 启动事务
        Db::startTrans();
        try {
            // 如果消息删除失败
            if ($query->delete() == 0) {
                return new Result(Result::CODE_ERROR_UNKNOWN);
            }

            ChatMemberModel::update([
                'update_time' => SqlUtil::rawTimestamp(),
                // 如果消息不是该用户的，且未读消息数小于100，则递减（未读消息数最多储存到100，因为客户端会显示99+）
                'unread'      => Db::raw('CASE WHEN user_id != ' . $userId . ' AND unread BETWEEN 1 AND 100 THEN unread-1 ELSE unread END'),
            ], [
                'chatroom_id' => $id
            ]);

            // 提交事务
            Db::commit();
            return Result::success(['chatroomId' => $id, 'msgId' => $msgId]);
        } catch (\Exception $e) {
            // 回滚事务
            Db::rollback();
            return new Result(Result::CODE_ERROR_UNKNOWN, $e->getMessage());
        }
    }

    /**
     * 创建群聊聊天室
     *
     * @param string $name
     * @param string $description
     * @param integer $userId
     * @param string $username
     * @return Result
     */
    public static function create(string $name, ?string $description, int $userId, string $username): Result
    {
        if (!$name) {
            return new Result(Result::CODE_ERROR_PARAM);
        }

        $count = ChatMemberModel::where([
            'user_id' => $userId,
            'role' => ChatMemberModel::ROLE_HOST
        ])->count();

        if ($count >= self::MAX_GROUP_CHAT_COUNT) {
            return new Result(self::CODE_GROUP_CHAT_COUNT_FULL, '你可创建的聊天室数量已满！');
        }

        // 启动事务
        Db::startTrans();
        try {
            $result = self::creatChatroom($name, ChatroomModel::TYPE_GROUP_CHAT, $description);
            if ($result->code != Result::CODE_SUCCESS) {
                return $result;
            }

            $chatroom = $result->data;

            // 将自己添加到聊天室，角色为主人
            $result = self::addChatMember($chatroom['id'], $userId, $username, ChatMemberModel::ROLE_HOST);
            if ($result->code != Result::CODE_SUCCESS) {
                return $result;
            }

            $data = $result->data;

            // 移除掉一些不要的信息
            unset($data['nickname']);
            unset($data['role']);
            unset($data['userId']);

            // 补充一些信息
            $data['name'] = $name;
            $data['avatarThumbnail'] = $chatroom['avatarThumbnail'];
            $data['type'] = ChatroomModel::TYPE_GROUP_CHAT;
            $data['sticky'] = false;

            Db::commit();

            // 这里就不用转骆驼峰了，因为上面已经转过了
            return Result::success($data);
        } catch (\Exception $e) {
            // 回滚事务
            Db::rollback();
            return new Result(Result::CODE_ERROR_UNKNOWN, $e->getMessage());
        }
    }

    /**
     * 根据房间号尝试动态添加聊天记录表
     * 策略：1000个聊天室 使用 100个数据表记录聊天记录
     *
     * @param integer $chatroomId
     * @return void
     */
    public static function addChatRecordTable(int $chatroomId)
    {
        // 拿到千位数（小于1000，千位数为1）
        $thousand = $chatroomId < 1000 ? 1 : substr((string) $chatroomId, 0, -3);
        $index = $chatroomId % 100;
        $tableName = "chat_record_{$thousand}_{$index}";
        // 如果没有这个表
        if (Db::execute("SHOW TABLES LIKE '{$tableName}'") == 0) {
            Db::execute("CREATE TABLE IF NOT EXISTS {$tableName} (
                    id          INT        UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    chatroom_id INT        UNSIGNED NOT NULL COMMENT '聊天室ID',
                    user_id     INT        UNSIGNED NULL     COMMENT '消息发送者ID',
                    type        TINYINT(1) UNSIGNED NOT NULL COMMENT '消息类型',
                    data        JSON                NOT NULL COMMENT '消息数据体',
                    reply_id    INT        UNSIGNED NULL     COMMENT '回复消息的消息记录ID',
                    create_time BIGINT     UNSIGNED NOT NULL,
                    FOREIGN KEY (chatroom_id) REFERENCES chatroom(id) ON DELETE CASCADE ON UPDATE CASCADE,
                    FOREIGN KEY (user_id)     REFERENCES user(id)     ON DELETE CASCADE ON UPDATE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        }
    }
}