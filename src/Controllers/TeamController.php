<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Exceptions\ValidationException;
use App\Services\TeamService;
use App\Support\Presenter;
use App\Validation\Validator;

final class TeamController
{
    public function __construct(private readonly TeamService $teamService = new TeamService())
    {
    }

    public function store(Request $request): never
    {
        $data = Validator::make($request->all(), [
            'name' => 'required|string|min:2|max:120',
        ])->validate();

        $team = $this->teamService->create($request->user(), $data['name']);
        Response::created(['team' => Presenter::team($team)], 'Team created successfully.');
    }

    public function index(Request $request): never
    {
        $teams = $this->teamService->listForCompany($request->user());
        Response::success(['teams' => Presenter::collection($teams, [Presenter::class, 'team'])], 'Teams retrieved.');
    }

    public function show(Request $request): never
    {
        $team = $this->teamService->find($request->user(), $request->param('teamId'));
        Response::success(['team' => Presenter::team($team)], 'Team retrieved.');
    }

    public function update(Request $request): never
    {
        $data = Validator::make($request->all(), [
            'name' => 'string|min:2|max:120',
        ])->validate();

        if (empty($data)) {
            throw new ValidationException(['name' => ['At least one updatable field is required.']]);
        }

        $team = $this->teamService->update($request->user(), $request->param('teamId'), $data);
        Response::success(['team' => Presenter::team($team)], 'Team updated successfully.');
    }

    public function destroy(Request $request): never
    {
        $this->teamService->delete($request->user(), $request->param('teamId'));
        Response::success([], 'Team deleted successfully.');
    }

    public function addMember(Request $request): never
    {
        $data = Validator::make($request->all(), [
            'user_id' => 'required|string',
        ])->validate();

        $user = $this->teamService->addMember($request->user(), $request->param('teamId'), $data['user_id']);
        Response::created(['user' => Presenter::user($user)], 'User added to team.');
    }

    public function removeMember(Request $request): never
    {
        $this->teamService->removeMember($request->user(), $request->param('teamId'), $request->param('userId'));
        Response::success([], 'User removed from team.');
    }

    public function members(Request $request): never
    {
        $members = $this->teamService->listMembers($request->user(), $request->param('teamId'));
        Response::success(
            ['members' => Presenter::collection($members, [Presenter::class, 'user'])],
            'Team members retrieved.'
        );
    }
}
