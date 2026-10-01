<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\MessageService;
use App\Support\Presenter;
use App\Validation\Validator;

final class MessageController
{
    public function __construct(private readonly MessageService $messageService = new MessageService())
    {
    }

    public function store(Request $request): never
    {
        $data = Validator::make($request->all(), [
            'message' => 'required|string|min:1|max:5000',
        ])->validate();

        $message = $this->messageService->send($request->user(), $request->param('channelId'), $data['message']);
        Response::created(['message' => Presenter::message($message)], 'Message sent.');
    }

    public function index(Request $request): never
    {
        $limit = (int) $request->query('limit', 50);
        $limit = max(1, min($limit, 200));
        $skip = max(0, (int) $request->query('skip', 0));

        $messages = $this->messageService->listForChannel(
            $request->user(),
            $request->param('channelId'),
            $limit,
            $skip
        );

        Response::success(
            ['messages' => Presenter::collection($messages, [Presenter::class, 'message'])],
            'Messages retrieved.'
        );
    }

    public function show(Request $request): never
    {
        $message = $this->messageService->find($request->user(), $request->param('messageId'));
        Response::success(['message' => Presenter::message($message)], 'Message retrieved.');
    }

    public function update(Request $request): never
    {
        $data = Validator::make($request->all(), [
            'message' => 'required|string|min:1|max:5000',
        ])->validate();

        $message = $this->messageService->update($request->user(), $request->param('messageId'), $data['message']);
        Response::success(['message' => Presenter::message($message)], 'Message updated.');
    }

    public function destroy(Request $request): never
    {
        $this->messageService->delete($request->user(), $request->param('messageId'));
        Response::success([], 'Message deleted.');
    }
}
