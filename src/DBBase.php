<?php
/**
 * This file is part of the TelegramBot package.
 *
 * (c) Avtandil Kikabidze aka LONGMAN <akalongman@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 * Written by Marco Boretto <marco.bore@gmail.com>
 */

namespace Longman\TelegramBot;

use Exception;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\Chat;
use Longman\TelegramBot\Entities\ChosenInlineResult;
use Longman\TelegramBot\Entities\InlineQuery;
use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Entities\ReplyToMessage;
use Longman\TelegramBot\Entities\Update;
use Longman\TelegramBot\Entities\User;
use Longman\TelegramBot\Exception\TelegramException;

abstract class DBBase
{
    /**
     * MySQL credentials
     *
     * @var array
     */
    protected $mysql_credentials = [];

    /**
     * Table prefix
     *
     * @var string
     */
    protected $table_prefix;

    /**
     * Telegram class object
     *
     * @var Telegram
     */
    protected $telegram;

    /**
     * @var self
     */
    protected static $instance;


    /**
     * Initialize
     *
     * @param array    $credentials  Database connection details
     * @param Telegram $telegram     Telegram object to connect with this object
     * @param string   $table_prefix Table prefix
     * @param string   $encoding     Database character encoding
     *
     * @return DB
     * @throws TelegramException
     */
    public function initialize(
        array $credentials,
        Telegram $telegram,
        $table_prefix = null,
        $encoding = 'utf8mb4'
    ) {
        if (self::$instance !== null) {
            return self::$instance;
        }
        if (empty($credentials)) {
            throw new TelegramException('MySQL credentials not provided!');
        }

        $this->initDb($credentials, $encoding);

        $this->telegram          = $telegram;
        $this->mysql_credentials = $credentials;
        $this->table_prefix      = $table_prefix;
        self::$instance          = $this;

        $this->defineTables();

        return $this;
    }

    abstract protected function initDb(array $credentials, $encoding = 'utf8mb4');

    /**
     * @return DB|null
     */
    public static function getInstance()
    {
        return self::$instance;
    }

    /**
     * Define all the tables with the proper prefix
     */
    protected function defineTables()
    {
        $tables = [
            'callback_query',
            'chat',
            'chosen_inline_result',
            'edited_message',
            'inline_query',
            'message',
            'request_limiter',
            'telegram_update',
            'user',
            'user_chat',
            'conversation',
            'botan_shortener'
        ];
        foreach ($tables as $table) {
            $table_name = 'TB_' . strtoupper($table);
            if (!defined($table_name)) {
                define($table_name, $this->table_prefix . $table);
            }
        }
    }

    /**
     * Check if database connection has been created
     *
     * @return bool
     */
    abstract public function isDbConnected();

    /**
     * Fetch update(s) from DB
     *
     * @param int    $limit Limit the number of updates to fetch
     * @param string $id    Check for unique update id
     *
     * @return array|bool Fetched data or false if not connected
     * @throws TelegramException
     */
    abstract public function selectTelegramUpdate($limit = null, $id = null);

    /**
     * Fetch message(s) from DB
     *
     * @param int $limit Limit the number of messages to fetch
     *
     * @return array|bool Fetched data or false if not connected
     * @throws TelegramException
     */
    abstract public function selectMessages($limit = null);

    /**
     * Convert from unix timestamp to timestamp
     *
     * @param int $time Unix timestamp (if empty, current timestamp is used)
     *
     * @return string
     */
    protected function getTimestamp($time = null)
    {
        return date('Y-m-d H:i:s', $time ?: time());
    }

    /**
     * Convert array of Entity items to a JSON array
     *
     * @todo Find a better way, as json_* functions are very heavy
     *
     * @param array|null $entities
     * @param mixed      $default
     *
     * @return mixed
     */
    public function entitiesArrayToJson($entities, $default = null)
    {
        if (!is_array($entities)) {
            return $default;
        }

        // Convert each Entity item into an object based on its JSON reflection
        $json_entities = array_map(function ($entity) {
            return json_decode($entity, true);
        }, $entities);

        return json_encode($json_entities);
    }

    /**
     * Insert entry to telegram_update table
     *
     * @todo Add missing values! See https://core.telegram.org/bots/api#update
     *
     * @param string $id
     * @param string $chat_id
     * @param string $message_id
     * @param string $inline_query_id
     * @param string $chosen_inline_result_id
     * @param string $callback_query_id
     * @param string $edited_message_id
     *
     * @return bool If the insert was successful
     * @throws TelegramException
     */
    public function insertTelegramUpdate(
        $id,
        $chat_id = null,
        $message_id = null,
        $inline_query_id = null,
        $chosen_inline_result_id = null,
        $callback_query_id = null,
        $edited_message_id = null
    ) {
        if ($message_id === null && $inline_query_id === null && $chosen_inline_result_id === null && $callback_query_id === null && $edited_message_id === null) {
            throw new TelegramException('message_id, inline_query_id, chosen_inline_result_id, callback_query_id, edited_message_id are all null');
        }

        if (!$this->isDbConnected()) {
            return false;
        }

        return $this->insertTelegramUpdateToDb($id, $chat_id, $message_id, $inline_query_id, $chosen_inline_result_id, $callback_query_id, $edited_message_id);
    }

    abstract protected function insertTelegramUpdateToDb($id, $chat_id = null, $message_id = null, $inline_query_id = null, $chosen_inline_result_id = null, $callback_query_id = null, $edited_message_id = null);

    /**
     * Insert users and save their connection to chats
     *
     * @param User   $user
     * @param string $date
     * @param Chat   $chat
     *
     * @return bool If the insert was successful
     * @throws TelegramException
     */
    public function insertUser(User $user, $date = null, Chat $chat = null)
    {
        if (!$this->isDbConnected()) {
            return false;
        }

        $status = $this->insertUserToDb($user, $date);

        // Also insert the relationship to the chat into the user_chat table
        if ($chat instanceof Chat) {
            $status = $this->insertUserChatRelation($user, $chat);
        }

        return $status;
    }

    /**
     * @param User $user
     *
     * @param      $date
     *
     * @return bool
     */
    abstract protected function insertUserToDb(User $user, $date);

    /**
     * @param User $user
     * @param Chat $chat
     *
     * @return bool
     */
    abstract protected function insertUserChatRelation(User $user, Chat $chat);

    /**
     * Insert chat
     *
     * @param Chat   $chat
     * @param string $date
     * @param string $migrate_to_chat_id
     *
     * @return bool If the insert was successful
     * @throws TelegramException
     */
    public function insertChat(Chat $chat, $date = null, $migrate_to_chat_id = null, $user = null)
    {
        if (!$this->isDbConnected()) {
            return false;
        }

        $type = $chat->getType();
        $id = $chat->getId();
        $oldId = $migrate_to_chat_id;

        if ($migrate_to_chat_id !== null) {
            $type = 'supergroup';
            $id = $migrate_to_chat_id;
            $oldId = $chat->getId();
        }
        $createdAt = $date;
        $updatedAt = $date;

        return $this->insertChatToDb($chat, $id, $oldId, $type, $createdAt, $updatedAt, $user);
    }

    /**
     * @param $chat
     * @param $id
     * @param $oldId
     * @param $type
     * @param $createdAt
     * @param $updatedAt
     *
     * @return bool
     */
    abstract protected function insertChatToDb(Chat $chat, $id, $oldId, $type, $createdAt, $updatedAt, $user = null);

    /**
     * Insert request into database
     *
     *
     * @param Update $update
     *
     * @return bool
     * @throws TelegramException
     */
    public function insertRequest(Update $update)
    {
        if (!$this->isDbConnected()) {
            return false;
        }

        $update_id   = $update->getUpdateId();
        $update_type = $update->getUpdateType();

        // @todo Make this simpler: if ($message = $update->getMessage()) ...
        if ($update_type === 'message') {
            $message = $update->getMessage();

            if ($this->insertMessageRequest($message)) {
                $message_id = $message->getMessageId();
                $chat_id    = $message->getChat()->getId();

                return $this->insertTelegramUpdate(
                    $update_id,
                    $chat_id,
                    $message_id
                );
            }
        } elseif ($update_type === 'edited_message') {
            $edited_message = $update->getEditedMessage();

            if ($edited_message_local_id = $this->insertEditedMessageRequest($edited_message)) {
                $chat_id                 = $edited_message->getChat()->getId();

                return $this->insertTelegramUpdate(
                    $update_id,
                    $chat_id,
                    null,
                    null,
                    null,
                    null,
                    $edited_message_local_id
                );
            }
        } elseif ($update_type === 'channel_post') {
            $channel_post = $update->getChannelPost();

            if ($this->insertMessageRequest($channel_post)) {
                $message_id = $channel_post->getMessageId();
                $chat_id    = $channel_post->getChat()->getId();

                return $this->insertTelegramUpdate(
                    $update_id,
                    $chat_id,
                    $message_id
                );
            }
        } elseif ($update_type === 'edited_channel_post') {
            $edited_channel_post = $update->getEditedChannelPost();

            if ($edited_channel_post_local_id = $this->insertEditedMessageRequest($edited_channel_post)) {
                $chat_id                      = $edited_channel_post->getChat()->getId();

                return $this->insertTelegramUpdate(
                    $update_id,
                    $chat_id,
                    null,
                    null,
                    null,
                    null,
                    $edited_channel_post_local_id
                );
            }
        } elseif ($update_type === 'inline_query') {
            $inline_query = $update->getInlineQuery();

            if ($this->insertInlineQueryRequest($inline_query)) {
                $inline_query_id = $inline_query->getId();

                return $this->insertTelegramUpdate(
                    $update_id,
                    null,
                    null,
                    $inline_query_id
                );
            }
        } elseif ($update_type === 'chosen_inline_result') {
            $chosen_inline_result = $update->getChosenInlineResult();

            if ($chosen_inline_result_local_id = $this->insertChosenInlineResultRequest($chosen_inline_result)) {
                return $this->insertTelegramUpdate(
                    $update_id,
                    null,
                    null,
                    null,
                    $chosen_inline_result_local_id
                );
            }
        } elseif ($update_type === 'callback_query') {
            $callback_query = $update->getCallbackQuery();

            if ($this->insertCallbackQueryRequest($callback_query)) {
                $callback_query_id = $callback_query->getId();

                return $this->insertTelegramUpdate(
                    $update_id,
                    null,
                    null,
                    null,
                    null,
                    $callback_query_id
                );
            }
        }

        return false;
    }

    /**
     * Insert inline query request into database
     *
     * @param InlineQuery $inline_query
     *
     * @return bool If the insert was successful
     * @throws TelegramException
     */
    abstract public function insertInlineQueryRequest(InlineQuery $inline_query);

    /**
     * Insert chosen inline result request into database
     *
     * @param ChosenInlineResult $chosen_inline_result
     *
     * @return bool If the insert was successful
     * @throws TelegramException
     */
    public function insertChosenInlineResultRequest(ChosenInlineResult $chosen_inline_result)
    {
        if (!$this->isDbConnected()) {
            return false;
        }

        $date    = $this->getTimestamp();
        $user_id = null;

        $user = $chosen_inline_result->getFrom();
        if ($user instanceof User) {
            $user_id = $user->getId();
            $this->insertUser($user, $date);
        }

        $created_at = $date;

        return $this->insertChosenInlineResultRequestToDb($chosen_inline_result, $user_id, $created_at);
    }

    /**
     * @param ChosenInlineResult $chosen_inline_result
     * @param                    $user_id
     * @param                    $created_at
     *
     * @return bool
     */
    abstract protected function insertChosenInlineResultRequestToDb(ChosenInlineResult $chosen_inline_result, $user_id, $created_at);

    /**
     * Insert callback query request into database
     *
     * @param CallbackQuery $callback_query
     *
     * @return bool If the insert was successful
     * @throws TelegramException
     */
    public function insertCallbackQueryRequest(CallbackQuery $callback_query)
    {
        if (!$this->isDbConnected()) {
            return false;
        }

        $date    = $this->getTimestamp();
        $user_id = null;

        $user = $callback_query->getFrom();
        if ($user instanceof User) {
            $user_id = $user->getId();
            $this->insertUser($user, $date);
        }

        $message    = $callback_query->getMessage();
        $chat_id    = null;
        $message_id = null;
        if ($message instanceof Message) {
            $chat_id    = $message->getChat()->getId();
            $message_id = $message->getMessageId();

            $is_message = $this->isMessage($message_id, $chat_id);

            if ($is_message) {
                $this->insertEditedMessageRequest($message);
            } else {
                $this->insertMessageRequest($message);
            }
        }

        $created_at = $date;

        return $this->insertCallbackQueryRequestToDb($callback_query, $user_id, $chat_id, $message_id, $created_at);
    }

    /**
     * @param $message_id
     * @param $chat_id
     *
     * @return bool
     */
    abstract protected function isMessage($message_id, $chat_id);

    /**
     * @param CallbackQuery $callback_query
     * @param               $user_id
     * @param               $chat_id
     * @param               $message_id
     * @param               $created_at
     *
     * @return bool
     */
    abstract protected function insertCallbackQueryRequestToDb(CallbackQuery $callback_query, $user_id, $chat_id, $message_id, $created_at);

    /**
     * Insert Message request in db
     *
     * @todo Complete with new fields: https://core.telegram.org/bots/api#message
     *
     * @param Message $message
     *
     * @return bool If the insert was successful
     * @throws TelegramException
     */
    public function insertMessageRequest(Message $message)
    {
        if (!$this->isDbConnected()) {
            return false;
        }

        $date = $this->getTimestamp($message->getDate());

        $user = $message->getFrom();

        // Insert chat, update chat id in case it migrated
        $chat = $message->getChat();
        $this->insertChat($chat, $date, $message->getMigrateToChatId(), $user);

        // Insert user and the relation with the chat
        if ($user instanceof User) {
            $this->insertUser($user, $date, $chat);
        }

        // Insert the forwarded message user in users table
        $forward_date = null;
        $forward_from = $message->getForwardFrom();
        if ($forward_from instanceof User) {
            $this->insertUser($forward_from, $forward_date);
            $forward_from = $forward_from->getId();
            $forward_date = $this->getTimestamp($message->getForwardDate());
        }
        $forward_from_chat = $message->getForwardFromChat();
        if ($forward_from_chat instanceof Chat) {
            $this->insertChat($forward_from_chat, $forward_date);
            $forward_from_chat = $forward_from_chat->getId();
            $forward_date      = $this->getTimestamp($message->getForwardDate());
        }

        // New and left chat member
        $new_chat_members_ids = null;
        $left_chat_member_id  = null;

        $new_chat_members = $message->getNewChatMembers();
        $left_chat_member = $message->getLeftChatMember();
        if (!empty($new_chat_members)) {
            foreach ($new_chat_members as $new_chat_member) {
                if ($new_chat_member instanceof User) {
                    // Insert the new chat user
                    $this->insertUser($new_chat_member, $date, $chat);
                    $new_chat_members_ids[] = $new_chat_member->getId();
                }
            }
            $new_chat_members_ids = implode(',', $new_chat_members_ids);
        } elseif ($left_chat_member instanceof User) {
            // Insert the left chat user
            $this->insertUser($left_chat_member, $date, $chat);
            $left_chat_member_id = $left_chat_member->getId();
        }

        $user_id = null;
        if ($user instanceof User) {
            $user_id = $user->getId();
        }
        $chat_id = $chat->getId();

        $reply_to_message    = $message->getReplyToMessage();
        $reply_to_message_id = null;
        if ($reply_to_message instanceof ReplyToMessage) {
            $reply_to_message_id = $reply_to_message->getMessageId();
            // please notice that, as explained in the documentation, reply_to_message don't contain other
            // reply_to_message field so recursion deep is 1
            $this->insertMessageRequest($reply_to_message);
        }
        $reply_to_chat_id = null;
        if ($reply_to_message_id !== null) {
            $reply_to_chat_id = $chat_id;
        }

        $entities = $this->entitiesArrayToJson($message->getEntities(), null);
        $photo = $this->entitiesArrayToJson($message->getPhoto(), null);
        $new_chat_photo = $this->entitiesArrayToJson($message->getNewChatPhoto(), null);

        return $this->insertMessageRequestToDb(
            $message, $chat_id, $user_id, $date, $forward_from, $forward_from_chat, $forward_date,
            $reply_to_chat_id, $reply_to_message_id, $entities, $photo, $new_chat_photo, $new_chat_members_ids, $left_chat_member_id
        );
    }

    /**
     * @param Message $message
     * @param         $chat_id
     * @param         $user_id
     * @param         $date
     * @param         $forward_from
     * @param         $forward_from_chat
     * @param         $forward_date
     * @param         $reply_to_chat_id
     * @param         $reply_to_message_id
     * @param         $entities
     * @param         $photo
     * @param         $new_chat_photo
     * @param         $new_chat_members_ids
     * @param         $left_chat_member_id
     *
     * @return bool
     */
    abstract protected function insertMessageRequestToDb(Message $message, $chat_id, $user_id, $date, $forward_from, $forward_from_chat, $forward_date,
                                                         $reply_to_chat_id, $reply_to_message_id, $entities, $photo, $new_chat_photo, $new_chat_members_ids, $left_chat_member_id);

    /**
     * Insert Edited Message request in db
     *
     * @param Message $edited_message
     *
     * @return bool If the insert was successful
     * @throws TelegramException
     */
    public function insertEditedMessageRequest(Message $edited_message)
    {
        if (!$this->isDbConnected()) {
            return false;
        }

        $edit_date = $this->getTimestamp($edited_message->getEditDate());
        $user = $edited_message->getFrom();

        // Insert chat
        $chat = $edited_message->getChat();
        $this->insertChat($chat, $edit_date, $user);

        // Insert user and the relation with the chat
        if ($user instanceof User) {
            $this->insertUser($user, $edit_date, $chat);
        }

        $user_id = null;
        if ($user instanceof User) {
            $user_id = $user->getId();
        }

        $entities = $this->entitiesArrayToJson($edited_message->getEntities(), null);

        return $this->insertEditedMessageRequestToDb($edited_message, $chat, $user_id, $edit_date, $entities);
    }

    abstract protected function insertEditedMessageRequestToDb(Message $edited_message, Chat $chat, $user_id, $edit_date, $entities);

    /**
     * Select Groups, Supergroups, Channels and/or single user Chats (also by ID or text)
     *
     * @param $select_chats_params
     *
     * @return array|bool
     * @throws TelegramException
     */
    public function selectChats($select_chats_params)
    {
        if (!$this->isDbConnected()) {
            return false;
        }

        // Set defaults for omitted values.
        $select = array_merge([
            'groups'      => true,
            'supergroups' => true,
            'channels'    => true,
            'users'       => true,
            'date_from'   => null,
            'date_to'     => null,
            'chat_id'     => null,
            'text'        => null,
        ], $select_chats_params);

        if (!$select['groups'] && !$select['users'] && !$select['supergroups'] && !$select['channels']) {
            return false;
        }

        return $this->selectChatsFromDb($select);
    }

    /**
     * @param $select array
     *
     * @return array|bool
     */
    abstract protected function selectChatsFromDb($select);

    /**
     * Get Telegram API request count for current chat / message
     *
     * @param integer $chat_id
     * @param string  $inline_message_id
     *
     * @return array|bool Array containing TOTAL and CURRENT fields or false on invalid arguments
     * @throws TelegramException
     */
    abstract public function getTelegramRequestCount($chat_id = null, $inline_message_id = null);

    /**
     * Insert Telegram API request in db
     *
     * @param string $method
     * @param array  $data
     *
     * @return bool If the insert was successful
     * @throws TelegramException
     */
    public function insertTelegramRequest($method, $data)
    {
        if (!$this->isDbConnected()) {
            return false;
        }

        $chat_id           = isset($data['chat_id']) ? $data['chat_id'] : null;
        $inline_message_id = isset($data['inline_message_id']) ? $data['inline_message_id'] : null;
        $created_at = $this->getTimestamp();

        return $this->insertTelegramRequestToDb($chat_id, $inline_message_id, $method, $created_at);
    }

    /**
     * @param $chat_id
     * @param $inline_message_id
     * @param $method
     * @param $created_at
     *
     * @return bool
     */
    abstract protected function insertTelegramRequestToDb($chat_id, $inline_message_id, $method, $created_at);

    /**
     * Bulk update the entries of any table
     *
     * @param string $table
     * @param array  $fields_values
     * @param array  $where_fields_values
     *
     * @return bool
     * @throws TelegramException
     */
    public function update($table, array $fields_values, array $where_fields_values)
    {
        if (empty($fields_values) || !$this->isDbConnected()) {
            return false;
        }

        return $this->updateInDb($table, $fields_values, $where_fields_values);
    }

    /**
     * @param       $table
     * @param array $fields_values
     * @param array $where_fields_values
     *
     * @return bool
     */
    abstract protected function updateInDb($table, array $fields_values, array $where_fields_values);

    /**
     * Select a conversation from the DB
     *
     * @param string   $user_id
     * @param string   $chat_id
     * @param int|null $limit
     *
     * @return array|bool
     * @throws TelegramException
     */
    abstract public function selectConversation($user_id, $chat_id, $limit = null);

    /**
     * Insert the conversation in the database
     *
     * @param string $user_id
     * @param string $chat_id
     * @param string $command
     *
     * @return bool
     * @throws TelegramException
     */
    abstract public function insertConversation($user_id, $chat_id, $command);

    /**
     * Update a specific conversation
     *
     * @param array $fields_values
     * @param array $where_fields_values
     *
     * @return bool
     * @throws TelegramException
     */
    public function updateConversation(array $fields_values, array $where_fields_values)
    {
        // Auto update the update_at field.
        $fields_values['updated_at'] = $this->getTimestamp();

        return $this->update(TB_CONVERSATION, $fields_values, $where_fields_values);
    }

    /**
     * Select cached shortened URL from the database
     *
     * @deprecated Botan.io service is no longer working
     * @param string $url
     * @param string $user_id
     *
     * @return array|bool
     * @throws TelegramException
     */
    abstract public function selectShortUrl($url, $user_id);

    /**
     * Insert shortened URL into the database
     *
     * @deprecated Botan.io service is no longer working
     *
     * @param string $url
     * @param string $user_id
     * @param string $short_url
     *
     * @return bool
     * @throws TelegramException
     */
    abstract public function insertShortUrl($url, $user_id, $short_url);
}
