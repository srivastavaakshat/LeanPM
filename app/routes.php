<?php
declare(strict_types=1);

use App\Controllers\AccountController;
use App\Controllers\AuthController;
use App\Controllers\CommentApiController;
use App\Controllers\ExportController;
use App\Controllers\FileController;
use App\Controllers\MemberController;
use App\Controllers\OrgController;
use App\Controllers\ProjectController;
use App\Controllers\TaskApiController;

/** @var \App\Core\Router $router  Routes default to the 'auth' middleware (signed-in members only). */

// Sign-in
$router->get('/login', [AuthController::class, 'show'], ['guest']);
$router->post('/login', [AuthController::class, 'login'], ['guest']);
$router->post('/logout', [AuthController::class, 'logout']);
$router->get('/account/password', [AccountController::class, 'password'], ['auth', 'password']);
$router->post('/account/password', [AccountController::class, 'updatePassword'], ['auth', 'password']);
$router->get('/account', [AccountController::class, 'show']);
$router->post('/account', [AccountController::class, 'update']);

// Organizations
$router->get('/', [OrgController::class, 'home']);
$router->get('/orgs/new', [OrgController::class, 'create']);
$router->post('/orgs', [OrgController::class, 'store']);
$router->get('/o/{org}', [OrgController::class, 'show']);
$router->get('/o/{org}/settings', [OrgController::class, 'settings']);
$router->post('/o/{org}/settings', [OrgController::class, 'updateSettings']);
$router->post('/o/{org}/delete', [OrgController::class, 'destroy']);
$router->get('/o/{org}/trash', [OrgController::class, 'trash']);
$router->post('/o/{org}/trash/{task}/restore', [OrgController::class, 'restore']);

// Projects, board and list
$router->post('/o/{org}/projects', [ProjectController::class, 'store']);
$router->get('/o/{org}/p/{project}', [ProjectController::class, 'show']);
$router->get('/o/{org}/p/{project}/settings', [ProjectController::class, 'settings']);
$router->post('/o/{org}/p/{project}/rename', [ProjectController::class, 'rename']);
$router->post('/o/{org}/p/{project}/archive', [ProjectController::class, 'archive']);
$router->post('/o/{org}/p/{project}/statuses', [ProjectController::class, 'saveStatuses']);
$router->post('/o/{org}/p/{project}/statuses/template', [ProjectController::class, 'applyTemplate']);
$router->post('/o/{org}/p/{project}/fields', [ProjectController::class, 'saveFields']);
$router->post('/o/{org}/p/{project}/fields/template', [ProjectController::class, 'addTemplateFields']);
$router->post('/o/{org}/p/{project}/fields/{field}/delete', [ProjectController::class, 'deleteField']);
$router->post('/o/{org}/p/{project}/statuses/{status}/delete', [ProjectController::class, 'deleteStatus']);

// Members and permissions
$router->get('/o/{org}/members', [MemberController::class, 'index']);
$router->get('/o/{org}/members/new', [MemberController::class, 'create']);
$router->post('/o/{org}/members', [MemberController::class, 'store']);
$router->get('/o/{org}/members/{member}', [MemberController::class, 'edit']);
$router->post('/o/{org}/members/{member}', [MemberController::class, 'update']);
$router->post('/o/{org}/members/{member}/active', [MemberController::class, 'toggleActive']);
$router->post('/o/{org}/members/{member}/reset-password', [MemberController::class, 'resetPassword']);

// Reports
$router->get('/o/{org}/export', [ExportController::class, 'form']);
$router->get('/o/{org}/export/tasks.csv', [ExportController::class, 'tasks']);
$router->get('/o/{org}/export/comments.csv', [ExportController::class, 'comments']);

// Files (members of the task's organization only)
$router->get('/files/{file}', [FileController::class, 'show']);

// JSON API used by the board, list and task panel
$router->get('/api/projects/{project}/tasks', [TaskApiController::class, 'index'], ['api']);
$router->post('/api/projects/{project}/tasks', [TaskApiController::class, 'store'], ['api']);
$router->get('/api/projects/{project}/files', [TaskApiController::class, 'files'], ['api']);
$router->get('/api/tasks/{task}', [TaskApiController::class, 'show'], ['api']);
$router->post('/api/tasks/{task}', [TaskApiController::class, 'update'], ['api']);
$router->post('/api/tasks/{task}/move', [TaskApiController::class, 'move'], ['api']);
$router->post('/api/tasks/{task}/delete', [TaskApiController::class, 'destroy'], ['api']);
$router->get('/api/tasks/{task}/linkable', [TaskApiController::class, 'linkable'], ['api']);
$router->post('/api/tasks/{task}/subtasks/link', [TaskApiController::class, 'linkSubtask'], ['api']);
$router->post('/api/tasks/{task}/detach', [TaskApiController::class, 'detach'], ['api']);
$router->post('/api/tasks/{task}/files', [CommentApiController::class, 'upload'], ['api']);
$router->post('/api/tasks/{task}/comments', [CommentApiController::class, 'store'], ['api']);
$router->post('/api/comments/{comment}', [CommentApiController::class, 'update'], ['api']);
$router->post('/api/comments/{comment}/delete', [CommentApiController::class, 'destroy'], ['api']);
$router->post('/api/files/{file}/delete', [CommentApiController::class, 'deleteFile'], ['api']);
