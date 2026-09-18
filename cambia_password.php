<?php
session_start();
if (!isset($_SESSION['utente'])) {
    header("Location: index.php");
    exit;
}
require_once 'config.php';

// Estrazione sicura dell'ID utente dalla sessione
$utente_id = $_SESSION['utente_id'] ?? $_SESSION['utente']['id'] ?? $_SESSION['utente']['ID'] ?? null;

if (empty($utente_id)) {
    header("Location: dashboard.php");
    exit;
}

$messaggio = '';
$tipo_alert = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password_attuale = $_POST['password_attuale'] ?? '';
    $nuova_password = $_POST['nuova_password'] ?? '';
    $conferma_password = $_POST['conferma_password'] ?? '';

    if (empty($password_attuale) || empty($nuova_password) || empty($conferma_password)) {
        $messaggio = "Tutti i campi sono obbligatori.";
        $tipo_alert = "danger";
    } elseif ($nuova_password !== $conferma_password) {
        $messaggio = "La nuova password e la conferma non coincidono.";
        $tipo_alert = "danger";
    } elseif (strlen($nuova_password) < 6) {
        $messaggio = "La nuova password deve essere di almeno 6 caratteri.";
        $tipo_alert = "danger";
    } else {
        // Recupero utente da staging_utenti per verifica password attuale
        $dati_utente = supabase_request('staging_utenti', "?id=eq.$utente_id&select=*");
        
        if (!empty($dati_utente) && is_array($dati_utente)) {
            $userRecord = $dati_utente[0];
            $password_db = $userRecord['password_hash'] ?? $userRecord['password'] ?? '';

            $password_valida = false;
            // Controllo se la password nel DB è cifrata con password_verify o in chiaro
            if (!empty($password_db) && password_get_info($password_db)['algo'] !== 0) {
                if (password_verify($password_attuale, $password_db)) {
                    $password_valida = true;
                }
            } else {
                if ($password_attuale === $password_db) {
                    $password_valida = true;
                }
            }

            if (!$password_valida) {
                $messaggio = "La password attuale non è corretta.";
                $tipo_alert = "danger";
            } else {
                // Generazione hash della nuova password
                $nuova_password_hash = password_hash($nuova_password, PASSWORD_DEFAULT);

                $datiAggiornamento = [
                    'password_hash' => $nuova_password_hash
                ];

                $headersPatch = [
                    'apikey: ' . SUPABASE_KEY,
                    'Authorization: Bearer ' . SUPABASE_KEY,
                    'Content-Type: application/json',
                    'Prefer: return=representation'
                ];

                $ch = curl_init(SUPABASE_URL . '/rest/v1/staging_utenti?id=eq.' . urlencode($utente_id));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($datiAggiornamento));
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headersPatch);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                $resp = curl_exec($ch);
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($code >= 200 && $code < 300) {
                    $messaggio = "Password modificata con successo!";
                    $tipo_alert = "success";
                } else {
                    $messaggio = "Errore durante l'aggiornamento della password (HTTP $code).";
                    $tipo_alert = "danger";
                }
            }
        } else {
            $messaggio = "Utente non trovato nel sistema.";
            $tipo_alert = "danger";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Modifica Password - Gestione Turni Ospedalieri</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body { background-color: #f4f7f6; padding-bottom: 70px; }
        .card { border: none; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
    </style>
</head>
<body class="bg-light">

    <nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">Gestione Turni Ospedalieri</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item"><a class="nav-link" href="dashboard.php">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link active" href="cambia_password.php">Modifica Password</a></li>
                    <li class="nav-item"><a class="nav-link text-danger" href="logout.php">Logout</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container" style="max-width: 600px;">
        <h2 class="mb-1 fw-bold">Modifica Password</h2>
        <h4 class="text-muted mb-4 fs-6">Aggiorna le credenziali di accesso al tuo account</h4>

        <?php if (!empty($messaggio)) { ?>
            <div class="alert alert-<?php echo $tipo_alert; ?> py-2 small" role="alert">
                <?php echo htmlspecialchars($messaggio); ?>
            </div>
        <?php } ?>

        <div class="card shadow-sm bg-white">
            <div class="card-header bg-primary text-white fw-bold">
                <h5 class="mb-0 fs-6 fw-bold"><i class="bi bi-key"></i> Inserisci i dati di sicurezza</h5>
            </div>
            <div class="card-body p-4">
                <form method="POST" action="cambia_password.php">
                    <div class="mb-3">
                        <label for="password_attuale" class="form-label small fw-bold">Password Attuale</label>
                        <input type="password" class="form-control" id="password_attuale" name="password_attuale" required>
                    </div>
                    <div class="mb-3">
                        <label for="nuova_password" class="form-label small fw-bold">Nuova Password</label>
                        <input type="password" class="form-control" id="nuova_password" name="nuova_password" required placeholder="Minimo 6 caratteri">
                    </div>
                    <div class="mb-4">
                        <label for="conferma_password" class="form-label small fw-bold">Conferma Nuova Password</label>
                        <input type="password" class="form-control" id="conferma_password" name="conferma_password" required>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <a href="dashboard.php" class="btn btn-outline-secondary btn-sm">Annulla</a>
                        <button type="submit" class="btn btn-primary fw-bold px-4">Aggiorna Password</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>