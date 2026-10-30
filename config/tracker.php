<?php

$projectDataRoot = str_replace('\\', '/', dirname(base_path())).'/project-data';

return [
    'cloud' => env('TRACKER_CLOUD', false),
    'media_disk' => env('TRACKER_MEDIA_DISK', ''),
    'attachments' => env('TRACKER_ATTACHMENTS_PATH', $projectDataRoot.'/attachments'),
    'profile_photos' => env('TRACKER_PROFILE_PHOTOS_PATH', $projectDataRoot.'/profile-photos'),
];
