<?php
// crea_utente.php
require_once 'config.php';

// Dati del primo utente (Super Admin)
$nome = "Gianni SuperAdmin";
$email = "admin@ospedale.it";
$password_in_chiaro = "PasswordSicura123!";
$ruolo = "admin"; 
$organizzazione_id = null; // Il Super Admin non è limitato a una specifica organizzazione
$reparto_id = null;        // Nessun reparto specifico
$is_super_admin = true;    // Impostato a TRUE per i privilegi di Super Admin

// Generazione dell'hash sicuro della password
$password_hash = password_hash($password_in_chiaro, PASSWORD_DEFAULT);

// Preparazione dei dati per Supabase
$nuovo_utente = [
    'organizzazione_id' => $organizzazione_id,
    'reparto_id' => $reparto_id,
    'nome' => $nome,
    'email' => $email,
    'ruolo' => $ruolo,
    'password_hash' => $password_hash,
    'is_super_admin' => $is_super_admin
];

// Richiesta POST a Supabase per inserire l'utente nella tabella 'utenti'
$response = supabase_request('utenti', '', 'POST', $nuovo_utente);

?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Creazione Utente Super Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="container mt-5">
    <div class="card p-4 shadow-sm">
        <h3 class="mb-3">Esito Creazione Super Admin</h3>
        <?php if ($response['status'] === 201 || $response['status'] === 200): ?>
            <div class="alert alert-success">
                <strong>Super Admin creato con successo!</strong><br><br>
                <strong>Email:</strong> <?php echo htmlspecialchars($email); ?><br>
                <strong>Ruolo:</strong> Super Admin<br>
                <strong>Password Hash:</strong> <code><?php echo htmlspecialchars($password_hash); ?></code>
            </div>
        <?php else: ?>
            <div class="alert alert-danger">
                Errore durante la creazione (Codice HTTP: <?php echo $response['status']; ?>):
                <pre><?php print_r($response['data']); ?></pre>
            </div>
        <?php endif; ?>
        <a href="index.php" class="btn btn-primary mt-3">Vai al Login</a>
    </div>
</body>
</html>