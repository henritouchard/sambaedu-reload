<?php

/**
 * Compatibilité encrypt/decrypt legacy SambaEdu.
 *
 * DOIT être chargé AVANT le autoloader Composer (dans public/index.php)
 * pour que les function_exists() guards de Laravel cèdent la priorité.
 *
 * Détecte le pattern d'appel :
 * - Legacy : encrypt($config, $data) — $config est un array avec 'url_key'
 * - Laravel : encrypt($value) ou encrypt($value, $serialize)
 *
 * Et délègue vers la bonne implémentation.
 */

if (!function_exists('encrypt')) {
    function encrypt($configOrValue, $dataOrSerialize = true)
    {
        // Appel legacy : encrypt($config_array, $string_data)
        if (is_array($configOrValue)) {
            if (empty($configOrValue['url_key'])) {
                return '';
            }
            $key = hex2bin($configOrValue['url_key']);
            $method = 'aes-128-cbc';
            $iv_length = openssl_cipher_iv_length($method);
            $iv = openssl_random_pseudo_bytes($iv_length);
            $encrypted_1 = openssl_encrypt((string) $dataOrSerialize, $method, $key, OPENSSL_RAW_DATA, $iv);
            $encrypted_2 = hash_hmac('sha256', $encrypted_1, $key, true);
            return bin2hex($iv . $encrypted_1 . $encrypted_2);
        }

        // Appel Laravel : encrypt($value, $serialize = true)
        return app('encryptor')->encrypt($configOrValue, (bool) $dataOrSerialize);
    }
}

if (!function_exists('decrypt')) {
    function decrypt($configOrInput, $inputOrUnserialize = true)
    {
        // Appel legacy : decrypt($config_array, $hex_string)
        if (is_array($configOrInput)) {
            if (empty($inputOrUnserialize) || empty($configOrInput['url_key'])) {
                return $inputOrUnserialize;
            }
            $key = hex2bin($configOrInput['url_key']);
            $mix = @hex2bin($inputOrUnserialize);
            if ($mix === false) {
                return '';
            }
            $method = 'aes-128-cbc';
            $iv_length = openssl_cipher_iv_length($method);
            $iv = substr($mix, 0, $iv_length);
            $encrypted_2 = substr($mix, -32);
            $encrypted_1 = substr($mix, $iv_length, -32);
            $data = openssl_decrypt($encrypted_1, $method, $key, OPENSSL_RAW_DATA, $iv);
            if ($data !== false && hash_equals($encrypted_2, hash_hmac('sha256', $encrypted_1, $key, true))) {
                return $data;
            }
            return '';
        }

        // Appel Laravel : decrypt($payload, $unserialize = true)
        return app('encryptor')->decrypt($configOrInput, (bool) $inputOrUnserialize);
    }
}
