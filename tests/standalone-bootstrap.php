<?php

// Allows dependency-free execution of the pure enum unit test when Composer
// dependencies have not been installed yet. Normal CI uses vendor/autoload.php.
require_once __DIR__.'/../app/Enums/OrganizationCode.php';
require_once __DIR__.'/../app/Enums/UserRole.php';
