<?php

declare(strict_types=1);

use QuizMyClass\Auth\Session;
use QuizMyClass\Support\AdminGuard;
use QuizMyClass\Support\AdminRepository;
use QuizMyClass\Support\Csrf;
use QuizMyClass\Support\Http;

$runtime = require dirname(__DIR__, 2) . '/app/bootstrap.php';
Session::start($runtime['config']);
Http::securityHeaders();
$admin = AdminGuard::require($runtime);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

$redirect = '/admin/';
try {
    Csrf::validate();
    $repo = new AdminRepository($runtime['database']);
    $roomId = (int) ($_POST['room_id'] ?? 0);
    switch ((string) ($_POST['action'] ?? '')) {
        case 'close_room':
            $redirect = '/admin/room.php?id=' . $roomId;
            $repo->closeRoom($roomId, $admin);
            Session::set('flash_success', 'Quiz deactivated.');
            break;
        case 'delete_room':
            $redirect = '/admin/room.php?id=' . $roomId;
            $repo->deleteRoom($roomId, (string) ($_POST['confirm_slug'] ?? ''), $admin);
            $redirect = '/admin/';
            Session::set('flash_success', 'Quiz deleted.');
            break;
        case 'block_user':
        case 'unblock_user':
            $redirect = '/admin/users.php';
            $repo->setBlocked((int) ($_POST['user_id'] ?? 0), $_POST['action'] === 'block_user', $admin);
            Session::set('flash_success', $_POST['action'] === 'block_user' ? 'User blocked.' : 'User unblocked.');
            break;
        default:
            throw new RuntimeException('Unknown action.');
    }
} catch (Throwable $exception) {
    Session::set('flash_error', $exception->getMessage());
}
Http::redirect($redirect);
