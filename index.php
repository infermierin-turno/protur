<?php
session_start();
require_once 'config.php';

$errore = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (!empty($email) && !empty($password)) {
        $utenti = [];

        // 1. Prima cerchiamo nella tabella staging_utenti
        $endpoint = "staging_utenti?email=eq." . urlencode($email) . "&select=*";
        $risposta = supabase_request($endpoint);

        if (is_array($risposta)) {
            $utenti = $risposta;
        } elseif (is_string($risposta)) {
            $decodificato = json_decode($risposta, true);
            if (is_array($decodificato)) {
                $utenti = $decodificato;
            }
        }

        // 2. Se non trovato in staging_utenti, proviamo nelle vecchie tabelle come fallback
        if (empty($utenti)) {
            $endpoint_utenti = "utenti?email=eq." . urlencode($email) . "&select=*";
            $risposta_utenti = supabase_request($endpoint_utenti);
            if (is_array($risposta_utenti)) {
                $utenti = $risposta_utenti;
            } elseif (is_string($risposta_utenti)) {
                $decodificato_utenti = json_decode($risposta_utenti, true);
                if (is_array($decodificato_utenti)) {
                    $utenti = $decodificato_utenti;
                }
            }
        }

        if (empty($utenti)) {
            $endpoint_dash = "dashboard_data?email=eq." . urlencode($email) . "&select=*";
            $risposta_dash = supabase_request($endpoint_dash);
            if (is_array($risposta_dash)) {
                $utenti = $risposta_dash;
            } elseif (is_string($risposta_dash)) {
                $decodificato_dash = json_decode($risposta_dash, true);
                if (is_array($decodificato_dash)) {
                    $utenti = $decodificato_dash;
                }
            }
        }

        if (!empty($utenti) && is_array($utenti)) {
            $utente = $utenti[0] ?? null;
            
            $passwordDb = $utente['password'] ?? $utente['PASSWORD'] ?? $utente['password_hash'] ?? '';
            
            if ($utente && ($password === $passwordDb || (!empty($passwordDb) && password_verify($password, $passwordDb)))) {
                $_SESSION['utente'] = $utente;
                
                // Normalizzazione rigorosa del ruolo e dei permessi amministrativi
                $ruoloDb = trim($utente['ruolo'] ?? $utente['RUOLO'] ?? $utente['role'] ?? 'collaboratore');
                $_SESSION['ruolo'] = $ruoloDb;
                
                // Controllo flessibile per super admin / admin / capo personale
                $isSuperAdmin = false;
                if (!empty($utente['is_super_admin']) || 
                    strtolower($ruoloDb) === 'super admin' || 
                    strtolower($ruoloDb) === 'admin' || 
                    strtolower($ruoloDb) === 'capo personale') {
                    $isSuperAdmin = true;
                }
                $_SESSION['is_super_admin'] = $isSuperAdmin;

                header("Location: dashboard.php");
                exit;
            } else {
                $errore = "Credenziali non valide o password errata.";
            }
        } else {
            $errore = "Utente non trovato con questa email.";
        }
    } else {
        $errore = "Compila tutti i campi.";
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Login - Turni App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body { background-color: #f4f7f6; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
        .login-card { border: none; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); width: 100%; max-width: 400px; padding: 20px; background: white; }
    </style>
</head>
<body>

<div class="login-card">
    <div class="text-center mb-4">
        <h3 class="fw-bold text-primary">Turni App</h3>
        <p class="text-muted small">Accedi al sistema gestionale</p>
    </div>

    <?php if (!empty($errore)) : ?>
        <div class="alert alert-danger py-2 small" role="alert">
            <?php echo htmlspecialchars($errore); ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="index.php">
        <div class="mb-3">
            <label for="email" class="form-label small fw-bold">Email</label>
            <div class="input-group">
                <span class="input-group-text bg-light"><i class="bi bi-envelope"></i></span>
                <input type="email" class="form-control" id="email" name="email" required autofocus>
            </div>
        </div>

        <div class="mb-4">
            <label for="password" class="form-label small fw-bold">Password</label>
            <div class="input-group">
                <span class="input-group-text bg-light"><i class="bi bi-lock"></i></span>
                <input type="password" class="form-control" id="password" name="password" required>
            </div>
        </div>

        <div class="d-grid">
            <button type="submit" class="btn btn-primary py-2 fw-bold">Accedi</button>
        </div>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>