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
$router->get('/auth/google', [App\Controllers\GoogleController::class, 'start'], ['guest']);
$router->get('/auth/google/callback', [App\Controllers\GoogleController::class, 'callback'], ['guest']);
$router->post('/auth/google/unlink', [App\Controllers\GoogleController::class, 'unlink']);
$router->get('/password/forgot', [App\Controllers\PasswordController::class, 'forgot'], ['guest']);
$router->post('/password/forgot', [App\Controllers\PasswordController::class, 'sendForgot'], ['guest']);
$router->get('/password/set/{token}', [App\Controllers\PasswordController::class, 'show'], ['guest']);
$router->post('/password/set/{token}', [App\Controllers\PasswordController::class, 'save'], ['guest']);
$router->get('/account/password', [AccountController::class, 'password'], ['auth', 'password']);
$router->post('/account/password', [AccountController::class, 'updatePassword'], ['auth', 'password']);
$router->get('/account', [AccountController::class, 'show']);
$router->post('/account', [AccountController::class, 'update']);

// Organizations
// The public landing page for visitors; signed-in people go straight to their workspace.
$router->get('/', [OrgController::class, 'home'], ['guest']);
$router->get('/admin', [App\Controllers\AdminController::class, 'index']);
$router->post('/admin/orgs/{id}/rename', [App\Controllers\AdminController::class, 'rename']);
$router->post('/admin/orgs/{id}/admins', [App\Controllers\AdminController::class, 'storeAdmin']);
$router->post('/admin/orgs/{id}/delete', [App\Controllers\AdminController::class, 'destroy']);
$router->post('/admin/owners', [App\Controllers\AdminController::class, 'storeOwner']);
$router->post('/admin/owners/{id}/remove', [App\Controllers\AdminController::class, 'removeOwner']);
$router->get('/orgs/new', [OrgController::class, 'create']);
$router->post('/orgs', [OrgController::class, 'store']);
$router->get('/o/{org}', [OrgController::class, 'show']);
$router->get('/o/{org}/settings', [OrgController::class, 'settings']);
$router->post('/o/{org}/settings', [OrgController::class, 'updateSettings']);
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
$router->get('/o/{org}/p/{project}/import', [App\Controllers\ImportController::class, 'show']);
$router->post('/o/{org}/p/{project}/import', [App\Controllers\ImportController::class, 'upload']);
$router->post('/o/{org}/p/{project}/import/run', [App\Controllers\ImportController::class, 'run']);
$router->post('/o/{org}/p/{project}/import/cancel', [App\Controllers\ImportController::class, 'cancel']);
$router->get('/o/{org}/p/{project}/import/sample.csv', [App\Controllers\ImportController::class, 'sample']);
$router->post('/o/{org}/p/{project}/fields', [ProjectController::class, 'saveFields']);
$router->post('/o/{org}/p/{project}/fields/template', [ProjectController::class, 'addTemplateFields']);
$router->post('/o/{org}/p/{project}/fields/{field}/delete', [ProjectController::class, 'deleteField']);
$router->post('/o/{org}/p/{project}/statuses/{status}/delete', [ProjectController::class, 'deleteStatus']);

// All tasks across projects (Inbox first) and meeting notes
$router->get('/o/{org}/tasks', [App\Controllers\AllTasksController::class, 'show']);
$router->get('/o/{org}/notes', [App\Controllers\NotesController::class, 'index']);
$router->post('/o/{org}/notes', [App\Controllers\NotesController::class, 'store']);
$router->get('/o/{org}/notes/{doc}', [App\Controllers\NotesController::class, 'show']);
$router->post('/o/{org}/notes/{doc}/settings', [App\Controllers\NotesController::class, 'update']);
$router->post('/o/{org}/notes/{doc}/delete', [App\Controllers\NotesController::class, 'destroy']);
$router->get('/o/{org}/notes/{doc}/export/{format}', [App\Controllers\NotesController::class, 'export']);

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
$router->post('/api/notes/{doc}/entries', [App\Controllers\NotesController::class, 'createEntry'], ['api']);
$router->post('/api/notes/entries/{entry}', [App\Controllers\NotesController::class, 'updateEntry'], ['api']);
$router->post('/api/notes/entries/{entry}/delete', [App\Controllers\NotesController::class, 'deleteEntry'], ['api']);
$router->post('/api/notes/{doc}/tasks', [App\Controllers\NotesController::class, 'createTask'], ['api']);
$router->get('/api/notes/{doc}/tasks', [App\Controllers\NotesController::class, 'tasks'], ['api']);
$router->post('/api/tasks/{task}/docs', [App\Controllers\NotesController::class, 'createForTask'], ['api']);
$router->get('/api/tasks/{task}/docs/available', [App\Controllers\NotesController::class, 'availableForTask'], ['api']);
$router->post('/api/tasks/{task}/docs/link', [App\Controllers\NotesController::class, 'linkToTask'], ['api']);
$router->post('/api/tasks/{task}/docs/{doc}/unlink', [App\Controllers\NotesController::class, 'unlinkFromTask'], ['api']);
$router->get('/api/o/{org}/search', [App\Controllers\SearchController::class, 'tasks'], ['api']);
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
$router->post('/api/tasks/{task}/project', [TaskApiController::class, 'moveProject'], ['api']);
$router->post('/api/tasks/{task}/files', [CommentApiController::class, 'upload'], ['api']);
$router->post('/api/tasks/{task}/comments', [CommentApiController::class, 'store'], ['api']);
$router->post('/api/comments/{comment}', [CommentApiController::class, 'update'], ['api']);
$router->post('/api/comments/{comment}/delete', [CommentApiController::class, 'destroy'], ['api']);
$router->post('/api/files/{file}/delete', [CommentApiController::class, 'deleteFile'], ['api']);
