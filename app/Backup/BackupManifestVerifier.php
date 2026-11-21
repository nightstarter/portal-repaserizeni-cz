<?php

namespace App\Backup;

use DateTimeImmutable;
use PharData;
use RecursiveIteratorIterator;
use Throwable;

final class BackupManifestVerifier
{
    public function __construct(
        private readonly BackupPathGuard $paths,
        private readonly PrivateStoragePolicy $privateStoragePolicy,
    ) {}

    public function verify(string $path): BackupVerification
    {
        return $this->verifyPath($path, 'COMPLETE');
    }

    public function verifyCandidate(BackupCandidateManifest $candidate): BackupVerification
    {
        $root = realpath($candidate->stagingRoot);
        $manifest = realpath($candidate->manifestPath);
        if ($root === false || $manifest === false || dirname($manifest) !== $root
            || preg_match('/\A\.manifest-incomplete-[0-9a-f]{24}\.json\z/', basename($manifest)) !== 1) {
            return $this->invalid('Candidate manifest path is unsafe.', 'UNSAFE_COMPONENT_PATH', 'manifest.json');
        }

        return $this->verifyPath($manifest, 'INCOMPLETE', $root);
    }

    private function verifyPath(string $path, string $expectedStatus, ?string $expectedRoot = null): BackupVerification
    {
        try {
            $resolved = $this->paths->existingBackup($path);
            $manifestPath = is_dir($resolved) ? $resolved.DIRECTORY_SEPARATOR.'manifest.json' : $resolved;
            if (is_link($manifestPath) || ! is_file($manifestPath)) {
                return $this->invalid('Manifest is missing or is not a regular file.');
            }
            $root = dirname($manifestPath);
            if ($expectedRoot !== null && $root !== $expectedRoot) {
                return $this->invalid('Candidate manifest root is invalid.', 'UNSAFE_COMPONENT_PATH', 'manifest.json');
            }
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
            $newPackage = in_array('private-archive', $kinds, true);
            if (! in_array('database-dump', $kinds, true)
                || ($newPackage && (! in_array('metadata', $kinds, true)))
                || (! $newPackage && ! in_array('operations-config', $kinds, true))) {
                $errors[] = 'Required backup package parts are missing.';
            }
            $this->validatePackageContents($root, $parts, $manifest, $errors);
        }

        if (($manifest['status'] ?? null) !== $expectedStatus) {
            $errors[] = 'Backup status is not '.$expectedStatus.'.';
        }
        $errors = array_values(array_unique($errors));
        [$verificationCode, $component] = $this->classify($errors, $manifest);

        return new BackupVerification($errors === [], $errors, $warnings, $manifest, $root, $verificationCode, $component);
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
        $requiredComponents = array_key_exists('schema_metadata', (array) ($manifest['components'] ?? []))
            ? ['database', 'private_storage', 'schema_metadata', 'deployed_revision', 'runtime_versions']
            : [
                'database', 'private_storage', 'apache_php_configuration', 'webserver_cron_export',
                'windows_task_scheduler_export', 'connector_config_state', 'deployed_revision',
                'runtime_versions', 'protected_secret_escrow',
            ];
        foreach ($requiredComponents as $component) {
            if (data_get($manifest, 'components.'.$component) !== true) {
                $errors[] = 'Required backup component confirmations are incomplete.';
                break;
            }
        }
        if (data_get($manifest, 'private_storage.excluded_operational_artifacts') !== $this->privateStoragePolicy->excludedOperationalArtifacts()) {
            $errors[] = 'Private storage exclusion policy does not match this application version.';
        }
    }

    private function validateShape(array $manifest, array &$errors): void
    {
        $allowed = [
            '' => ['format_version', 'backup_id', 'created_at_utc', 'environment', 'status', 'git_commit', 'schema', 'database', 'private_storage', 'components', 'runtime', 'parts', 'off_server', 'restore_test'],
            'schema' => ['migrations', 'fingerprint'],
            'database' => ['engine', 'version', 'source_identity_sha256', 'critical_counts', 'logical_objects', 'dump_options'],
            'private_storage' => ['excluded_operational_artifacts'],
            'components' => ['database', 'private_storage', 'schema_metadata', 'apache_php_configuration', 'webserver_cron_export', 'windows_task_scheduler_export', 'connector_config_state', 'deployed_revision', 'runtime_versions', 'protected_secret_escrow'],
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
        $logicalObjects = data_get($manifest, 'database.logical_objects');
        foreach (['triggers', 'routines', 'events'] as $type) {
            if (! is_array($logicalObjects) || ! is_int($logicalObjects[$type] ?? null) || $logicalObjects[$type] < 0) {
                $errors[] = 'Database logical object inventory is incomplete.';
                break;
            }
        }
        $dumpOptions = data_get($manifest, 'database.dump_options');
        foreach (['single_transaction', 'quick', 'skip_lock_tables', 'no_tablespaces', 'triggers', 'routines', 'events'] as $option) {
            if (! is_array($dumpOptions) || ! is_bool($dumpOptions[$option] ?? null)) {
                $errors[] = 'Database dump option metadata is incomplete.';
                break;
            }
        }
        foreach ((array) ($manifest['parts'] ?? []) as $part) {
            if (is_array($part) && array_diff(array_keys($part), ['name', 'kind', 'path', 'size', 'sha256', 'source_path', 'mode', 'encrypted', 'entries']) !== []) {
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
        if (! in_array($part['kind'] ?? null, ['database-dump', 'private-file', 'private-archive', 'metadata', 'operations-config'], true)) {
            $errors[] = "Part {$index} kind is invalid.";
        }
        if (($part['kind'] ?? null) === 'database-dump' && $size < 1) {
            $errors[] = "Part {$index} database dump is empty.";
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

    private function validatePackageContents(string $root, array $parts, array $manifest, array &$errors): void
    {
        $archivePart = collect($parts)->firstWhere('kind', 'private-archive');
        if (is_array($archivePart)) {
            $this->validateArchive($root, $archivePart, $errors);
        }
        $metadataPart = collect($parts)->firstWhere('kind', 'metadata');
        if (is_array($metadataPart) && is_string($metadataPart['path'] ?? null)) {
            try {
                $metadata = json_decode((string) file_get_contents($root.DIRECTORY_SEPARATOR.$metadataPart['path']), true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($metadata)) {
                    throw new \RuntimeException;
                }
                $metadataErrors = [];
                $this->detectSecrets($metadata, $metadataErrors);
                if ($metadataErrors !== []
                    || ($metadata['backup_id'] ?? null) !== ($manifest['backup_id'] ?? null)
                    || ($metadata['git_commit'] ?? null) !== ($manifest['git_commit'] ?? null)
                    || ($metadata['schema'] ?? null) !== ($manifest['schema'] ?? null)
                    || ($metadata['critical_counts'] ?? null) !== data_get($manifest, 'database.critical_counts')
                    || data_get($metadata, 'database_dump.logical_objects') !== data_get($manifest, 'database.logical_objects')
                    || data_get($metadata, 'database_dump.options') !== data_get($manifest, 'database.dump_options')
                    || data_get($metadata, 'private_storage.excluded_operational_artifacts') !== data_get($manifest, 'private_storage.excluded_operational_artifacts')) {
                    $errors[] = 'Sanitized metadata content is invalid or inconsistent.';
                }
            } catch (Throwable) {
                $errors[] = 'Sanitized metadata file is not valid JSON.';
            }
        }
    }

    private function validateArchive(string $root, array $part, array &$errors): void
    {
        $declared = $part['entries'] ?? null;
        if (! is_array($declared)) {
            $errors[] = 'Private archive entry inventory is missing.';

            return;
        }
        $expected = [];
        foreach ($declared as $entry) {
            if (! is_array($entry) || array_diff(array_keys($entry), ['path', 'size', 'sha256', 'mode']) !== []) {
                $errors[] = 'Private archive entry metadata is invalid.';

                return;
            }
            $path = $entry['path'] ?? null;
            if (! is_string($path) || preg_match('/\A[A-Za-z0-9._\/-]+\z/', $path) !== 1
                || str_starts_with($path, '/') || preg_match('/(^|\/)\.\.($|\/)/', $path) === 1
                || ! is_int($entry['size'] ?? null) || $entry['size'] < 0
                || ! is_string($entry['sha256'] ?? null) || preg_match('/\A[0-9a-f]{64}\z/', $entry['sha256']) !== 1
                || ! is_string($entry['mode'] ?? null) || preg_match('/\A0[0-7]{3}\z/', $entry['mode']) !== 1) {
                $errors[] = 'Private archive entry metadata is invalid.';

                return;
            }
            if ($this->privateStoragePolicy->isExcludedOperationalArtifact($path)) {
                $errors[] = 'Private archive declares an excluded operational artifact.';

                return;
            }
            $expected[$path] = $entry;
        }
        if (count($expected) !== count($declared)) {
            $errors[] = 'Private archive contains duplicate declared paths.';

            return;
        }
        try {
            $archivePath = $root.DIRECTORY_SEPARATOR.$part['path'];
            $archive = new PharData($archivePath);
            $actual = [];
            $prefix = 'phar://'.$archivePath.'/';
            foreach (new RecursiveIteratorIterator($archive) as $key => $file) {
                if ($file->isLink() || ! $file->isFile() || ! str_starts_with((string) $key, $prefix)) {
                    throw new \RuntimeException;
                }
                $path = substr((string) $key, strlen($prefix));
                $contents = file_get_contents((string) $key);
                if (! is_string($contents)) {
                    throw new \RuntimeException;
                }
                $actual[$path] = ['size' => strlen($contents), 'sha256' => hash('sha256', $contents)];
            }
            if (array_keys($actual) !== array_keys($expected)) {
                $errors[] = 'Private archive file list does not match its manifest inventory.';

                return;
            }
            foreach ($actual as $path => $value) {
                if ($value['size'] !== $expected[$path]['size'] || ! hash_equals($expected[$path]['sha256'], $value['sha256'])) {
                    $errors[] = 'Private archive entry checksum or size does not match.';

                    return;
                }
            }
        } catch (Throwable) {
            $errors[] = 'Private storage archive is unreadable or unsafe.';
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

    /** @return array{0:?string,1:?string} */
    private function classify(array $errors, array $manifest): array
    {
        if ($errors === []) {
            return [null, null];
        }

        foreach ($errors as $error) {
            $component = $this->componentForError($error, $manifest);
            $code = match (true) {
                str_contains($error, 'status is not') => 'MANIFEST_STATUS_INVALID',
                str_contains($error, 'missing or escapes'), str_contains($error, 'Required backup package parts are missing'),
                str_contains($error, 'Manifest is missing') => 'COMPONENT_MISSING',
                str_contains($error, 'unsafe path'), str_contains($error, 'must not be a symlink'),
                str_contains($error, 'escapes the backup root'), str_contains($error, 'restore path is invalid') => 'UNSAFE_COMPONENT_PATH',
                str_contains($error, 'Private archive'), str_contains($error, 'Private storage archive'),
                str_contains($error, 'Private storage exclusion') => 'PRIVATE_INVENTORY_MISMATCH',
                str_contains($error, 'size does not match') => 'COMPONENT_SIZE_MISMATCH',
                str_contains($error, 'checksum does not match') && ! str_contains($error, 'archive entry') => 'COMPONENT_CHECKSUM_MISMATCH',
                str_contains($error, 'metadata content'), str_contains($error, 'metadata file'),
                str_contains($error, 'Critical table counts'), str_contains($error, 'Schema migration'),
                str_contains($error, 'Database engine metadata'), str_contains($error, 'Database source identity'),
                str_contains($error, 'logical object'), str_contains($error, 'dump option') => 'DATABASE_METADATA_MISMATCH',
                str_contains($error, 'Manifest'), str_contains($error, 'manifest'),
                str_contains($error, 'Unsupported manifest'), str_contains($error, 'Backup ID'),
                str_contains($error, 'Backup timestamp'), str_contains($error, 'Backup environment'),
                str_contains($error, 'Git commit'), str_contains($error, 'Required backup component') => 'MANIFEST_INVALID',
                default => 'VERIFY_INTERNAL_ERROR',
            };

            return [$code, $component];
        }

        return ['VERIFY_INTERNAL_ERROR', null];
    }

    private function componentForError(string $error, array $manifest): ?string
    {
        if (preg_match('/\APart (\d+) /', $error, $match) === 1) {
            $part = $manifest['parts'][(int) $match[1]] ?? null;
            $name = is_array($part) ? ($part['name'] ?? null) : null;

            return is_string($name) && preg_match('/\A[A-Za-z0-9._-]{1,128}\z/', $name) === 1 ? $name : null;
        }
        if (str_contains($error, 'Private archive') || str_contains($error, 'Private storage archive')) {
            return 'private-storage.tar';
        }
        if (str_contains($error, 'metadata')) {
            return 'metadata.json';
        }

        return 'manifest.json';
    }

    private function invalid(string $message, string $code = 'MANIFEST_INVALID', ?string $component = 'manifest.json'): BackupVerification
    {
        return new BackupVerification(false, [$message], [], null, null, $code, $component);
    }
}
