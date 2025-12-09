<?php
function compute_entry_hash(array $entry, string $prev_hash = ''): string {
    $required_keys = ['rev', 'role', 'user', 'timestamp', 'external_ts', 'version', 'expected', 'fp_hash'];
    $data = [];

    foreach ($required_keys as $key) {
        if (!array_key_exists($key, $entry)) {
            throw new RuntimeException("Missing required key '$key' in entry");
        }
        $data[$key] = $entry[$key];
    }

    return hash('sha256', $prev_hash . json_encode($data));
}
?>