<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Exceptions\ValidationException;
use App\Services\ChannelService;
use App\Support\Presenter;
use App\Validation\Validator;

final class ChannelController
{
    public function __construct(private readonly ChannelService $channelService = new ChannelService())
    {
    }

    public function store(Request $request): never
    {
        $data = Validator::make($request->all(), [
            'name' => 'required|string|min:2|max:120',
            'visibility' => 'in:private,public',
        ])->validate();

        $channel = $this->channelService->create(
            $request->user(),
            $request->param('teamId'),
            $data['name'],
            $data['visibility'] ?? 'private'
        );
        Response::created(['channel' => Presenter::channel($channel)], 'Channel created successfully.');
    }

    public function index(Request $request): never
    {
        $channels = $this->channelService->listForTeam($request->user(), $request->param('teamId'));
        Response::success(
            ['channels' => Presenter::collection($channels, [Presenter::class, 'channel'])],
            'Channels retrieved.'
        );
    }

    public function show(Request $request): never
    {
        $channel = $this->channelService->find($request->user(), $request->param('channelId'));
        Response::success(['channel' => Presenter::channel($channel)], 'Channel retrieved.');
    }

    public function update(Request $request): never
    {
        $data = Validator::make($request->all(), [
            'name' => 'string|min:2|max:120',
            'visibility' => 'in:private,public',
        ])->validate();

        if (empty($data)) {
            throw new ValidationException(['name' => ['At least one updatable field is required.']]);
        }

        $channel = $this->channelService->update($request->user(), $request->param('channelId'), $data);
        Response::success(['channel' => Presenter::channel($channel)], 'Channel updated successfully.');
    }

    public function destroy(Request $request): never
    {
        $this->channelService->delete($request->user(), $request->param('channelId'));
        Response::success([], 'Channel deleted successfully.');
    }

    public function addMember(Request $request): never
    {
        $data = Validator::make($request->all(), [
            'user_id' => 'required|string',
        ])->validate();

        $user = $this->channelService->addMember($request->user(), $request->param('channelId'), $data['user_id']);
        Response::created(['user' => Presenter::user($user)], 'User added to channel.');
    }

    public function join(Request $request): never
    {
        $channel = $this->channelService->joinChannel($request->user(), $request->param('channelId'));
        Response::created(['channel' => Presenter::channel($channel)], 'Joined channel successfully.');
    }

    public function removeMember(Request $request): never
    {
        $this->channelService->removeMember($request->user(), $request->param('channelId'), $request->param('userId'));
        Response::success([], 'User removed from channel.');
    }

    public function members(Request $request): never
    {
        $members = $this->channelService->listMembers($request->user(), $request->param('channelId'));
        Response::success(
            ['members' => Presenter::collection($members, [Presenter::class, 'user'])],
            'Channel members retrieved.'
        );
    }
}
