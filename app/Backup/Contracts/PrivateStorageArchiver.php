<?php

namespace App\Backup\Contracts;

interface PrivateStorageArchiver
{
    /** @return list<array{path:string,size:int,sha256:string,mode:string}> */
    public function inventory(string $source): array;

    /** @return list<array{path:string,size:int,sha256:string,mode:string}> */
    public function archive(string $source, string $outputPath): array;
}
