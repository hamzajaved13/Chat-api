<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AuthorizationException;
use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Repositories\ChannelRepository;
use App\Repositories\TeamRepository;
use App\Repositories\UserRepository;
use MongoDB\BSON\ObjectId;


final class ChannelService
{
    private const VISIBILITY_PUBLIC = 'public';
    private const VISIBILITY_PRIVATE = 'private';

    public function __construct(
        private readonly ChannelRepository $channels = new ChannelRepository(),
        private readonly TeamRepository $teams = new TeamRepository(),
        private readonly UserRepository $users = new UserRepository(),
    ) {
    }

    public function create(array $currentUser, string $teamId, string $name, string $visibility = self::VISIBILITY_PRIVATE): array
    {
        $team = $this->requireTeamInCompany($currentUser, $teamId);

        if (!$this->teams->isMember($teamId, (string) $currentUser['_id'])) {
            throw new AuthorizationException('You can only create a channel inside a team you are a member of.');
        }

        $teamObjectId = $team['_id'];

        if ($this->channels->findByNameInTeam($name, $teamObjectId) !== null) {
            throw new ConflictException('A channel with this name already exists in this team.');
        }

        $channel = $this->channels->create([
            'name' => $name,
            'team_id' => $teamObjectId,
            'created_by' => new ObjectId((string) $currentUser['_id']),
            'visibility' => $this->normalizeVisibility($visibility),
        ]);

        $this->channels->addMember(
            $channel['_id'],
            new ObjectId((string) $currentUser['_id']),
            new ObjectId((string) $currentUser['_id'])
        );

        return $channel;
    }

    /**
     * Returns only the channels this user is allowed to see: every "public"
     * channel in the team, plus any "private" channel they already belong
     * to. Requires team membership to see anything at all.
     */
    public function listForTeam(array $currentUser, string $teamId): array
    {
        $this->requireTeamInCompany($currentUser, $teamId);
        $this->assertTeamMember($currentUser, $teamId, 'You must be a member of this team to view its channels.');

        $channels = $this->channels->listByTeam($teamId);
        $userId = (string) $currentUser['_id'];

        return array_values(array_filter(
            $channels,
            fn (array $channel) => $this->isVisibleTo($channel, $userId)
        ));
    }

    public function find(array $currentUser, string $channelId): array
    {
        ['channel' => $channel, 'team' => $team] = $this->requireChannelInCompany($currentUser, $channelId);
        $this->assertTeamMember($currentUser, (string) $team['_id'], 'You must be a member of this team to view this channel.');

        if (!$this->isVisibleTo($channel, (string) $currentUser['_id'])) {
            // Private and not a member - hide its existence entirely.
            throw new NotFoundException('Channel not found.');
        }

        return $channel;
    }

    public function update(array $currentUser, string $channelId, array $fields): array
    {
        ['channel' => $channel] = $this->requireChannelInCompany($currentUser, $channelId);
        $this->assertIsCreator($currentUser, $channel, 'Only the channel creator can update this channel.');

        if (isset($fields['visibility'])) {
            $fields['visibility'] = $this->normalizeVisibility($fields['visibility']);
        }

        $this->channels->update($channelId, $fields);
        return $this->channels->findById($channelId);
    }

    public function delete(array $currentUser, string $channelId): void
    {
        ['channel' => $channel] = $this->requireChannelInCompany($currentUser, $channelId);
        $this->assertIsCreator($currentUser, $channel, 'Only the channel creator can delete this channel.');

        $this->channels->delete($channelId);
    }

    public function addMember(array $currentUser, string $channelId, string $targetUserId): array
    {
        ['channel' => $channel, 'team' => $team] = $this->requireChannelInCompany($currentUser, $channelId);

        if (!$this->channels->isMember($channelId, (string) $currentUser['_id'])) {
            throw new AuthorizationException('You must be a member of this channel to add members.');
        }

        $targetUser = $this->users->findById($targetUserId);
        if ($targetUser === null) {
            throw new NotFoundException('User not found.');
        }

        // Mandatory rule: target must already be a member of the channel's team.
        if (!$this->teams->isMember((string) $team['_id'], $targetUserId)) {
            throw new AuthorizationException('User must be a member of the channel\'s team before being added to the channel.');
        }

        $added = $this->channels->addMember(
            $channel['_id'],
            new ObjectId($targetUserId),
            new ObjectId((string) $currentUser['_id'])
        );

        if (!$added) {
            throw new ConflictException('This user is already a member of the channel.');
        }

        return $targetUser;
    }

    /**
     * Self-join: lets any team member join a "public" channel without
     * needing an existing member to add them. Not allowed on "private"
     * channels - those remain strictly invite-only.
     */
    public function joinChannel(array $currentUser, string $channelId): array
    {
        ['channel' => $channel, 'team' => $team] = $this->requireChannelInCompany($currentUser, $channelId);
        $userId = (string) $currentUser['_id'];

        $this->assertTeamMember($currentUser, (string) $team['_id'], 'You must be a member of this channel\'s team to join it.');

        if (!$this->isVisibleTo($channel, $userId)) {
            // Private and not already a member - don't even confirm it exists.
            throw new NotFoundException('Channel not found.');
        }

        if ($this->normalizeVisibility($channel['visibility'] ?? self::VISIBILITY_PRIVATE) !== self::VISIBILITY_PUBLIC) {
            throw new AuthorizationException('This channel is private. Ask an existing member to add you.');
        }

        $added = $this->channels->addMember(
            $channel['_id'],
            new ObjectId($userId),
            new ObjectId($userId)
        );

        if (!$added) {
            throw new ConflictException('You are already a member of this channel.');
        }

        return $channel;
    }

    public function removeMember(array $currentUser, string $channelId, string $targetUserId): void
    {
        ['channel' => $channel] = $this->requireChannelInCompany($currentUser, $channelId);

        $isCreator = (string) $channel['created_by'] === (string) $currentUser['_id'];
        $isSelf = $targetUserId === (string) $currentUser['_id'];

        if (!$isCreator && !$isSelf) {
            throw new AuthorizationException('Only the channel creator can remove other members.');
        }

        if (!$this->channels->isMember($channelId, $targetUserId)) {
            throw new NotFoundException('This user is not a member of the channel.');
        }

        $this->channels->removeMember($channelId, $targetUserId);
    }

    public function listMembers(array $currentUser, string $channelId): array
    {
        $this->find($currentUser, $channelId); // reuses visibility + team-membership checks

        $memberships = $this->channels->listMembers($channelId);
        $userIds = array_map(fn ($m) => (string) $m['user_id'], $memberships);

        return $this->users->findManyByIds($userIds);
    }

    public function assertCanReadOrWrite(array $currentUser, string $channelId): array
    {
        ['channel' => $channel] = $this->requireChannelInCompany($currentUser, $channelId);

        if (!$this->channels->isMember($channelId, (string) $currentUser['_id'])) {
            throw new AuthorizationException('You must be a member of this channel to read or send messages.');
        }

        return $channel;
    }

    // ------------------------------------------------------------------

    private function isVisibleTo(array $channel, string $userId): bool
    {
        $visibility = $this->normalizeVisibility($channel['visibility'] ?? self::VISIBILITY_PRIVATE);

        if ($visibility === self::VISIBILITY_PUBLIC) {
            return true;
        }

        return $this->channels->isMember((string) $channel['_id'], $userId);
    }

    private function normalizeVisibility(mixed $visibility): string
    {
        $value = is_string($visibility) ? strtolower(trim($visibility)) : '';
        return $value === self::VISIBILITY_PUBLIC ? self::VISIBILITY_PUBLIC : self::VISIBILITY_PRIVATE;
    }

    private function assertTeamMember(array $currentUser, string $teamId, string $message): void
    {
        if (!$this->teams->isMember($teamId, (string) $currentUser['_id'])) {
            throw new AuthorizationException($message);
        }
    }

    private function requireTeamInCompany(array $currentUser, string $teamId): array
    {
        $team = $this->teams->findById($teamId);

        if ($team === null || (string) $team['company_id'] !== (string) $currentUser['company_id']) {
            throw new NotFoundException('Team not found.');
        }

        return $team;
    }

    /** @return array{channel: array, team: array} */
    private function requireChannelInCompany(array $currentUser, string $channelId): array
    {
        $channel = $this->channels->findById($channelId);

        if ($channel === null) {
            throw new NotFoundException('Channel not found.');
        }

        $team = $this->teams->findById((string) $channel['team_id']);

        if ($team === null || (string) $team['company_id'] !== (string) $currentUser['company_id']) {
            // Do not reveal existence of another company's channel.
            throw new NotFoundException('Channel not found.');
        }

        return ['channel' => $channel, 'team' => $team];
    }

    private function assertIsCreator(array $currentUser, array $channel, string $message): void
    {
        if ((string) $channel['created_by'] !== (string) $currentUser['_id']) {
            throw new AuthorizationException($message);
        }
    }
}
