<?php

$projectDataRoot = str_replace('\\', '/', dirname(base_path())).'/project-data';

return [
    // Empty keeps the existing Windows folders. Cloud deployments use a private S3 disk.
    'cloud' => env('TRACKER_CLOUD', false),
    'media_disk' => env('TRACKER_MEDIA_DISK', ''),
    'attachments' => env('TRACKER_ATTACHMENTS_PATH', $projectDataRoot.'/attachments'),
    'profile_photos' => env('TRACKER_PROFILE_PHOTOS_PATH', $projectDataRoot.'/profile-photos'),
];
