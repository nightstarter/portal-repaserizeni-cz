<?php

namespace App\Backup;

final class PrivateStoragePolicy
{
    /** @var list<array{path:string,classification:string}> */
    private const EXCLUDED_OPERATIONAL_ARTIFACTS = [
        ['path' => '.gitignore', 'classification' => 'DEPLOYMENT_CONTROL_FILE'],
        ['path' => 'legacy-shop-phase1c-report.json', 'classification' => 'GENERATED_DIAGNOSTIC_ARTIFACT'],
    ];

    /** @return list<array{path:string,classification:string}> */
    public function excludedOperationalArtifacts(): array
    {
        return self::EXCLUDED_OPERATIONAL_ARTIFACTS;
    }

    public function isExcludedOperationalArtifact(string $relativePath): bool
    {
        foreach (self::EXCLUDED_OPERATIONAL_ARTIFACTS as $artifact) {
            if ($relativePath === $artifact['path']) {
                return true;
            }
        }

        return false;
    }
}
