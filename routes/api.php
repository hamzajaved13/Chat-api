<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\ChannelController;
use App\Controllers\CompanyController;
use App\Controllers\MessageController;
use App\Controllers\TeamController;
use App\Controllers\UserController;
use App\Core\Router;
use App\Middleware\AuthMiddleware;



$auth = new AuthMiddleware();
$authController = new AuthController();
$userController = new UserController();
$companyController = new CompanyController();
$teamController = new TeamController();
$channelController = new ChannelController();
$messageController = new MessageController();


// Authentication

$router->post('/api/auth/signup', [$authController, 'signup']);
$router->post('/api/auth/login', [$authController, 'login']);
$router->post('/api/auth/verify-email', [$authController, 'verifyEmail']);
$router->get('/api/auth/verify-email', [$authController, 'verifyEmail']); // supports the emailed link (?token=)
$router->post('/api/auth/resend-verification', [$authController, 'resendVerification']);
$router->post('/api/auth/logout', [$authController, 'logout']);


// Authenticated user / company

$router->get('/api/me', [$userController, 'me'], [$auth]);
$router->get('/api/company', [$companyController, 'show'], [$auth]);
$router->get('/api/company/users', [$companyController, 'users'], [$auth]);


// Teams

$router->post('/api/teams', [$teamController, 'store'], [$auth]);
$router->get('/api/teams', [$teamController, 'index'], [$auth]);
$router->get('/api/teams/{teamId}', [$teamController, 'show'], [$auth]);
$router->put('/api/teams/{teamId}', [$teamController, 'update'], [$auth]);
$router->delete('/api/teams/{teamId}', [$teamController, 'destroy'], [$auth]);
$router->get('/api/teams/{teamId}/members', [$teamController, 'members'], [$auth]);
$router->post('/api/teams/{teamId}/members', [$teamController, 'addMember'], [$auth]);
$router->delete('/api/teams/{teamId}/members/{userId}', [$teamController, 'removeMember'], [$auth]);


// Channels

$router->post('/api/teams/{teamId}/channels', [$channelController, 'store'], [$auth]);
$router->get('/api/teams/{teamId}/channels', [$channelController, 'index'], [$auth]);
$router->get('/api/channels/{channelId}', [$channelController, 'show'], [$auth]);
$router->put('/api/channels/{channelId}', [$channelController, 'update'], [$auth]);
$router->delete('/api/channels/{channelId}', [$channelController, 'destroy'], [$auth]);
$router->get('/api/channels/{channelId}/members', [$channelController, 'members'], [$auth]);
$router->post('/api/channels/{channelId}/members', [$channelController, 'addMember'], [$auth]);
$router->post('/api/channels/{channelId}/join', [$channelController, 'join'], [$auth]);
$router->delete('/api/channels/{channelId}/members/{userId}', [$channelController, 'removeMember'], [$auth]);


// Messages

$router->post('/api/channels/{channelId}/messages', [$messageController, 'store'], [$auth]);
$router->get('/api/channels/{channelId}/messages', [$messageController, 'index'], [$auth]);
$router->get('/api/messages/{messageId}', [$messageController, 'show'], [$auth]);
$router->put('/api/messages/{messageId}', [$messageController, 'update'], [$auth]);
$router->delete('/api/messages/{messageId}', [$messageController, 'destroy'], [$auth]);
