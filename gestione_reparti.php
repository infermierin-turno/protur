<?php
session_start();
if (!isset($_SESSION['utente'])) {
    header("Location: index.php");
    exit;
}
require_once 'config.php';

// Estrazione sicura dell'ID utente dalla sessione
$utente_id = $_SESSION['utente_id'] ?? $_SESSION['utente']['id'] ?? $_SESSION['utente']['ID'] ?? null;

// Normalizzazione del ruolo
$ruolo_raw = trim($_SESSION['ruolo'] ?? $_SESSION['utente']['ruolo'] ?? $_SESSION['utente']['RUOLO'] ?? '');
$ruolo_lower = strtolower(str_replace([' ', '-'], '_', $ruolo_raw));

$is_super_admin = $_SESSION['is_super_admin'] ?? false;
if (!$is_super_admin && ($ruolo_lower === 'super_admin' || $ruolo_lower === 'admin' || $ruolo_lower === 'superadmin')) {
    $is_super_admin = true;
}

$is_capo_personale = ($ruolo_lower === 'capo_personale' || $ruolo_lower === 'capopersonale');

// Controllo accesso (consentito a Super Admin e Capo Personale)
if (empty($utente_id) || (!$is_super_admin && !$is_capo_personale)) {
    header("Location: index.php");
    exit;
}

$messaggio = '';
$tipo_alert = '';
$organizzazione_id = null;

if ($is_capo_personale && !$is_super_admin) {
    $user_query_res = @supabase_request('staging_utenti', '?id=eq.' . $utente_id . '&select=organizzazione_id');
    if (is_array($user_query_res)) {
        $userData = $user_query_res['data'] ?? $user_query_res[0] ?? $user_query_res;
        if (is_array($userData)) {
            $organizzazione_id = $userData['organizzazione_id'] ?? null;
        }
    }
} else {
    $organizzazione_id = $_POST['organizzazione_id'] ?? $_GET['org_id'] ?? null;
}

// Gestione invio modulo per creazione Reparto
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome_reparto = trim($_POST['nome_reparto'] ?? '');
    $post_org_id = trim($_POST['organizzazione_id'] ?? $organizzazione_id);

    if (!empty($nome_reparto) && !empty($post_org_id)) {
        $nuovo_reparto = [
            'organizzazione_id' => $post_org_id,
            'nome_reparto' => $nome_reparto
        ];

        $response = @supabase_request('reparti', '', 'POST', $nuovo_reparto);

        if (is_array($response)) {
            $messaggio = "Reparto aggiunto con successo!";
            $tipo_alert = "success";
            $organizzazione_id = $post_org_id; 
        } else {
            $messaggio = "Errore durante la creazione del reparto.";
            $tipo_alert = "danger";
        }
    } else {
        $messaggio = "Il nome del reparto e l'organizzazione sono obbligatori.";
        $tipo_alert = "warning";
    }
}

// Recupero organizzazioni per Super Admin con gestione robusta di array/oggetti
$organizzazioni = [];
if ($is_super_admin) {
    $org_response = @supabase_request('organizzazioni', '?select=id,nome_struttura');
    if (is_array($org_response)) {
        if (isset($org_response['data']) && is_array($org_response['data'])) {
            $organizzazioni = $org_response['data'];
        } elseif (isset($org_response[0])) {
            $organizzazioni = $org_response;
        } else {
            $organizzazioni = [$org_response];
        }
    }
}

// Recupera i reparti dell'organizzazione corrente
$reparti = [];
if (!empty($organizzazione_id)) {
    $reparti_response = @supabase_request('reparti', '?organizzazione_id=eq.' . $organizzazione_id . '&select=id,nome_reparto');
    if (is_array($reparti_response)) {
        if (isset($reparti_response['data']) && is_array($reparti_response['data'])) {
            $reparti = $reparti_response['data'];
        } elseif (isset($reparti_response[0])) {
            $reparti = $reparti_response;
        } else {
            $reparti = [$reparti_response];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Gestione Reparti</title>
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
            <h5 class="mb-0"><?php echo $is_super_admin ? 'Admin Panel' : 'Capo Personale Panel'; ?></h5>
            <small>Gestione Reparti Struttura</small>
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

    <?php if ($is_super_admin): ?>
        <!-- Filtro organizzazione per il Super Admin -->
        <div class="card p-3 mb-4">
            <form method="GET" action="gestione_reparti.php" class="row g-2 align-items-center">
                <div class="col-12">
                    <label for="org_id" class="form-label fw-bold small">Seleziona Organizzazione da gestire:</label>
                </div>
                <div class="col-8">
                    <select class="form-select form-select-sm" id="org_id" name="org_id" required>
                        <option value="">-- Scegli struttura --</option>
                        <?php foreach ($organizzazioni as $org): ?>
                            <?php if (!empty($org['id'])): ?>
                                <option value="<?php echo htmlspecialchars($org['id']); ?>" <?php echo ($organizzazione_id == $org['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($org['nome_struttura'] ?? 'Struttura senza nome'); ?>
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-4">
                    <button type="submit" class="btn btn-sm btn-outline-primary w-100">Carica</button>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <?php if (!empty($organizzazione_id)): ?>
        <!-- Modulo Aggiunta Reparto -->
        <div class="card p-4 mb-4">
            <h5 class="fw-bold mb-3"><i class="bi bi-hospital-fill me-2 text-primary"></i>Aggiungi Nuovo Reparto</h5>
            <form method="POST" action="gestione_reparti.php<?php echo $is_super_admin ? '?org_id=' . $organizzazione_id : ''; ?>">
                <input type="hidden" name="organizzazione_id" value="<?php echo htmlspecialchars($organizzazione_id); ?>">
                <div class="mb-3">
                    <label for="nome_reparto" class="form-label">Nome del Reparto (es. Cardiologia, Pronto Soccorso)</label>
                    <input type="text" class="form-control" id="nome_reparto" name="nome_reparto" required placeholder="Inserisci nome reparto">
                </div>
                <button type="submit" class="btn btn-primary w-100 py-2 fw-bold">Salva Reparto</button>
            </form>
        </div>

        <!-- Elenco Reparti Esistenti -->
        <div class="card p-4">
            <h5 class="fw-bold mb-3"><i class="bi bi-list-check me-2 text-secondary"></i>Reparti Registrati</h5>
            <?php if (empty($reparti)): ?>
                <p class="text-muted mb-0">Nessun reparto registrato per questa organizzazione.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Nome Reparto</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reparti as $rep): ?>
                                <?php if (!empty($rep['nome_reparto'])): ?>
                                    <tr>
                                        <td class="fw-bold"><?php echo htmlspecialchars($rep['nome_reparto']); ?></td>
                                    </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="alert alert-info text-center" role="alert">
            Seleziona un'organizzazione dal menu in alto per visualizzare e gestire i reparti.
        </div>
    <?php endif; ?>
</div>

<div class="nav-bottom">
    <a href="dashboard.php" class="text-secondary text-decoration-none"><i class="bi bi-house-door fs-4"></i></a>
    <a href="gestione_reparti.php" class="text-primary text-decoration-none"><i class="bi bi-hospital fs-4"></i></a>
    <a href="logout.php" class="text-danger text-decoration-none"><i class="bi bi-box-arrow-right fs-4"></i></a>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>