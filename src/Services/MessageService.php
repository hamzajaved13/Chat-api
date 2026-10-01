<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AuthorizationException;
use App\Exceptions\NotFoundException;
use App\Repositories\ChannelRepository;
use App\Repositories\MessageRepository;
use App\Repositories\TeamRepository;
use MongoDB\BSON\ObjectId;

/**
 * Authorization rule used here (spec section 11 says "only when permitted
 * by the defined authorization rules" without pinning down the exact
 * policy): a message may be edited or deleted only by its own sender.
 * Channel creators can still remove members (see ChannelService) but do
 * not get blanket rights to edit/delete other users' messages.
 */
final class MessageService
{
    public function __construct(
        private readonly MessageRepository $messages = new MessageRepository(),
        private readonly ChannelRepository $channels = new ChannelRepository(),
        private readonly TeamRepository $teams = new TeamRepository(),
    ) {
    }

    public function send(array $currentUser, string $channelId, string $text): array
    {
        $this->assertChannelMembership($currentUser, $channelId);

        return $this->messages->create([
            'channel_id' => new ObjectId($channelId),
            'sender_id' => new ObjectId((string) $currentUser['_id']),
            'message' => $text,
        ]);
    }

    public function listForChannel(array $currentUser, string $channelId, int $limit, int $skip): array
    {
        $this->assertChannelMembership($currentUser, $channelId);
        return $this->messages->listByChannel($channelId, $limit, $skip);
    }

    public function find(array $currentUser, string $messageId): array
    {
        $message = $this->messages->findById($messageId);

        if ($message === null) {
            throw new NotFoundException('Message not found.');
        }

        $this->assertChannelMembership($currentUser, (string) $message['channel_id']);

        return $message;
    }

    public function update(array $currentUser, string $messageId, string $text): array
    {
        $message = $this->find($currentUser, $messageId);

        if ((string) $message['sender_id'] !== (string) $currentUser['_id']) {
            throw new AuthorizationException('You can only edit your own messages.');
        }

        $this->messages->update($messageId, ['message' => $text]);
        return $this->messages->findById($messageId);
    }

    public function delete(array $currentUser, string $messageId): void
    {
        $message = $this->find($currentUser, $messageId);

        if ((string) $message['sender_id'] !== (string) $currentUser['_id']) {
            throw new AuthorizationException('You can only delete your own messages.');
        }

        $this->messages->softDelete($messageId);
    }

    // ------------------------------------------------------------------

    private function assertChannelMembership(array $currentUser, string $channelId): void
    {
        $channel = $this->channels->findById($channelId);

        if ($channel === null) {
            throw new NotFoundException('Channel not found.');
        }

        $team = $this->teams->findById((string) $channel['team_id']);
        if ($team === null || (string) $team['company_id'] !== (string) $currentUser['company_id']) {
            throw new NotFoundException('Channel not found.');
        }

        if (!$this->channels->isMember($channelId, (string) $currentUser['_id'])) {
            throw new AuthorizationException('You must be a member of this channel to read or send messages.');
        }
    }
}
