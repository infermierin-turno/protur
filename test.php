<?php
// test_login.php - Script di diagnostica temporaneo
require_once 'config.php';

echo "<h2>Diagnostica Connessione Supabase</h2>";

$email_test = 'sabella@pellegrini.it';
$password_test = 'sabella';

// 1. Test chiamata cURL
echo "<p>1. Interrogazione tabella staging_utenti per l'email: <strong>$email_test</strong>...</p>";
$risultato = supabase_request('staging_utenti?email=eq.' . urlencode($email_test) . '&select=*');

if (isset($risultato['error'])) {
    echo "<p style='color:red;'>Errore cURL/Supabase (Codice {$risultato['code']}):</p>";
    echo "<pre>" . htmlspecialchars($risultato['message']) . "</pre>";
} else {
    echo "<p style='color:green;'>Chiamata cURL riuscita con successo.</p>";
    echo "<p>Risultato grezzo ricevuto:</p>";
    echo "<pre>" . htmlspecialchars(print_r($risultato, true)) . "</pre>";
    
    if (empty($risultato)) {
        echo "<p style='color:orange;'><strong>Attenzione:</strong> La tabella 'staging_utenti' ha risposto ma restituisce un array vuoto. Significa che l'utente con email <code>$email_test</code> non esiste nel database o la RLS sta bloccando la lettura.</p>";
    } else {
        $medico = $risultato[0];
        echo "<p>Utente trovato nel database: ID <strong>{$medico['id']}</strong></p>";
        echo "<p>Hash salvato nel DB: <code>{$medico['password_hash']}</code></p>";
        
        // 2. Test verifica password
        $verifica = password_verify($password_test, $medico['password_hash']);
        if ($verifica) {
            echo "<p style='color:green; font-size:18px;'><strong>VERIFICA PASSWORD RIUSCITA!</strong> L'hash corrisponde perfettamente alla password '$password_test'.</p>";
        } else {
            echo "<p style='color:red; font-size:18px;'><strong>VERIFICA PASSWORD FALLITA!</strong> L'hash nel database non corrisponde alla password '$password_test'.</p>";
        }
    }
}
?>