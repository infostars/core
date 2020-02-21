<?php

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
use PDO;
use PDOException;

class DBMySql extends DBBase
{
    protected $dbPublicName = 'mysql';

    /**
     * @var PDO $pdo
     */
    protected $pdo;

    protected function initDb(array $credentials, $encoding = 'utf8mb4')
    {

        $dsn = 'mysql:host=' . $credentials['host'] . ';dbname=' . $credentials['database'];
        if (!empty($credentials['port'])) {
            $dsn .= ';port=' . $credentials['port'];
        }

        $options = [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES ' . $encoding];
        try {
            $pdo = new PDO($dsn, $credentials['user'], $credentials['password'], $options);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
        } catch (PDOException $e) {
            throw new TelegramException($e->getMessage());
        }

        $this->pdo = $pdo;
    }

    /**
     * Check if database connection has been created
     *
     * @return bool
     */
    public function isDbConnected()
    {
        return $this->pdo !== null;
    }

    /**
     * Get the PDO object of the connected database
     *
     * @return PDO
     */
    public function getPdo()
    {
        return $this->pdo;
    }

    /**
     * Fetch update(s) from DB
     *
     * @param int    $limit Limit the number of updates to fetch
     * @param string $id    Check for unique update id
     *
     * @return array|bool Fetched data or false if not connected
     * @throws TelegramException
     */
    public function selectTelegramUpdate($limit = null, $id = null)
    {
        if (!$this->isDbConnected()) {
            return false;
        }

        try {
            $sql = '
                SELECT `id`
                FROM `' . TB_TELEGRAM_UPDATE . '`
            ';

            if ($id !== null) {
                $sql .= ' WHERE `id` = :id';
            } else {
                $sql .= ' ORDER BY `id` DESC';
            }

            if ($limit !== null) {
                $sql .= ' LIMIT :limit';
            }

            $sth = $this->pdo->prepare($sql);

            if ($limit !== null) {
                $sth->bindValue(':limit', $limit, PDO::PARAM_INT);
            }
            if ($id !== null) {
                $sth->bindValue(':id', $id);
            }

            $sth->execute();

            return $sth->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new TelegramException($e->getMessage());
        }
    }

    /**
     * Fetch message(s) from DB
     *
     * @param int $limit Limit the number of messages to fetch
     *
     * @return array|bool Fetched data or false if not connected
     * @throws TelegramException
     */
    public function selectMessages($limit = null)
    {
        if (!$this->isDbConnected()) {
            return false;
        }

        try {
            $sql = '
                SELECT *
                FROM `' . TB_MESSAGE . '`
                ORDER BY `id` DESC
            ';

            if ($limit !== null) {
                $sql .= ' LIMIT :limit';
            }

            $sth = $this->pdo->prepare($sql);

            if ($limit !== null) {
                $sth->bindValue(':limit', $limit, PDO::PARAM_INT);
            }

            $sth->execute();

            return $sth->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new TelegramException($e->getMessage());
        }
    }

    protected function insertTelegramUpdateToDb($id, $chat_id = null, $message_id = null, $inline_query_id = null,
                                                $chosen_inline_result_id = null, $callback_query_id = null,
                                                $edited_message_id = null)
    {
        try {
            $sth = $this->pdo->prepare('
                INSERT IGNORE INTO `' . TB_TELEGRAM_UPDATE . '`
                (`id`, `chat_id`, `message_id`, `inline_query_id`, `chosen_inline_result_id`, `callback_query_id`, `edited_message_id`)
                VALUES
                (:id, :chat_id, :message_id, :inline_query_id, :chosen_inline_result_id, :callback_query_id, :edited_message_id)
            ');

            $sth->bindValue(':id', $id);
            $sth->bindValue(':chat_id', $chat_id);
            $sth->bindValue(':message_id', $message_id);
            $sth->bindValue(':edited_message_id', $edited_message_id);
            $sth->bindValue(':inline_query_id', $inline_query_id);
            $sth->bindValue(':chosen_inline_result_id', $chosen_inline_result_id);
            $sth->bindValue(':callback_query_id', $callback_query_id);

            return $sth->execute();
        } catch (PDOException $e) {
            throw new TelegramException($e->getMessage());
        }
    }

    /**
     * @param User $user
     *
     * @param      $date
     *
     * @return bool
     */
    protected function insertUserToDb(User $user, $date)
    {
        try {
            $sth = $this->pdo->prepare('
                INSERT INTO `' . TB_USER . '`
                (`id`, `is_bot`, `username`, `first_name`, `last_name`, `language_code`, `created_at`, `updated_at`)
                VALUES
                (:id, :is_bot, :username, :first_name, :last_name, :language_code, :created_at, :updated_at)
                ON DUPLICATE KEY UPDATE
                    `is_bot`         = VALUES(`is_bot`),
                    `username`       = VALUES(`username`),
                    `first_name`     = VALUES(`first_name`),
                    `last_name`      = VALUES(`last_name`),
                    `language_code`  = VALUES(`language_code`),
                    `updated_at`     = VALUES(`updated_at`)
            ');

            $sth->bindValue(':id', $user->getId());
            $sth->bindValue(':is_bot', $user->getIsBot(), PDO::PARAM_INT);
            $sth->bindValue(':username', $user->getUsername());
            $sth->bindValue(':first_name', $user->getFirstName());
            $sth->bindValue(':last_name', $user->getLastName());
            $sth->bindValue(':language_code', $user->getLanguageCode());
            $date = $date ?: $this->getTimestamp();
            $sth->bindValue(':created_at', $date);
            $sth->bindValue(':updated_at', $date);

            $status = $sth->execute();
        } catch (PDOException $e) {
            throw new TelegramException($e->getMessage());
        }

        return $status;
    }

    /**
     * @param User $user
     * @param Chat $chat
     *
     * @return bool
     */
    protected function insertUserChatRelation(User $user, Chat $chat)
    {
        try {
            $sth = $this->pdo->prepare('
                    INSERT IGNORE INTO `' . TB_USER_CHAT . '`
                    (`user_id`, `chat_id`)
                    VALUES
                    (:user_id, :chat_id)
                ');

            $sth->bindValue(':user_id', $user->getId());
            $sth->bindValue(':chat_id', $chat->getId());

            $status = $sth->execute();
        } catch (PDOException $e) {
            throw new TelegramException($e->getMessage());
        }

        return $status;
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
    protected function insertChatToDb(Chat $chat, $id, $oldId, $type, $createdAt, $updatedAt, $user = null)
    {
        try {
            $sth = $this->pdo->prepare('
                INSERT IGNORE INTO `' . TB_CHAT . '`
                (`id`, `type`, `title`, `username`, `all_members_are_administrators`, `created_at` ,`updated_at`, `old_id`)
                VALUES
                (:id, :type, :title, :username, :all_members_are_administrators, :created_at, :updated_at, :old_id)
                ON DUPLICATE KEY UPDATE
                    `type`                           = VALUES(`type`),
                    `title`                          = VALUES(`title`),
                    `username`                       = VALUES(`username`),
                    `all_members_are_administrators` = VALUES(`all_members_are_administrators`),
                    `updated_at`                     = VALUES(`updated_at`)
            ');



            $sth->bindValue(':id', $id);
            $sth->bindValue(':old_id', $oldId);

            $sth->bindValue(':type', $type);
            $sth->bindValue(':title', $chat->getTitle());
            $sth->bindValue(':username', $chat->getUsername());
            $sth->bindValue(':all_members_are_administrators', $chat->getAllMembersAreAdministrators(), PDO::PARAM_INT);

            $sth->bindValue(':created_at', $createdAt);
            $sth->bindValue(':updated_at', $updatedAt);

            return $sth->execute();
        } catch (PDOException $e) {
            throw new TelegramException($e->getMessage());
        }
    }

    /**
     * Insert request into database
     *
     * @todo $this->pdo->lastInsertId() - unsafe usage if expected previous insert fails?
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

            if ($this->insertEditedMessageRequest($edited_message)) {
                $edited_message_local_id = $this->pdo->lastInsertId();
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

            if ($this->insertEditedMessageRequest($edited_channel_post)) {
                $edited_channel_post_local_id = $this->pdo->lastInsertId();
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

            if ($this->insertChosenInlineResultRequest($chosen_inline_result)) {
                $chosen_inline_result_local_id = $this->pdo->lastInsertId();

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
    public function insertInlineQueryRequest(InlineQuery $inline_query)
    {
        if (!$this->isDbConnected()) {
            return false;
        }

        try {
            $sth = $this->pdo->prepare('
                INSERT IGNORE INTO `' . TB_INLINE_QUERY . '`
                (`id`, `user_id`, `location`, `query`, `offset`, `created_at`)
                VALUES
                (:id, :user_id, :location, :query, :offset, :created_at)
            ');

            $date    = $this->getTimestamp();
            $user_id = null;

            $user = $inline_query->getFrom();
            if ($user instanceof User) {
                $user_id = $user->getId();
                $this->insertUser($user, $date);
            }

            $sth->bindValue(':id', $inline_query->getId());
            $sth->bindValue(':user_id', $user_id);
            $sth->bindValue(':location', $inline_query->getLocation());
            $sth->bindValue(':query', $inline_query->getQuery());
            $sth->bindValue(':offset', $inline_query->getOffset());
            $sth->bindValue(':created_at', $date);

            return $sth->execute();
        } catch (PDOException $e) {
            throw new TelegramException($e->getMessage());
        }
    }

    /**
     * @param ChosenInlineResult $chosen_inline_result
     * @param                    $user_id
     * @param                    $created_at
     *
     * @return bool
     * @throws TelegramException
     */
    protected function insertChosenInlineResultRequestToDb(ChosenInlineResult $chosen_inline_result, $user_id,
                                                           $created_at)
    {
        try {
            $sth = $this->pdo->prepare('
                INSERT INTO `' . TB_CHOSEN_INLINE_RESULT . '`
                (`result_id`, `user_id`, `location`, `inline_message_id`, `query`, `created_at`)
                VALUES
                (:result_id, :user_id, :location, :inline_message_id, :query, :created_at)
            ');

            $sth->bindValue(':result_id', $chosen_inline_result->getResultId());
            $sth->bindValue(':user_id', $user_id);
            $sth->bindValue(':location', $chosen_inline_result->getLocation());
            $sth->bindValue(':inline_message_id', $chosen_inline_result->getInlineMessageId());
            $sth->bindValue(':query', $chosen_inline_result->getQuery());
            $sth->bindValue(':created_at', $created_at);

            return $sth->execute();
        } catch (PDOException $e) {
            throw new TelegramException($e->getMessage());
        }
    }

    /**
     * @param $message_id
     * @param $chat_id
     *
     * @return bool
     */
    protected function isMessage($message_id, $chat_id)
    {
        return (bool)$this->pdo->query('
                    SELECT *
                    FROM `' . TB_MESSAGE . '`
                    WHERE `id` = ' . $message_id . '
                      AND `chat_id` = ' . $chat_id . '
                    LIMIT 1
                ')->rowCount();
    }

    /**
     * @param CallbackQuery $callback_query
     * @param               $user_id
     * @param               $chat_id
     * @param               $message_id
     * @param               $created_at
     *
     * @return bool
     * @throws TelegramException
     */
    protected function insertCallbackQueryRequestToDb(CallbackQuery $callback_query, $user_id, $chat_id, $message_id,
                                                      $created_at)
    {
        try {
            $sth = $this->pdo->prepare('
                INSERT IGNORE INTO `' . TB_CALLBACK_QUERY . '`
                (`id`, `user_id`, `chat_id`, `message_id`, `inline_message_id`, `data`, `created_at`)
                VALUES
                (:id, :user_id, :chat_id, :message_id, :inline_message_id, :data, :created_at)
            ');

            $sth->bindValue(':id', $callback_query->getId());
            $sth->bindValue(':user_id', $user_id);
            $sth->bindValue(':chat_id', $chat_id);
            $sth->bindValue(':message_id', $message_id);
            $sth->bindValue(':inline_message_id', $callback_query->getInlineMessageId());
            $sth->bindValue(':data', $callback_query->getData());
            $sth->bindValue(':created_at', $created_at);

            return $sth->execute();
        } catch (PDOException $e) {
            throw new TelegramException($e->getMessage());
        }
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
    protected function insertMessageRequestToDb(Message $message, $chat_id, $user_id, $date, $forward_from,
                                                $forward_from_chat, $forward_date, $reply_to_chat_id,
                                                $reply_to_message_id, $entities, $photo, $new_chat_photo,
                                                $new_chat_members_ids, $left_chat_member_id)
    {
        try {
            $sth = $this->pdo->prepare('
                INSERT IGNORE INTO `' . TB_MESSAGE . '`
                (
                    `id`, `user_id`, `chat_id`, `date`, `forward_from`, `forward_from_chat`, `forward_from_message_id`,
                    `forward_date`, `reply_to_chat`, `reply_to_message`, `media_group_id`, `text`, `entities`, `audio`, `document`,
                    `animation`, `game`, `photo`, `sticker`, `video`, `voice`, `video_note`, `caption`, `contact`,
                    `location`, `venue`, `new_chat_members`, `left_chat_member`,
                    `new_chat_title`,`new_chat_photo`, `delete_chat_photo`, `group_chat_created`,
                    `supergroup_chat_created`, `channel_chat_created`,
                    `migrate_from_chat_id`, `migrate_to_chat_id`, `pinned_message`, `connected_website`, `passport_data`
                ) VALUES (
                    :message_id, :user_id, :chat_id, :date, :forward_from, :forward_from_chat, :forward_from_message_id,
                    :forward_date, :reply_to_chat, :reply_to_message, :media_group_id, :text, :entities, :audio, :document,
                    :animation, :game, :photo, :sticker, :video, :voice, :video_note, :caption, :contact,
                    :location, :venue, :new_chat_members, :left_chat_member,
                    :new_chat_title, :new_chat_photo, :delete_chat_photo, :group_chat_created,
                    :supergroup_chat_created, :channel_chat_created,
                    :migrate_from_chat_id, :migrate_to_chat_id, :pinned_message, :connected_website, :passport_data
                )
            ');

            $reply_to_message    = $message->getReplyToMessage();
            $reply_to_message_id = null;
            if ($reply_to_message instanceof ReplyToMessage) {
                $reply_to_message_id = $reply_to_message->getMessageId();
                // please notice that, as explained in the documentation, reply_to_message don't contain other
                // reply_to_message field so recursion deep is 1
                $this->insertMessageRequest($reply_to_message);
            }

            $sth->bindValue(':message_id', $message->getMessageId());
            $sth->bindValue(':chat_id', $chat_id);
            $sth->bindValue(':user_id', $user_id);
            $sth->bindValue(':date', $date);
            $sth->bindValue(':forward_from', $forward_from);
            $sth->bindValue(':forward_from_chat', $forward_from_chat);
            $sth->bindValue(':forward_from_message_id', $message->getForwardFromMessageId());
            $sth->bindValue(':forward_date', $forward_date);

            $reply_to_chat_id = null;
            if ($reply_to_message_id !== null) {
                $reply_to_chat_id = $chat_id;
            }
            $sth->bindValue(':reply_to_chat', $reply_to_chat_id);
            $sth->bindValue(':reply_to_message', $reply_to_message_id);

            $sth->bindValue(':media_group_id', $message->getMediaGroupId());
            $sth->bindValue(':text', $message->getText());
            $sth->bindValue(':entities', $entities);
            $sth->bindValue(':audio', $message->getAudio());
            $sth->bindValue(':document', $message->getDocument());
            $sth->bindValue(':animation', $message->getAnimation());
            $sth->bindValue(':game', $message->getGame());
            $sth->bindValue(':photo', $photo);
            $sth->bindValue(':sticker', $message->getSticker());
            $sth->bindValue(':video', $message->getVideo());
            $sth->bindValue(':voice', $message->getVoice());
            $sth->bindValue(':video_note', $message->getVideoNote());
            $sth->bindValue(':caption', $message->getCaption());
            $sth->bindValue(':contact', $message->getContact());
            $sth->bindValue(':location', $message->getLocation());
            $sth->bindValue(':venue', $message->getVenue());
            $sth->bindValue(':new_chat_members', $new_chat_members_ids);
            $sth->bindValue(':left_chat_member', $left_chat_member_id);
            $sth->bindValue(':new_chat_title', $message->getNewChatTitle());
            $sth->bindValue(':new_chat_photo', $new_chat_photo);
            $sth->bindValue(':delete_chat_photo', $message->getDeleteChatPhoto());
            $sth->bindValue(':group_chat_created', $message->getGroupChatCreated());
            $sth->bindValue(':supergroup_chat_created', $message->getSupergroupChatCreated());
            $sth->bindValue(':channel_chat_created', $message->getChannelChatCreated());
            $sth->bindValue(':migrate_from_chat_id', $message->getMigrateFromChatId());
            $sth->bindValue(':migrate_to_chat_id', $message->getMigrateToChatId());
            $sth->bindValue(':pinned_message', $message->getPinnedMessage());
            $sth->bindValue(':connected_website', $message->getConnectedWebsite());
            $sth->bindValue(':passport_data', $message->getPassportData());

            return $sth->execute();
        } catch (PDOException $e) {
            throw new TelegramException($e->getMessage());
        }
    }

    protected function insertEditedMessageRequestToDb(Message $edited_message, Chat $chat, $user_id, $edit_date, $entities)
    {
        try {
            $sth = $this->pdo->prepare('
                INSERT IGNORE INTO `' . TB_EDITED_MESSAGE . '`
                (`chat_id`, `message_id`, `user_id`, `edit_date`, `text`, `entities`, `caption`)
                VALUES
                (:chat_id, :message_id, :user_id, :edit_date, :text, :entities, :caption)
            ');

            $sth->bindValue(':chat_id', $chat->getId());
            $sth->bindValue(':message_id', $edited_message->getMessageId());
            $sth->bindValue(':user_id', $user_id);
            $sth->bindValue(':edit_date', $edit_date);
            $sth->bindValue(':text', $edited_message->getText());
            $sth->bindValue(':entities', $entities);
            $sth->bindValue(':caption', $edited_message->getCaption());

            return $sth->execute();
        } catch (PDOException $e) {
            throw new TelegramException($e->getMessage());
        }
    }

    /**
     * @param $select array
     *
     * @return array|bool
     * @throws TelegramException
     */
    protected function selectChatsFromDb($select)
    {
        try {
            $query = '
                SELECT * ,
                ' . TB_CHAT . '.`id` AS `chat_id`,
                ' . TB_CHAT . '.`username` AS `chat_username`,
                ' . TB_CHAT . '.`created_at` AS `chat_created_at`,
                ' . TB_CHAT . '.`updated_at` AS `chat_updated_at`
            ';
            if ($select['users']) {
                $query .= '
                    , ' . TB_USER . '.`id` AS `user_id`
                    FROM `' . TB_CHAT . '`
                    LEFT JOIN `' . TB_USER . '`
                    ON ' . TB_CHAT . '.`id`=' . TB_USER . '.`id`
                ';
            } else {
                $query .= 'FROM `' . TB_CHAT . '`';
            }

            // Building parts of query
            $where  = [];
            $tokens = [];

            if (!$select['groups'] || !$select['users'] || !$select['supergroups'] || !$select['channels']) {
                $chat_or_user = [];

                $select['groups'] && $chat_or_user[] = TB_CHAT . '.`type` = "group"';
                $select['supergroups'] && $chat_or_user[] = TB_CHAT . '.`type` = "supergroup"';
                $select['channels'] && $chat_or_user[] = TB_CHAT . '.`type` = "channel"';
                $select['users'] && $chat_or_user[] = TB_CHAT . '.`type` = "private"';

                $where[] = '(' . implode(' OR ', $chat_or_user) . ')';
            }

            if (null !== $select['date_from']) {
                $where[]              = TB_CHAT . '.`updated_at` >= :date_from';
                $tokens[':date_from'] = $select['date_from'];
            }

            if (null !== $select['date_to']) {
                $where[]            = TB_CHAT . '.`updated_at` <= :date_to';
                $tokens[':date_to'] = $select['date_to'];
            }

            if (null !== $select['chat_id']) {
                $where[]            = TB_CHAT . '.`id` = :chat_id';
                $tokens[':chat_id'] = $select['chat_id'];
            }

            if (null !== $select['text']) {
                $text_like = '%' . strtolower($select['text']) . '%';
                if ($select['users']) {
                    $where[]          = '(
                        LOWER(' . TB_CHAT . '.`title`) LIKE :text1
                        OR LOWER(' . TB_USER . '.`first_name`) LIKE :text2
                        OR LOWER(' . TB_USER . '.`last_name`) LIKE :text3
                        OR LOWER(' . TB_USER . '.`username`) LIKE :text4
                    )';
                    $tokens[':text1'] = $text_like;
                    $tokens[':text2'] = $text_like;
                    $tokens[':text3'] = $text_like;
                    $tokens[':text4'] = $text_like;
                } else {
                    $where[]         = 'LOWER(' . TB_CHAT . '.`title`) LIKE :text';
                    $tokens[':text'] = $text_like;
                }
            }

            if (!empty($where)) {
                $query .= ' WHERE ' . implode(' AND ', $where);
            }

            $query .= ' ORDER BY ' . TB_CHAT . '.`updated_at` ASC';

            $sth = $this->pdo->prepare($query);
            $sth->execute($tokens);

            return $sth->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new TelegramException($e->getMessage());
        }
    }

    /**
     * Get Telegram API request count for current chat / message
     *
     * @param integer $chat_id
     * @param string  $inline_message_id
     *
     * @return array|bool Array containing TOTAL and CURRENT fields or false on invalid arguments
     * @throws TelegramException
     */
    public function getTelegramRequestCount($chat_id = null, $inline_message_id = null)
    {
        if (!$this->isDbConnected()) {
            return false;
        }

        try {
            $sth = $this->pdo->prepare('SELECT 
                (SELECT COUNT(DISTINCT `chat_id`) FROM `' . TB_REQUEST_LIMITER . '` WHERE `created_at` >= :created_at_1) AS LIMIT_PER_SEC_ALL,
                (SELECT COUNT(*) FROM `' . TB_REQUEST_LIMITER . '` WHERE `created_at` >= :created_at_2 AND ((`chat_id` = :chat_id_1 AND `inline_message_id` IS NULL) OR (`inline_message_id` = :inline_message_id AND `chat_id` IS NULL))) AS LIMIT_PER_SEC,
                (SELECT COUNT(*) FROM `' . TB_REQUEST_LIMITER . '` WHERE `created_at` >= :created_at_minute AND `chat_id` = :chat_id_2) AS LIMIT_PER_MINUTE
            ');

            $date        = $this->getTimestamp();
            $date_minute = $this->getTimestamp(strtotime('-1 minute'));

            $sth->bindValue(':chat_id_1', $chat_id);
            $sth->bindValue(':chat_id_2', $chat_id);
            $sth->bindValue(':inline_message_id', $inline_message_id);
            $sth->bindValue(':created_at_1', $date);
            $sth->bindValue(':created_at_2', $date);
            $sth->bindValue(':created_at_minute', $date_minute);

            $sth->execute();

            return $sth->fetch();
        } catch (Exception $e) {
            throw new TelegramException($e->getMessage());
        }
    }

    /**
     * @param $chat_id
     * @param $inline_message_id
     * @param $method
     * @param $created_at
     *
     * @return bool
     * @throws TelegramException
     */
    protected function insertTelegramRequestToDb($chat_id, $inline_message_id, $method, $created_at)
    {
        try {
            $sth = $this->pdo->prepare('INSERT INTO `' . TB_REQUEST_LIMITER . '`
                (`method`, `chat_id`, `inline_message_id`, `created_at`)
                VALUES
                (:method, :chat_id, :inline_message_id, :created_at);
            ');

            $sth->bindValue(':chat_id', $chat_id);
            $sth->bindValue(':inline_message_id', $inline_message_id);
            $sth->bindValue(':method', $method);
            $sth->bindValue(':created_at', $created_at);

            return $sth->execute();
        } catch (Exception $e) {
            throw new TelegramException($e->getMessage());
        }
    }

    /**
     * @param       $table
     * @param array $fields_values
     * @param array $where_fields_values
     *
     * @return bool
     * @throws TelegramException
     */
    protected function updateInDb($table, array $fields_values, array $where_fields_values)
    {
        try {
            // Building parts of query
            $tokens = $fields = $where = [];

            // Fields with values to update
            foreach ($fields_values as $field => $value) {
                $token          = ':' . count($tokens);
                $fields[]       = "`{$field}` = {$token}";
                $tokens[$token] = $value;
            }

            // Where conditions
            foreach ($where_fields_values as $field => $value) {
                $token          = ':' . count($tokens);
                $where[]        = "`{$field}` = {$token}";
                $tokens[$token] = $value;
            }

            $sql = 'UPDATE `' . $table . '` SET ' . implode(', ', $fields);
            $sql .= count($where) > 0 ? ' WHERE ' . implode(' AND ', $where) : '';

            return $this->pdo->prepare($sql)->execute($tokens);
        } catch (Exception $e) {
            throw new TelegramException($e->getMessage());
        }
    }

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
    public function selectConversation($user_id, $chat_id, $limit = null)
    {
        if (!$this->isDbConnected()) {
            return false;
        }

        try {
            $sql = '
              SELECT *
              FROM `' . TB_CONVERSATION . '`
              WHERE `status` = :status
                AND `chat_id` = :chat_id
                AND `user_id` = :user_id
            ';

            if ($limit !== null) {
                $sql .= ' LIMIT :limit';
            }

            $sth = $this->pdo->prepare($sql);

            $sth->bindValue(':status', 'active');
            $sth->bindValue(':user_id', $user_id);
            $sth->bindValue(':chat_id', $chat_id);

            if ($limit !== null) {
                $sth->bindValue(':limit', $limit, PDO::PARAM_INT);
            }

            $sth->execute();

            return $sth->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            throw new TelegramException($e->getMessage());
        }
    }

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
    public function insertConversation($user_id, $chat_id, $command)
    {
        if (!$this->isDbConnected()) {
            return false;
        }

        try {
            $sth = $this->pdo->prepare('INSERT INTO `' . TB_CONVERSATION . '`
                (`status`, `user_id`, `chat_id`, `command`, `notes`, `created_at`, `updated_at`)
                VALUES
                (:status, :user_id, :chat_id, :command, :notes, :created_at, :updated_at)
            ');

            $date = $this->getTimestamp();

            $sth->bindValue(':status', 'active');
            $sth->bindValue(':command', $command);
            $sth->bindValue(':user_id', $user_id);
            $sth->bindValue(':chat_id', $chat_id);
            $sth->bindValue(':notes', '[]');
            $sth->bindValue(':created_at', $date);
            $sth->bindValue(':updated_at', $date);

            return $sth->execute();
        } catch (Exception $e) {
            throw new TelegramException($e->getMessage());
        }
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
    public function selectShortUrl($url, $user_id)
    {
        if (!$this->isDbConnected()) {
            return false;
        }

        try {
            $sth = $this->pdo->prepare('
                SELECT `short_url`
                FROM `' . TB_BOTAN_SHORTENER . '`
                WHERE `user_id` = :user_id
                  AND `url` = :url
                ORDER BY `created_at` DESC
                LIMIT 1
            ');

            $sth->bindValue(':user_id', $user_id);
            $sth->bindValue(':url', $url);
            $sth->execute();

            return $sth->fetchColumn();
        } catch (Exception $e) {
            throw new TelegramException($e->getMessage());
        }
    }

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
    public function insertShortUrl($url, $user_id, $short_url)
    {
        if (!$this->isDbConnected()) {
            return false;
        }

        try {
            $sth = $this->pdo->prepare('
                INSERT INTO `' . TB_BOTAN_SHORTENER . '`
                (`user_id`, `url`, `short_url`, `created_at`)
                VALUES
                (:user_id, :url, :short_url, :created_at)
            ');

            $sth->bindValue(':user_id', $user_id);
            $sth->bindValue(':url', $url);
            $sth->bindValue(':short_url', $short_url);
            $sth->bindValue(':created_at', $this->getTimestamp());

            return $sth->execute();
        } catch (Exception $e) {
            throw new TelegramException($e->getMessage());
        }
    }

    /**
     * @return bool|string
     */
    public function getDbVersion()
    {
        return $this->pdo->query('SELECT VERSION() AS version')->fetchColumn();
    }
}