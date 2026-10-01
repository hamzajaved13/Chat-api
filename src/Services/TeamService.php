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

/**
 * Business-rule notes (see README "Business Rule Assumptions" for the
 * full rationale - the spec leaves a few permission edges to the
 * implementer):
 *
 *  - Any user can create a team inside their own company.
 *  - Only the team creator may update/delete the team.
 *  - Any existing team member may add another user FROM THE SAME COMPANY
 *    to the team (mirrors the explicit channel-membership rule in the spec).
 *  - The team creator may remove any member; a member may remove themself.
 */
final class TeamService
{
    public function __construct(
        private readonly TeamRepository $teams = new TeamRepository(),
        private readonly UserRepository $users = new UserRepository(),
        private readonly ChannelRepository $channels = new ChannelRepository(),
    ) {
    }

    public function create(array $currentUser, string $name): array
    {
        $companyId = new ObjectId((string) $currentUser['company_id']);

        if ($this->teams->findByNameInCompany($name, $companyId) !== null) {
            throw new ConflictException('A team with this name already exists in your company.');
        }

        $team = $this->teams->create([
            'name' => $name,
            'company_id' => $companyId,
            'created_by' => new ObjectId((string) $currentUser['_id']),
        ]);

        $this->teams->addMember(
            $team['_id'],
            new ObjectId((string) $currentUser['_id']),
            new ObjectId((string) $currentUser['_id'])
        );

        return $team;
    }

    public function listForCompany(array $currentUser): array
    {
        return $this->teams->listByCompany((string) $currentUser['company_id']);
    }

    public function find(array $currentUser, string $teamId): array
    {
        $team = $this->requireTeamInCompany($currentUser, $teamId);
        return $team;
    }

    public function update(array $currentUser, string $teamId, array $fields): array
    {
        $team = $this->requireTeamInCompany($currentUser, $teamId);
        $this->assertIsCreator($currentUser, $team, 'Only the team creator can update this team.');

        $this->teams->update($teamId, $fields);
        return $this->teams->findById($teamId);
    }

    public function delete(array $currentUser, string $teamId): void
    {
        $team = $this->requireTeamInCompany($currentUser, $teamId);
        $this->assertIsCreator($currentUser, $team, 'Only the team creator can delete this team.');

        // Cascade: remove channels (and their memberships) that belong to this team.
        foreach ($this->channels->listByTeam($teamId) as $channel) {
            $this->channels->delete((string) $channel['_id']);
        }

        $this->teams->delete($teamId);
    }

    public function addMember(array $currentUser, string $teamId, string $targetUserId): array
    {
        $team = $this->requireTeamInCompany($currentUser, $teamId);

        if (!$this->teams->isMember($teamId, (string) $currentUser['_id'])) {
            throw new AuthorizationException('You must be a member of this team to add members.');
        }

        $targetUser = $this->users->findById($targetUserId);
        if ($targetUser === null) {
            throw new NotFoundException('User not found.');
        }

        if ((string) $targetUser['company_id'] !== (string) $team['company_id']) {
            throw new AuthorizationException('Cannot add a user from another company to this team.');
        }

        $added = $this->teams->addMember(
            $team['_id'],
            new ObjectId($targetUserId),
            new ObjectId((string) $currentUser['_id'])
        );

        if (!$added) {
            throw new ConflictException('This user is already a member of the team.');
        }

        return $targetUser;
    }

    public function removeMember(array $currentUser, string $teamId, string $targetUserId): void
    {
        $team = $this->requireTeamInCompany($currentUser, $teamId);

        $isCreator = (string) $team['created_by'] === (string) $currentUser['_id'];
        $isSelf = $targetUserId === (string) $currentUser['_id'];

        if (!$isCreator && !$isSelf) {
            throw new AuthorizationException('Only the team creator can remove other members.');
        }

        if (!$this->teams->isMember($teamId, $targetUserId)) {
            throw new NotFoundException('This user is not a member of the team.');
        }

        $this->teams->removeMember($teamId, $targetUserId);
    }

    public function listMembers(array $currentUser, string $teamId): array
    {
        $this->requireTeamInCompany($currentUser, $teamId);

        $memberships = $this->teams->listMembers($teamId);
        $userIds = array_map(fn ($m) => (string) $m['user_id'], $memberships);

        return $this->users->findManyByIds($userIds);
    }

    // ------------------------------------------------------------------

    private function requireTeamInCompany(array $currentUser, string $teamId): array
    {
        $team = $this->teams->findById($teamId);

        if ($team === null) {
            throw new NotFoundException('Team not found.');
        }

        if ((string) $team['company_id'] !== (string) $currentUser['company_id']) {
            // Do not reveal existence of another company's team.
            throw new NotFoundException('Team not found.');
        }

        return $team;
    }

    private function assertIsCreator(array $currentUser, array $team, string $message): void
    {
        if ((string) $team['created_by'] !== (string) $currentUser['_id']) {
            throw new AuthorizationException($message);
        }
    }
}
