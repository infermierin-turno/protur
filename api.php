<?php
// config.php
define('SUPABASE_URL', 'https://oviraytzenzanvgchkgg.supabase.co');
define('SUPABASE_KEY', 'sb_publishable__pOnisyckhlkbs26ooNaKg_qIhAAZSt');

/**
 * Funzione centralizzata per le chiamate a Supabase.
 * Usa questa funzione per ogni operazione (SELECT, INSERT, ecc.)
 */
function chiamate_supabase($endpoint, $metodo = 'GET', $dati = null) {
    $url = SUPABASE_URL . '/rest/v1/' . $endpoint;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $metodo);
    
    $headers = [
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
        'Content-Type: application/json',
        'Prefer: return=representation'
    ];

    if ($dati) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($dati));
        $headers[] = 'Content-Length: ' . strlen(json_encode($dati));
    }
    
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ["error" => $error];
    }
    
    return json_decode($response, true);
}

/**
 * Funzione per ottenere i dati del dashboard
 */
function get_dashboard_data() {
    require_once 'config.php';
    $filtro = get_auth_query();

    $response = chiamate_supabase('your_table_name', 'GET', $filtro);

    if (isset($response['error'])) {
        return ['prossimo_turno' => 'Errore di connessione', 'ore_totali' => 'Errore di connessione'];
    }

    // Estrai i dati necessari
    $next_turn = null;
    $total_hours = 0;

    foreach ($response as $item) {
        if (isset($item['prossimo_turno'])) {
            $next_turn = $item['prossimo_turno'];
        }
        if (isset($item['ore_totali'])) {
            $total_hours += $item['ore_totali'];
        }
    }

    return ['prossimo_turno' => $next_turn, 'ore_totali' => $total_hours];
}

/**
 * Funzione per ottenere il filtro di autenticazione basato sull'utente
 */
function get_auth_query() {
    // Implementa qui la logica per ottenere il filtro di autenticazione
    // ad esempio: return ['id' => $_SESSION['utente_id']];
}
?>
