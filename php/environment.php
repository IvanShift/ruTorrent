<?php

// Persisted configurations may still read only $_ENV. Preserve explicit values,
// including an empty RU_LOG_FILE, which intentionally disables application logging.
foreach(array('RU_LOCALHOSTS', 'RU_LOG_FILE', 'RU_TOP_DIR', 'RU_SCGI_HOST',
    'RU_SCGI_PORT', 'RU_PROFILE_PATH', 'RU_PROFILE_MASK', 'RU_TEMP_DIRECTORY') as $setting)
{
    if(!array_key_exists($setting, $_ENV))
    {
        $value = getenv($setting);
        if($value !== false)
            $_ENV[$setting] = $value;
    }
}
