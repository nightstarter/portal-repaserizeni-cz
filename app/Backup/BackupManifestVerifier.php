<?php

namespace App\Backup;

use DateTimeImmutable;
use Throwable;

final class BackupManifestVerifier
{
    public function __construct(private readonly BackupPathGuard $paths) {}

    public function verify(string $path): BackupVerification
    {
        try {
            $resolved = $this->paths->existingBackup($path);
            $manifestPath = is_dir($resolved) ? $resolved.DIRECTORY_SEPARATOR.'manifest.json' : $resolved;
            if (is_link($manifestPath) || ! is_file($manifestPath)) {
                return $this->invalid('Manifest is missing or is not a regular file.');
            }
            $root = dirname($manifestPath);
            $raw = file_get_contents($manifestPath);
            $manifest = is_string($raw) ? json_decode($raw, true, flags: JSON_THROW_ON_ERROR) : null;
            if (! is_array($manifest)) {
                return $this->invalid('Manifest is not a JSON object.');
            }
        } catch (Throwable) {
            return $this->invalid('Manifest path or JSON is invalid.');
        }

        $errors = [];
        $warnings = [];
        $this->validateShape($manifest, $errors);
        $this->validateMetadata($manifest, $errors);
        $this->detectSecrets($manifest, $errors);

        $parts = $manifest['parts'] ?? null;
        if (! is_array($parts) || $parts === []) {
            $errors[] = 'Manifest must contain at least one file part.';
        } else {
            foreach ($parts as $index => $part) {
                $this->validatePart($root, $part, (int) $index, $errors);
            }
            $paths = array_column($parts, 'path');
            if (count($paths) !== count(array_unique($paths))) {
                $errors[] = 'Manifest contains duplicate part paths.';
            }
            $restorePaths = array_values(array_filter(array_column($parts, 'source_path'), 'is_string'));
            if (count($restorePaths) !== count(array_unique($restorePaths))) {
                $errors[] = 'Manifest contains duplicate private restore paths.';
            }
            $kinds = array_column($parts, 'kind');
            if (! in_array('database-dump', $kinds, true) || ! in_array('operations-config', $kinds, true)) {
                $errors[] = 'Required database and operations parts are missing.';
            }
        }

        if (($manifest['status'] ?? null) !== 'COMPLETE') {
            $errors[] = 'Backup status is not COMPLETE.';
        }
        if (! (bool) data_get($manifest, 'off_server.confirmed', false)) {
            $warnings[] = 'Off-server copy is not confirmed.';
        }
        if (data_get($manifest, 'restore_test.status') !== 'SUCCESS') {
            $warnings[] = 'Successful isolated restore test is not recorded.';
        }

        return new BackupVerification($errors === [], array_values(array_unique($errors)), $warnings, $manifest, $root);
    }

    private function validateMetadata(array $manifest, array &$errors): void
    {
        if (($manifest['format_version'] ?? null) !== (int) config('backups.format_version', 1)) {
            $errors[] = 'Unsupported manifest format version.';
        }
        if (! is_string($manifest['backup_id'] ?? null) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{7,127}\z/', $manifest['backup_id']) !== 1) {
            $errors[] = 'Backup ID is invalid.';
        }
        try {
            $created = new DateTimeImmutable((string) ($manifest['created_at_utc'] ?? ''));
            if ($created->getOffset() !== 0 || ! preg_match('/(?:Z|\+00:00)\z/', (string) $manifest['created_at_utc'])) {
                $errors[] = 'Backup timestamp must be UTC.';
            }
            if ($created > new DateTimeImmutable('+5 minutes')) {
                $errors[] = 'Backup timestamp is in the future.';
            }
        } catch (Throwable) {
            $errors[] = 'Backup timestamp is invalid.';
        }
        if (! in_array($manifest['environment'] ?? null, ['production', 'restore-test', 'testing'], true)) {
            $errors[] = 'Backup environment is invalid.';
        }
        if (! is_string($manifest['git_commit'] ?? null) || preg_match('/\A[0-9a-f]{7,40}\z/', $manifest['git_commit']) !== 1) {
            $errors[] = 'Git commit is invalid.';
        }
        if (! is_array(data_get($manifest, 'schema.migrations')) || ! is_string(data_get($manifest, 'schema.fingerprint'))) {
            $errors[] = 'Schema migration metadata is incomplete.';
        } elseif (! hash_equals(hash('sha256', json_encode(data_get($manifest, 'schema.migrations'), JSON_UNESCAPED_SLASHES)), data_get($manifest, 'schema.fingerprint'))) {
            $errors[] = 'Schema migration fingerprint does not match.';
        }
        if (! is_string(data_get($manifest, 'database.engine')) || ! is_string(data_get($manifest, 'database.version'))) {
            $errors[] = 'Database engine metadata is incomplete.';
        }
        if (! is_string(data_get($manifest, 'database.source_identity_sha256'))
            || preg_match('/\A[0-9a-f]{64}\z/', data_get($manifest, 'database.source_identity_sha256')) !== 1) {
            $errors[] = 'Database source identity fingerprint is invalid.';
        }
        $counts = data_get($manifest, 'database.critical_counts');
        foreach ((array) config('backups.critical_tables', []) as $table) {
            if (! is_array($counts) || ! array_key_exists($table, $counts) || ! is_int($counts[$table]) || $counts[$table] < 0) {
                $errors[] = 'Critical table counts are incomplete.';
                break;
            }
        }
        foreach ([
            'database', 'private_storage', 'apache_php_configuration', 'webserver_cron_export',
            'windows_task_scheduler_export', 'connector_config_state', 'deployed_revision',
            'runtime_versions', 'protected_secret_escrow',
        ] as $component) {
            if (data_get($manifest, 'components.'.$component) !== true) {
                $errors[] = 'Required backup component confirmations are incomplete.';
                break;
            }
        }
    }

    private function validateShape(array $manifest, array &$errors): void
    {
        $allowed = [
            '' => ['format_version', 'backup_id', 'created_at_utc', 'environment', 'status', 'git_commit', 'schema', 'database', 'components', 'runtime', 'parts', 'off_server', 'restore_test'],
            'schema' => ['migrations', 'fingerprint'],
            'database' => ['engine', 'version', 'source_identity_sha256', 'critical_counts'],
            'components' => ['database', 'private_storage', 'apache_php_configuration', 'webserver_cron_export', 'windows_task_scheduler_export', 'connector_config_state', 'deployed_revision', 'runtime_versions', 'protected_secret_escrow'],
            'runtime' => ['portal_php', 'connector_php', 'connector_os', 'mariadb'],
            'off_server' => ['confirmed', 'confirmed_at_utc', 'evidence_id'],
            'restore_test' => ['status', 'completed_at_utc', 'duration_seconds', 'evidence_id'],
        ];
        foreach ($allowed as $path => $keys) {
            $value = $path === '' ? $manifest : data_get($manifest, $path);
            if (is_array($value) && array_diff(array_keys($value), $keys) !== []) {
                $errors[] = 'Manifest contains an unknown metadata field.';

                return;
            }
        }
        $counts = data_get($manifest, 'database.critical_counts');
        if (is_array($counts) && array_diff(array_keys($counts), (array) config('backups.critical_tables', [])) !== []) {
            $errors[] = 'Manifest contains an unknown critical table count.';
        }
        foreach ((array) ($manifest['parts'] ?? []) as $part) {
            if (is_array($part) && array_diff(array_keys($part), ['name', 'kind', 'path', 'size', 'sha256', 'source_path', 'mode', 'encrypted']) !== []) {
                $errors[] = 'Manifest part contains an unknown metadata field.';

                return;
            }
        }
    }

    private function validatePart(string $root, mixed $part, int $index, array &$errors): void
    {
        if (! is_array($part)) {
            $errors[] = "Part {$index} is invalid.";

            return;
        }
        if (! is_string($part['name'] ?? null) || preg_match('/\A[A-Za-z0-9._-]{1,128}\z/', $part['name']) !== 1) {
            $errors[] = "Part {$index} name is invalid.";
        }
        $relative = $part['path'] ?? null;
        if (! is_string($relative) || $relative === '' || str_contains($relative, "\0") || str_starts_with($relative, '/')
            || preg_match('/\A[A-Za-z]:[\\\\\/]/', $relative) === 1
            || preg_match('/(^|[\\\\\/])\.\.([\\\\\/]|$)/', $relative) === 1) {
            $errors[] = "Part {$index} has an unsafe path.";

            return;
        }
        $candidate = $root.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
        if (is_link($candidate)) {
            $errors[] = "Part {$index} must not be a symlink.";

            return;
        }
        $resolved = realpath($candidate);
        if ($resolved === false || ! is_file($resolved) || ! $this->paths->isWithin($resolved, $root)) {
            $errors[] = "Part {$index} is missing or escapes the backup root.";

            return;
        }
        $size = filesize($resolved);
        $checksum = hash_file('sha256', $resolved);
        if (! is_int($part['size'] ?? null) || $size !== $part['size']) {
            $errors[] = "Part {$index} size does not match.";
        }
        if (! is_string($part['sha256'] ?? null) || ! hash_equals(strtolower($part['sha256']), $checksum)) {
            $errors[] = "Part {$index} checksum does not match.";
        }
        if (! in_array($part['kind'] ?? null, ['database-dump', 'private-file', 'operations-config'], true)) {
            $errors[] = "Part {$index} kind is invalid.";
        }
        if (($part['kind'] ?? null) === 'operations-config' && ($part['encrypted'] ?? null) !== true) {
            $errors[] = "Part {$index} operations configuration must be externally encrypted.";
        }
        if (($part['kind'] ?? null) === 'private-file') {
            $sourcePath = $part['source_path'] ?? null;
            if (! is_string($sourcePath) || preg_match('/\A[A-Za-z0-9._\/-]+\z/', $sourcePath) !== 1
                || str_starts_with($sourcePath, '/') || preg_match('/(^|\/)\.\.($|\/)/', $sourcePath) === 1) {
                $errors[] = "Part {$index} private restore path is invalid.";
            }
            if (! is_string($part['mode'] ?? null) || preg_match('/\A0[0-7]{3}\z/', $part['mode']) !== 1) {
                $errors[] = "Part {$index} private file mode is invalid.";
            }
        }
    }

    private function detectSecrets(array $manifest, array &$errors, string $prefix = ''): void
    {
        foreach ($manifest as $key => $value) {
            $name = strtolower((string) $key);
            $path = $prefix === '' ? $name : $prefix.'.'.$name;
            if (! is_bool($value) && preg_match('/(?:password|passwd|app_key|hmac|smtp_secret|dsn|database_url|secret)/', $name) === 1) {
                $errors[] = 'Manifest contains a forbidden secret field.';

                return;
            }
            if (is_array($value)) {
                $this->detectSecrets($value, $errors, $path);
            } elseif (is_string($value) && (preg_match('/\b(?:APP_KEY|DB_PASSWORD|MAIL_PASSWORD|POHODA_BRIDGE_SECRET)\s*=/', $value) === 1
                || preg_match('#://[^/@:\s]+:[^/@\s]+@#', $value) === 1)) {
                $errors[] = 'Manifest contains a forbidden secret value.';

                return;
            }
        }
    }

    private function invalid(string $message): BackupVerification
    {
        return new BackupVerification(false, [$message], []);
    }
}
