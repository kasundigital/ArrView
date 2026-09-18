<?php

declare(strict_types=1);

final class Auth
{
    private const IDLE_TIMEOUT = 43200; // 12 hours
    private const MAX_LOGIN_FAILURES = 5;
    private const LOGIN_LOCK_SECONDS = 60;

    public function __construct(private PDO $pdo)
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $forwardedProto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0] ?? ''));
            $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $forwardedProto === 'https';

            session_name('arrview_session');
            session_start([
                'cookie_httponly' => true,
                'cookie_secure' => $secure,
                'cookie_samesite' => 'Lax',
                'use_strict_mode' => true,
            ]);
        }

        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
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

        $lastActivity = (int)($_SESSION['last_activity'] ?? 0);
        if ($lastActivity > 0 && time() - $lastActivity > self::IDLE_TIMEOUT) {
            $this->logout();
            return null;
        }
        $_SESSION['last_activity'] = time();

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
        $lockedUntil = (int)($_SESSION['login_locked_until'] ?? 0);
        if ($lockedUntil > time()) return false;

        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE username=? AND enabled=1 LIMIT 1');
        $stmt->execute([trim($username)]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $failures = (int)($_SESSION['login_failures'] ?? 0) + 1;
            $_SESSION['login_failures'] = $failures;
            if ($failures >= self::MAX_LOGIN_FAILURES) {
                $_SESSION['login_locked_until'] = time() + self::LOGIN_LOCK_SECONDS;
                $_SESSION['login_failures'] = 0;
            }
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['last_activity'] = time();
        unset($_SESSION['login_failures'], $_SESSION['login_locked_until']);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
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

    public function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return (string)$_SESSION['csrf_token'];
    }

    public function requireCsrf(?string $token): void
    {
        $expected = $this->csrfToken();
        if (!$token || !hash_equals($expected, $token)) {
            throw new RuntimeException('Security token expired or invalid. Refresh the page and try again.');
        }
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

    public function enabledAdminCount(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM users WHERE role='admin' AND enabled=1")->fetchColumn();
    }

    public function createUser(string $username, string $password, string $role): void
    {
        $username = trim($username);
        if ($username === '' || strlen($username) < 3) throw new RuntimeException('Username must be at least 3 characters.');
        if (strlen($password) < 8) throw new RuntimeException('Password must be at least 8 characters.');
        if (!in_array($role, ['admin','viewer'], true)) throw new RuntimeException('Invalid role.');

        try {
            $stmt = $this->pdo->prepare('INSERT INTO users(username,password_hash,role) VALUES(?,?,?)');
            $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $role]);
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000') {
                throw new RuntimeException('That username already exists.');
            }
            throw $e;
        }
    }
}
