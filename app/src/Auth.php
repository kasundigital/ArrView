<?php

declare(strict_types=1);

final class Auth
{
    public function __construct(private PDO $pdo)
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_name('arrview_session');
            session_start([
                'cookie_httponly' => true,
                'cookie_samesite' => 'Lax',
                'use_strict_mode' => true,
            ]);
        }
    }

    public function hasUsers(): bool
    {
        return (int)$this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
    }

    public function user(): ?array
    {
        $id = (int)($_SESSION['user_id'] ?? 0);
        if ($id < 1) return null;
        $stmt = $this->pdo->prepare('SELECT id, username, role, enabled, created_at FROM users WHERE id=? LIMIT 1');
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        if (!$user || !(int)$user['enabled']) {
            $this->logout();
            return null;
        }
        return $user;
    }

    public function login(string $username, string $password): bool
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE username=? AND enabled=1 LIMIT 1');
        $stmt->execute([trim($username)]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) return false;
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        return true;
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    public function requireLogin(): array
    {
        if (!$this->hasUsers()) redirect('/setup.php');
        $user = $this->user();
        if (!$user) redirect('/login.php');
        return $user;
    }

    public function requireAdmin(): array
    {
        $user = $this->requireLogin();
        if ($user['role'] !== 'admin') {
            http_response_code(403);
            exit('403 Forbidden');
        }
        return $user;
    }

    public function createUser(string $username, string $password, string $role): void
    {
        $username = trim($username);
        if ($username === '' || strlen($username) < 3) throw new RuntimeException('Username must be at least 3 characters.');
        if (strlen($password) < 8) throw new RuntimeException('Password must be at least 8 characters.');
        if (!in_array($role, ['admin','viewer'], true)) throw new RuntimeException('Invalid role.');
        $stmt = $this->pdo->prepare('INSERT INTO users(username,password_hash,role) VALUES(?,?,?)');
        $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $role]);
    }
}
