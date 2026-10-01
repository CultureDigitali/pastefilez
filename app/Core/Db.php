<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use PDOStatement;

final class Db
{
    private static ?PDO $pdo = null;

    public static function init(): void
    {
        if (self::$pdo !== null) return;
        $path = (string)Config::get('db_path');
        $dir = dirname($path);
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        try {
            $pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA synchronous = NORMAL');
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA temp_store = MEMORY');
            $pdo->exec('PRAGMA secure_delete = ON');
            self::$pdo = $pdo;
            @chmod($path, 0600);
            self::migrate();
            @chmod($path, 0600);
        } catch (PDOException $e) {
            throw new \RuntimeException('DB connection failed: ' . $e->getMessage());
        }
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) self::init();
        return self::$pdo;
    }

    public static function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function first(string $sql, array $params = []): ?array
    {
        $r = self::run($sql, $params)->fetch();
        return $r === false ? null : $r;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $r = self::run($sql, $params)->fetchColumn();
        return $r === false ? null : $r;
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $place = array_map(fn($c) => ':' . $c, $cols);
        $sql = 'INSERT INTO ' . self::ident($table) . ' (' . implode(',', array_map([self::class, 'identCol'], $cols)) . ') VALUES (' . implode(',', $place) . ')';
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($data);
        return (int)self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $params = []): int
    {
        $set = [];
        foreach ($data as $k => $_) {
            $set[] = self::identCol($k) . ' = :set_' . $k;
        }
        $bind = [];
        foreach ($data as $k => $v) $bind['set_' . $k] = $v;
        $i = 0;
        $newWhere = preg_replace_callback('/\?/', function () use (&$i, $params) {
            $key = ':w' . $i++;
            return $key;
        }, $where);
        foreach ($params as $k => $v) {
            $key = is_int($k) ? ':w' . $k : $k;
            $bind[$key] = $v;
        }
        $sql = 'UPDATE ' . self::ident($table) . ' SET ' . implode(', ', $set) . ' WHERE ' . $newWhere;
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($bind);
        return $stmt->rowCount();
    }

    public static function delete(string $table, string $where, array $params = []): int
    {
        $i = 0;
        $newWhere = preg_replace_callback('/\?/', function () use (&$i) {
            return ':d' . $i++;
        }, $where);
        $bind = [];
        foreach ($params as $k => $v) {
            $key = is_int($k) ? ':d' . $k : $k;
            $bind[$key] = $v;
        }
        $stmt = self::pdo()->prepare('DELETE FROM ' . self::ident($table) . ' WHERE ' . $newWhere);
        $stmt->execute($bind);
        return $stmt->rowCount();
    }

    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $r = $fn($pdo);
            $pdo->commit();
            return $r;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    private static function ident(string $table): string
    {
        return '"' . str_replace('"', '""', $table) . '"';
    }

    private static function identCol(string $col): string
    {
        return '"' . str_replace('"', '""', $col) . '"';
    }

    private static function migrate(): void
    {
        $pdo = self::pdo();
        $pdo->exec("CREATE TABLE IF NOT EXISTS schema_meta (key TEXT PRIMARY KEY, value TEXT)");
        $v = $pdo->query("SELECT value FROM schema_meta WHERE key='version'")->fetchColumn();
        $current = $v === false ? 0 : (int)$v;
        $migrations = [
            1 => [
                "CREATE TABLE users (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    email TEXT UNIQUE NOT NULL,
                    password_hash TEXT NOT NULL,
                    role TEXT NOT NULL DEFAULT 'user',
                    display_name TEXT,
                    totp_secret TEXT,
                    totp_enabled INTEGER NOT NULL DEFAULT 0,
                    language TEXT NOT NULL DEFAULT 'it',
                    theme TEXT NOT NULL DEFAULT 'dark',
                    storage_backend TEXT NOT NULL DEFAULT 'local',
                    storage_quota_mb INTEGER NOT NULL DEFAULT 10240,
                    is_active INTEGER NOT NULL DEFAULT 1,
                    failed_attempts INTEGER NOT NULL DEFAULT 0,
                    locked_until INTEGER,
                    last_login_at INTEGER,
                    last_login_ip TEXT,
                    created_at INTEGER NOT NULL,
                    updated_at INTEGER NOT NULL
                )",
                "CREATE TABLE sessions (
                    id TEXT PRIMARY KEY,
                    user_id INTEGER NOT NULL,
                    ip TEXT,
                    ua TEXT,
                    csrf_token TEXT NOT NULL,
                    payload TEXT,
                    created_at INTEGER NOT NULL,
                    last_seen_at INTEGER NOT NULL,
                    expires_at INTEGER NOT NULL,
                    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
                )",
                "CREATE INDEX idx_sessions_user ON sessions(user_id)",
                "CREATE TABLE folders (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NOT NULL,
                    parent_id INTEGER,
                    name TEXT NOT NULL,
                    created_at INTEGER NOT NULL,
                    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
                    FOREIGN KEY(parent_id) REFERENCES folders(id) ON DELETE CASCADE
                )",
                "CREATE INDEX idx_folders_user ON folders(user_id)",
                "CREATE TABLE items (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NOT NULL,
                    folder_id INTEGER,
                    type TEXT NOT NULL,
                    mime TEXT NOT NULL,
                    original_name TEXT NOT NULL,
                    storage_backend TEXT NOT NULL DEFAULT 'local',
                    storage_path TEXT NOT NULL,
                    storage_id TEXT,
                    size INTEGER NOT NULL,
                    sha256 TEXT NOT NULL,
                    is_encrypted INTEGER NOT NULL DEFAULT 0,
                    thumbnail_path TEXT,
                    width INTEGER,
                    height INTEGER,
                    duration REAL,
                    ai_status TEXT NOT NULL DEFAULT 'pending',
                    ai_description TEXT,
                    ai_tags TEXT,
                    ai_ocr TEXT,
                    ai_embedding BLOB,
                    ai_provider TEXT,
                    ai_model TEXT,
                    ai_analyzed_at INTEGER,
                    ai_error TEXT,
                    is_public INTEGER NOT NULL DEFAULT 0,
                    share_token TEXT UNIQUE,
                    share_password_hash TEXT,
                    share_expires_at INTEGER,
                    share_max_views INTEGER,
                    share_view_count INTEGER NOT NULL DEFAULT 0,
                    deleted_at INTEGER,
                    created_at INTEGER NOT NULL,
                    updated_at INTEGER NOT NULL,
                    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
                    FOREIGN KEY(folder_id) REFERENCES folders(id) ON DELETE SET NULL
                )",
                "CREATE INDEX idx_items_user ON items(user_id)",
                "CREATE INDEX idx_items_folder ON items(folder_id)",
                "CREATE INDEX idx_items_share ON items(share_token)",
                "CREATE INDEX idx_items_ai_status ON items(ai_status)",
                "CREATE INDEX idx_items_created ON items(created_at DESC)",
                "CREATE VIRTUAL TABLE items_fts USING fts5(
                    original_name, ai_description, ai_ocr, ai_tags,
                    content='items', content_rowid='id',
                    tokenize='unicode61 remove_diacritics 2'
                )",
                "INSERT INTO items_fts(items_fts, rowid, original_name, ai_description, ai_ocr, ai_tags)
                    SELECT 'rebuild', id, original_name, COALESCE(ai_description,''), COALESCE(ai_ocr,''), COALESCE(ai_tags,'') FROM items WHERE deleted_at IS NULL",
                "CREATE TABLE tags (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NOT NULL,
                    name TEXT NOT NULL,
                    color TEXT,
                    created_at INTEGER NOT NULL,
                    UNIQUE(user_id, name),
                    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
                )",
                "CREATE TABLE item_tags (
                    item_id INTEGER NOT NULL,
                    tag_id INTEGER NOT NULL,
                    PRIMARY KEY(item_id, tag_id),
                    FOREIGN KEY(item_id) REFERENCES items(id) ON DELETE CASCADE,
                    FOREIGN KEY(tag_id) REFERENCES tags(id) ON DELETE CASCADE
                )",
                "CREATE TABLE api_keys (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NOT NULL,
                    provider TEXT NOT NULL,
                    label TEXT,
                    encrypted_key TEXT NOT NULL,
                    iv TEXT NOT NULL,
                    last_tested_at INTEGER,
                    last_test_status TEXT,
                    last_test_msg TEXT,
                    created_at INTEGER NOT NULL,
                    updated_at INTEGER NOT NULL,
                    UNIQUE(user_id, provider),
                    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
                )",
                "CREATE TABLE ai_models_cache (
                    provider TEXT NOT NULL,
                    model_id TEXT NOT NULL,
                    display_name TEXT,
                    supports_vision INTEGER NOT NULL DEFAULT 0,
                    supports_embedding INTEGER NOT NULL DEFAULT 0,
                    context_length INTEGER,
                    pricing_prompt REAL,
                    pricing_completion REAL,
                    raw_json TEXT,
                    fetched_at INTEGER NOT NULL,
                    PRIMARY KEY(provider, model_id)
                )",
                "CREATE TABLE cloud_tokens (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NOT NULL,
                    provider TEXT NOT NULL,
                    account_email TEXT,
                    encrypted_access TEXT NOT NULL,
                    iv_access TEXT NOT NULL,
                    encrypted_refresh TEXT,
                    iv_refresh TEXT,
                    scope TEXT,
                    expires_at INTEGER,
                    created_at INTEGER NOT NULL,
                    updated_at INTEGER NOT NULL,
                    UNIQUE(user_id, provider),
                    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
                )",
                "CREATE TABLE oauth_states (
                    state TEXT PRIMARY KEY,
                    user_id INTEGER NOT NULL,
                    provider TEXT NOT NULL,
                    code_verifier TEXT,
                    redirect_to TEXT,
                    created_at INTEGER NOT NULL
                )",
                "CREATE TABLE settings (
                    key TEXT NOT NULL,
                    value TEXT,
                    user_id INTEGER,
                    PRIMARY KEY(key, user_id)
                )",
                "CREATE TABLE api_tokens (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NOT NULL,
                    name TEXT NOT NULL,
                    token_hash TEXT NOT NULL,
                    last_used_at INTEGER,
                    created_at INTEGER NOT NULL,
                    expires_at INTEGER,
                    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
                )",
                "CREATE TABLE jobs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER,
                    type TEXT NOT NULL,
                    payload TEXT,
                    status TEXT NOT NULL DEFAULT 'pending',
                    attempts INTEGER NOT NULL DEFAULT 0,
                    last_error TEXT,
                    scheduled_at INTEGER NOT NULL,
                    started_at INTEGER,
                    finished_at INTEGER
                )",
                "CREATE INDEX idx_jobs_status ON jobs(status, scheduled_at)",
                "CREATE TABLE audit_log (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER,
                    action TEXT NOT NULL,
                    target TEXT,
                    ip TEXT,
                    ua TEXT,
                    meta TEXT,
                    created_at INTEGER NOT NULL
                )",
                "CREATE INDEX idx_audit_user ON audit_log(user_id, created_at DESC)",
                "CREATE INDEX idx_audit_action ON audit_log(action, created_at DESC)",
                "CREATE TABLE rate_limits (
                    key TEXT NOT NULL,
                    window_start INTEGER NOT NULL,
                    count INTEGER NOT NULL DEFAULT 0,
                    PRIMARY KEY(key, window_start)
                )",
                "CREATE TABLE recovery_codes (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NOT NULL,
                    code_hash TEXT NOT NULL,
                    used_at INTEGER,
                    created_at INTEGER NOT NULL,
                    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
                )",
            ],
            2 => [
                "DROP TABLE IF EXISTS items_fts",
                "DROP TABLE IF EXISTS items_fts_data",
                "DROP TABLE IF EXISTS items_fts_idx",
                "DROP TABLE IF EXISTS items_fts_docsize",
                "DROP TABLE IF EXISTS items_fts_config",
                "CREATE VIRTUAL TABLE items_fts USING fts5(
                    original_name, ai_description, ai_ocr, ai_tags,
                    content='items', content_rowid='id',
                    tokenize='unicode61 remove_diacritics 2'
                )",
                "INSERT INTO items_fts(rowid, original_name, ai_description, ai_ocr, ai_tags)
                    SELECT id, original_name, COALESCE(ai_description,''), COALESCE(ai_ocr,''), COALESCE(ai_tags,'') FROM items WHERE deleted_at IS NULL",
                "CREATE TRIGGER items_ai AFTER INSERT ON items BEGIN
                    INSERT INTO items_fts(rowid, original_name, ai_description, ai_ocr, ai_tags)
                    VALUES (new.id, new.original_name, COALESCE(new.ai_description,''), COALESCE(new.ai_ocr,''), COALESCE(new.ai_tags,''));
                END",
                "CREATE TRIGGER items_ad AFTER DELETE ON items BEGIN
                    INSERT INTO items_fts(items_fts, rowid) VALUES('delete', old.id);
                END",
                "CREATE TRIGGER items_au AFTER UPDATE ON items BEGIN
                    INSERT INTO items_fts(items_fts, rowid, original_name, ai_description, ai_ocr, ai_tags)
                    VALUES('delete', old.id, old.original_name, COALESCE(old.ai_description,''), COALESCE(old.ai_ocr,''), COALESCE(old.ai_tags,''));
                    INSERT INTO items_fts(rowid, original_name, ai_description, ai_ocr, ai_tags)
                    VALUES (new.id, new.original_name, COALESCE(new.ai_description,''), COALESCE(new.ai_ocr,''), COALESCE(new.ai_tags,''));
                END",
            ],
            3 => [
                "CREATE TABLE e2e_shares (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NOT NULL,
                    item_id INTEGER NOT NULL,
                    token TEXT UNIQUE NOT NULL,
                    payload_path TEXT NOT NULL,
                    size INTEGER NOT NULL,
                    expires_at INTEGER,
                    max_views INTEGER,
                    views INTEGER NOT NULL DEFAULT 0,
                    revoked_at INTEGER,
                    created_at INTEGER NOT NULL,
                    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
                    FOREIGN KEY(item_id) REFERENCES items(id) ON DELETE CASCADE
                )",
                "CREATE INDEX idx_e2e_shares_user ON e2e_shares(user_id)",
                "CREATE INDEX idx_e2e_shares_item ON e2e_shares(item_id)",
            ],
            4 => [
                "CREATE TABLE webhooks (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NOT NULL,
                    direction TEXT NOT NULL,
                    name TEXT NOT NULL,
                    token TEXT UNIQUE NOT NULL,
                    target_url TEXT,
                    events TEXT,
                    secret TEXT,
                    is_active INTEGER NOT NULL DEFAULT 1,
                    last_triggered_at INTEGER,
                    last_status INTEGER,
                    created_at INTEGER NOT NULL,
                    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
                )",
                "CREATE INDEX idx_webhooks_user ON webhooks(user_id)",
                "CREATE INDEX idx_webhooks_token ON webhooks(token)",
                "CREATE TABLE webhook_deliveries (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    webhook_id INTEGER NOT NULL,
                    event TEXT NOT NULL,
                    payload TEXT,
                    status INTEGER,
                    response_body TEXT,
                    created_at INTEGER NOT NULL,
                    FOREIGN KEY(webhook_id) REFERENCES webhooks(id) ON DELETE CASCADE
                )",
                "CREATE INDEX idx_webhook_deliveries ON webhook_deliveries(webhook_id, created_at DESC)",
            ],
            5 => [
                "ALTER TABLE recovery_codes ADD COLUMN expires_at INTEGER",
            ],
            6 => [
                "DROP TRIGGER IF EXISTS items_au",
                "CREATE TRIGGER items_au AFTER UPDATE ON items BEGIN
                    INSERT INTO items_fts(items_fts, rowid, original_name, ai_description, ai_ocr, ai_tags)
                    VALUES('delete', old.id, old.original_name, COALESCE(old.ai_description,''), COALESCE(old.ai_ocr,''), COALESCE(old.ai_tags,''));
                    INSERT INTO items_fts(rowid, original_name, ai_description, ai_ocr, ai_tags)
                    VALUES (new.id, new.original_name, COALESCE(new.ai_description,''), COALESCE(new.ai_ocr,''), COALESCE(new.ai_tags,''));
                END",
                "INSERT INTO items_fts(items_fts) VALUES('rebuild')",
                "DELETE FROM items WHERE id NOT IN (SELECT id FROM items)",
                "CREATE INDEX IF NOT EXISTS idx_items_user_sha ON items(user_id, sha256)",
                "UPDATE folders SET parent_id = NULL WHERE parent_id IS NOT NULL AND parent_id IN (SELECT id FROM folders WHERE user_id <> folders.user_id)",
            ],
            7 => [
                "CREATE TABLE IF NOT EXISTS api_refresh_tokens (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NOT NULL,
                    token_hash TEXT NOT NULL UNIQUE,
                    family_id TEXT NOT NULL,
                    used_at INTEGER,
                    revoked_at INTEGER,
                    expires_at INTEGER NOT NULL,
                    created_at INTEGER NOT NULL
                )",
                "CREATE INDEX IF NOT EXISTS idx_api_refresh_user ON api_refresh_tokens(user_id)",
                "CREATE INDEX IF NOT EXISTS idx_api_refresh_family ON api_refresh_tokens(family_id)",
            ],
            8 => [
                "UPDATE folders AS f SET parent_id = NULL
                 WHERE f.parent_id IS NOT NULL
                   AND EXISTS (SELECT 1 FROM folders p WHERE p.id = f.parent_id AND p.user_id <> f.user_id)",
                "DELETE FROM api_refresh_tokens WHERE expires_at < " . (time() - 86400) . " OR (used_at IS NOT NULL AND used_at < " . (time() - 86400 * 7) . ")",
            ],
        ];
        foreach ($migrations as $ver => $stmts) {
            if ($ver > $current) {
                foreach ($stmts as $sql) {
                    try {
                        $pdo->exec($sql);
                    } catch (PDOException $e) {
                        if (!str_contains(strtolower($e->getMessage()), 'duplicate column')) throw $e;
                    }
                }
                $pdo->prepare("INSERT OR REPLACE INTO schema_meta(key,value) VALUES('version',?)")->execute([(string)$ver]);
            }
        }
    }
}
