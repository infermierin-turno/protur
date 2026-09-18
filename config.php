<?php
// Avvio della sessione centralizzato per tutta l'applicazione
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Configurazione Supabase
define('SUPABASE_URL', 'https://cfrsuknofgywvznjhqvp.supabase.co');
define('SUPABASE_KEY', 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImNmcnN1a25vZmd5d3Z6bmpocXZwIiwicm9sZSI6ImFub24iLCJpYXQiOjE3ODA5OTI1NjMsImV4cCI6MjA5NjU2ODU2M30.udFkfESJsJKdho5vhJR-MdgpIYby9uqMWRsZtymbv0Y');

/**
 * Funzione centralizzata per le chiamate REST a Supabase tramite cURL
 * Punta direttamente alle tabelle con prefisso nello schema pubblico
 */
function supabase_request($endpoint, $query = '', $method = 'GET', $data = null) {
    $url = SUPABASE_URL . '/rest/v1/' . $endpoint . $query;
    
    $headers = [
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
        'Content-Type: application/json',
        'Prefer: return=representation'
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    
    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($response, true);

    if ($http_code >= 200 && $http_code < 300) {
        return is_array($decoded) ? $decoded : [];
    }

    if (is_array($decoded) && isset($decoded['code'])) {
        return $decoded;
    }

    return ['code' => 'HTTP_' . $http_code, 'message' => $response];
}
?>