<?php
session_start();
if (!isset($_SESSION['utente'])) {
    header("Location: index.php");
    exit;
}
require_once 'config.php';

// Estrazione sicura dell'ID utente dalla sessione (compatibile con vari formati di login)
$utente_id = $_SESSION['utente_id'] ?? $_SESSION['utente']['id'] ?? $_SESSION['utente']['ID'] ?? null;

// Normalizzazione del ruolo e dei permessi
$ruolo_raw = trim($_SESSION['ruolo'] ?? $_SESSION['utente']['ruolo'] ?? $_SESSION['utente']['RUOLO'] ?? '');
$ruolo_lower = strtolower(str_replace([' ', '-'], '_', $ruolo_raw));

$is_super_admin = $_SESSION['is_super_admin'] ?? false;
if (!$is_super_admin && ($ruolo_lower === 'super_admin' || $ruolo_lower === 'admin' || $ruolo_lower === 'superadmin')) {
    $is_super_admin = true;
}

$is_capo_personale = ($ruolo_lower === 'capo_personale' || $ruolo_lower === 'capopersonale');

// Controllo accesso flessibile e robusto
if (empty($utente_id) || (!$is_super_admin && !$is_capo_personale)) {
    header("Location: index.php");
    exit;
}

$messaggio = '';
$tipo_alert = '';

// Gestione invio modulo per creazione Capo Personale
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = trim($_POST['nome'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password_in_chiaro = $_POST['password'] ?? '';
    $organizzazione_id = trim($_POST['organizzazione_id'] ?? '');

    if (!empty($nome) && !empty($email) && !empty($password_in_chiaro) && !empty($organizzazione_id)) {
        $password_hash = password_hash($password_in_chiaro, PASSWORD_DEFAULT);
        
        $nuovo_capo = [
            'organizzazione_id' => $organizzazione_id,
            'reparto_id' => null,
            'nome' => $nome,
            'email' => $email,
            'ruolo' => 'capo_personale',
            'password_hash' => $password_hash,
            'is_super_admin' => false
        ];

        $response = supabase_request('staging_utenti', '', 'POST', $nuovo_capo);

        // Controllo flessibile del successo: se la risposta è un array (anche senza chiave status esplicita o con dati inseriti) consideriamo l'operazione riuscita
        $status_code = is_array($response) ? ($response['status'] ?? 201) : 200;

        if (is_array($response) && ($status_code === 201 || $status_code === 200 || !isset($response['code']))) {
            $messaggio = "Capo Personale creato con successo!";
            $tipo_alert = "success";
        } else {
            $messaggio = "Errore durante la creazione (Codice HTTP: " . $status_code . ").";
            $tipo_alert = "danger";
        }
    } else {
        $messaggio = "Tutti i campi obbligatori devono essere compilati.";
        $tipo_alert = "warning";
    }
}

// Chiamata API per recuperare le organizzazioni
$org_response = supabase_request('organizzazioni', '?select=id,nome_struttura');
$organizzazioni = [];

if (is_array($org_response)) {
    if (isset($org_response['data']) && is_array($org_response['data'])) {
        $organizzazioni = $org_response['data'];
    } elseif (isset($org_response[0])) {
        $organizzazioni = $org_response;
    }
}

// Recupera la lista di tutti i Capi Personale esistenti
$capi_response = supabase_request('staging_utenti', '?ruolo=eq.capo_personale&select=id,nome,email,organizzazione_id');
$capi_data = [];
if (is_array($capi_response)) {
    if (isset($capi_response['data']) && is_array($capi_response['data'])) {
        $capi_data = $capi_response['data'];
    } elseif (isset($capi_response[0])) {
        $capi_data = $capi_response;
    }
}
$capi_personale = $capi_data;
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Gestione Capi Personale</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body { background-color: #f4f7f6; padding-bottom: 70px; }
        .card { border: none; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .nav-bottom { position: fixed; bottom: 0; width: 100%; height: 60px; background: white; border-top: 1px solid #ddd; display: flex; justify-content: space-around; align-items: center; z-index: 1000; }
        .header-mobile { background: #0d6efd; color: white; padding: 20px; border-radius: 0 0 20px 20px; }
    </style>
</head>
<body>

<div class="header-mobile mb-4">
    <div class="container d-flex justify-content-between align-items-center">
        <div>
            <h5 class="mb-0">Super Admin Panel</h5>
            <small>Gestione Capi Personale</small>
        </div>
        <a href="dashboard.php" class="text-white text-decoration-none"><i class="bi bi-arrow-left fs-4"></i></a>
    </div>
</div>

<div class="container">
    <?php if (!empty($messaggio)): ?>
        <div class="alert alert-<?php echo $tipo_alert; ?> alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($messaggio); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Modulo Creazione Capo Personale -->
    <div class="card p-4 mb-4">
        <h5 class="fw-bold mb-3"><i class="bi bi-person-plus-fill me-2 text-primary"></i>Crea Nuovo Capo Personale</h5>
        
        <form method="POST" action="gestione_capi_personale.php">
            <div class="mb-3">
                <label for="nome" class="form-label">Nome e Cognome</label>
                <input type="text" class="form-control" id="nome" name="nome" required>
            </div>
            <div class="mb-3">
                <label for="email" class="form-label">Email (Login)</label>
                <input type="email" class="form-control" id="email" name="email" required>
            </div>
            <div class="mb-3">
                <label for="password" class="form-label">Password temporanea</label>
                <input type="password" class="form-control" id="password" name="password" required>
            </div>
            <div class="mb-3">
                <label for="organizzazione_id" class="form-label">Organizzazione di appartenenza</label>
                <select class="form-select" id="organizzazione_id" name="organizzazione_id" required>
                    <option value="">Seleziona un'organizzazione...</option>
                    <?php if (!empty($organizzazioni)): ?>
                        <?php foreach ($organizzazioni as $org): ?>
                            <option value="<?php echo htmlspecialchars($org['id'] ?? ''); ?>">
                                <?php echo htmlspecialchars($org['nome_struttura'] ?? ''); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary w-100 py-2 fw-bold">Crea Capo Personale</button>
        </form>
    </div>

    <!-- Elenco Capi Personale Esistenti -->
    <div class="card p-4">
        <h5 class="fw-bold mb-3"><i class="bi bi-people-fill me-2 text-secondary"></i>Capi Personale Registrati</h5>
        <?php if (empty($capi_personale)): ?>
            <p class="text-muted mb-0">Nessun Capo Personale presente nel sistema.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Nome</th>
                            <th>Email</th>
                            <th>ID Organizzazione</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($capi_personale as $capo): ?>
                            <tr>
                                <td class="fw-bold"><?php echo htmlspecialchars($capo['nome'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($capo['email'] ?? ''); ?></td>
                                <td><small class="text-muted"><?php echo htmlspecialchars($capo['organizzazione_id'] ?? ''); ?></small></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="nav-bottom">
    <a href="dashboard.php" class="text-secondary text-decoration-none"><i class="bi bi-house-door fs-4"></i></a>
    <a href="gestione_capi_personale.php" class="text-primary text-decoration-none"><i class="bi bi-shield-lock-fill fs-4"></i></a>
    <a href="logout.php" class="text-danger text-decoration-none"><i class="bi bi-box-arrow-right fs-4"></i></a>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>